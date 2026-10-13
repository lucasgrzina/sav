<?php

namespace Tests\Unit\Notifications;

use App\Models\Country;
use App\Models\DocumentType;
use App\Models\Role;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Vet;
use App\Notifications\Enums\AlertCancelReason;
use App\Notifications\Enums\AlertStatus;
use App\Notifications\Enums\AlertType;
use App\Notifications\Enums\Channel;
use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Enums\FailureCode;
use App\Notifications\Models\Alert;
use App\Notifications\Models\AlertRecipient;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Concerns\UniqueIsoCode;

class AlertTraceabilityMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_13_000001_add_traceability_columns_to_alerts_and_alert_recipients.php';

    private function migration(): object
    {
        return require database_path('migrations/'.self::MIGRATION);
    }

    private function createProfileId(): int
    {
        $country = Country::create([
            'guid' => Str::uuid()->toString(), 'name' => 'Argentina',
            'iso_code' => UniqueIsoCode::next(), 'phone_prefix' => '+54',
        ]);
        $documentType = DocumentType::create([
            'guid' => Str::uuid()->toString(), 'country_id' => $country->id,
            'name' => 'CUIT', 'validation_regex' => '.*',
        ]);
        $vet = Vet::create([
            'guid' => Str::uuid()->toString(), 'name' => 'Vet Test',
            'slug' => 'vet-test-'.Str::random(6), 'country_id' => $country->id,
            'document_type_id' => $documentType->id, 'tax_id' => '20-12345678-9',
        ]);
        $role = Role::firstOrCreate(
            ['name' => 'vet_vet', 'guard_name' => 'web'],
            ['type' => Role::TYPE_TENANT, 'guid' => Str::uuid()->toString()],
        );

        return UserProfile::create([
            'user_id' => User::factory()->create()->id,
            'authenticatable_type' => 'vet',
            'authenticatable_id' => $vet->id,
            'role_id' => $role->id,
        ])->id;
    }

    private function createAlert(array $overrides = []): Alert
    {
        return Alert::create($overrides + [
            'type' => AlertType::ProgramTaskDue,
            'payload' => [],
            'scheduled_at' => now(),
            'status' => AlertStatus::Pending->value,
        ]);
    }

    public function test_migration_adds_all_traceability_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('alerts', ['cancelled_at', 'cancel_reason', 'logical_key']));
        $this->assertTrue(Schema::hasColumns('alert_recipients', [
            'failure_code', 'destination_hint', 'rendered_body', 'template_version',
            'retry_count', 'last_retry_at', 'last_retry_by_user_id',
        ]));
    }

    public function test_new_columns_have_safe_defaults_and_casts(): void
    {
        $alert = $this->createAlert()->fresh();
        $recipient = AlertRecipient::create([
            'alert_id' => $alert->id,
            'user_profile_id' => $this->createProfileId(),
            'channel' => Channel::Whatsapp,
            'status' => DeliveryStatus::Pending,
            'idempotency_key' => Str::uuid()->toString(),
        ])->fresh();

        $this->assertNull($alert->cancelled_at);
        $this->assertNull($alert->cancel_reason);
        $this->assertNull($alert->logical_key);
        $this->assertNull($recipient->failure_code);
        $this->assertSame(0, (int) $recipient->retry_count);

        $alert->update([
            'status' => AlertStatus::Cancelled->value,
            'cancelled_at' => now(),
            'cancel_reason' => AlertCancelReason::Rescheduled,
        ]);
        $recipient->update(['failure_code' => FailureCode::NoLongerApplicable]);

        $this->assertSame(AlertCancelReason::Rescheduled, $alert->fresh()->cancel_reason);
        $this->assertSame(FailureCode::NoLongerApplicable, $recipient->fresh()->failure_code);
    }

    public function test_logical_key_is_unique_but_allows_many_nulls(): void
    {
        $key = sha1('program|task_due|target|task-alert|2026-10-20 12:00:00');

        $this->createAlert(['logical_key' => $key]);
        $this->createAlert();
        $this->createAlert();

        $this->expectException(QueryException::class);
        $this->createAlert(['logical_key' => $key]);
    }

    public function test_active_scope_excludes_cancelled_alerts(): void
    {
        $pending = $this->createAlert();
        $dispatched = $this->createAlert(['status' => AlertStatus::Dispatched->value]);
        $this->createAlert(['status' => AlertStatus::Cancelled->value]);

        $this->assertEqualsCanonicalizing(
            [$pending->id, $dispatched->id],
            Alert::active()->pluck('id')->all(),
        );
    }

    public function test_failure_code_backfill_maps_only_deterministic_patterns(): void
    {
        $migration = $this->migration();
        $migration->down();

        $profileId = $this->createProfileId();
        $rows = [];

        foreach ([
            'optedOut' => ['whatsapp', 'suppressed', 'opted_out'],
            'noLonger' => ['push', 'suppressed', 'no_longer_applicable'],
            'whatsapp' => ['whatsapp', 'failed', 'El perfil abc-123 no tiene un contacto whatsapp habilitado para alertas'],
            'email' => ['email', 'failed', 'El perfil abc-123 no tiene un contacto email habilitado para alertas'],
            'rawProvider' => ['whatsapp', 'failed', 'Twilio error 63016'],
            'noReason' => ['whatsapp', 'failed', null],
        ] as $name => [$channel, $status, $reason]) {
            $alert = $this->createAlert();
            $rows[$name] = DB::table('alert_recipients')->insertGetId([
                'guid' => Str::uuid()->toString(),
                'alert_id' => $alert->id,
                'user_profile_id' => $profileId,
                'channel' => $channel,
                'status' => $status,
                'failure_reason' => $reason,
                'idempotency_key' => Str::uuid()->toString(),
            ]);
        }

        $migration->up();
        $codes = DB::table('alert_recipients')->pluck('failure_code', 'id');

        $this->assertSame('opted_out', $codes[$rows['optedOut']]);
        $this->assertSame('no_longer_applicable', $codes[$rows['noLonger']]);
        $this->assertSame('no_whatsapp_contact', $codes[$rows['whatsapp']]);
        $this->assertSame('no_email_contact', $codes[$rows['email']]);
        $this->assertNull($codes[$rows['rawProvider']]);
        $this->assertNull($codes[$rows['noReason']]);
    }

    public function test_migration_down_removes_every_added_column(): void
    {
        $this->migration()->down();

        $this->assertFalse(Schema::hasColumn('alerts', 'logical_key'));
        $this->assertFalse(Schema::hasColumn('alerts', 'cancelled_at'));
        $this->assertFalse(Schema::hasColumn('alerts', 'cancel_reason'));
        $this->assertFalse(Schema::hasColumn('alert_recipients', 'failure_code'));
        $this->assertFalse(Schema::hasColumn('alert_recipients', 'retry_count'));
    }
}
