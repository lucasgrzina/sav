<?php

namespace Tests\Unit\Notifications;

use App\Notifications\Enums\AlertType;
use App\Notifications\Enums\Channel;
use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Jobs\DeliverAlertJob;
use App\Notifications\Models\Alert;
use App\Notifications\Models\AlertRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class DispatchDueAlertsTest extends TestCase
{
    use RefreshDatabase;

    private function createAlert(string $scheduledAtModifier, string $status = 'pending'): Alert
    {
        return Alert::create([
            'type' => AlertType::ProgramCreated,
            'payload' => [],
            'scheduled_at' => now()->modify($scheduledAtModifier),
            'status' => $status,
        ]);
    }

    private function createRecipient(Alert $alert, DeliveryStatus $status = DeliveryStatus::Pending): AlertRecipient
    {
        return AlertRecipient::create([
            'alert_id' => $alert->id,
            'user_profile_id' => $this->fakeUserProfileId(),
            'channel' => Channel::Whatsapp,
            'status' => $status,
            'idempotency_key' => Str::uuid()->toString(),
        ]);
    }

    private function fakeUserProfileId(): int
    {
        $country = \App\Models\Country::create([
            'guid' => Str::uuid()->toString(), 'name' => 'Argentina',
            'iso_code' => 'A' . Str::random(4), 'phone_prefix' => '+54',
        ]);
        $documentType = \App\Models\DocumentType::create([
            'guid' => Str::uuid()->toString(), 'country_id' => $country->id,
            'name' => 'CUIT', 'validation_regex' => '.*',
        ]);
        $vet = \App\Models\Vet::create([
            'guid' => Str::uuid()->toString(), 'name' => 'Vet Test',
            'slug' => 'vet-test-' . Str::random(6), 'country_id' => $country->id,
            'document_type_id' => $documentType->id, 'tax_id' => '20-12345678-9',
        ]);
        $role = \App\Models\Role::firstOrCreate(
            ['name' => 'vet_vet', 'guard_name' => 'web'],
            ['type' => \App\Models\Role::TYPE_TENANT, 'guid' => Str::uuid()->toString()],
        );
        $user = \App\Models\User::factory()->create();

        $profile = \App\Models\UserProfile::create([
            'user_id' => $user->id, 'authenticatable_type' => 'vet',
            'authenticatable_id' => $vet->id, 'role_id' => $role->id,
        ]);

        return $profile->id;
    }

    public function test_dispatches_a_job_per_pending_recipient_of_a_due_alert(): void
    {
        Queue::fake();

        $alert = $this->createAlert('-1 minute');
        $recipientA = $this->createRecipient($alert);
        $recipientB = $this->createRecipient($alert);

        $this->artisan('alerts:dispatch-due')->assertSuccessful();

        Queue::assertPushed(DeliverAlertJob::class, 2);
        Queue::assertPushed(DeliverAlertJob::class, fn ($job) => $job->recipientId === $recipientA->id);
        Queue::assertPushed(DeliverAlertJob::class, fn ($job) => $job->recipientId === $recipientB->id);

        $this->assertSame('dispatched', $alert->fresh()->status);
    }

    public function test_ignores_alerts_scheduled_in_the_future(): void
    {
        Queue::fake();

        $alert = $this->createAlert('+1 hour');
        $this->createRecipient($alert);

        $this->artisan('alerts:dispatch-due')->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertSame('pending', $alert->fresh()->status);
    }

    public function test_skips_recipients_that_are_not_pending(): void
    {
        Queue::fake();

        $alert = $this->createAlert('-1 minute');
        $this->createRecipient($alert, DeliveryStatus::Sent);

        $this->artisan('alerts:dispatch-due')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_ignores_alerts_that_are_not_pending(): void
    {
        Queue::fake();

        $alert = $this->createAlert('-1 minute', 'dispatched');
        $this->createRecipient($alert);

        $this->artisan('alerts:dispatch-due')->assertSuccessful();

        Queue::assertNothingPushed();
    }
}
