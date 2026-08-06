<?php

namespace Tests\Unit\Notifications;

use App\Enums\ContactType;
use App\Models\Contact;
use App\Models\Country;
use App\Models\DocumentType;
use App\Models\Role;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Vet;
use App\Notifications\Enums\AlertType;
use App\Notifications\Enums\Channel;
use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Models\Alert;
use App\Notifications\Models\AlertRecipient;
use App\Notifications\Models\OptOut;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AlertModelsTest extends TestCase
{
    use RefreshDatabase;

    private function createManagerProfile(): UserProfile
    {
        $country = Country::create([
            'guid' => Str::uuid()->toString(),
            'name' => 'Argentina',
            'iso_code' => 'A' . Str::random(4),
            'phone_prefix' => '+54',
        ]);

        $documentType = DocumentType::create([
            'guid' => Str::uuid()->toString(),
            'country_id' => $country->id,
            'name' => 'CUIT',
            'validation_regex' => '.*',
        ]);

        $vet = Vet::create([
            'guid' => Str::uuid()->toString(),
            'name' => 'Vet Test',
            'slug' => 'vet-test-' . Str::random(6),
            'country_id' => $country->id,
            'document_type_id' => $documentType->id,
            'tax_id' => '20-12345678-9',
        ]);

        $role = Role::create(['name' => 'vet_vet', 'guard_name' => 'web', 'type' => Role::TYPE_TENANT]);

        $user = User::factory()->create();

        $profile = UserProfile::create([
            'user_id' => $user->id,
            'authenticatable_type' => 'vet',
            'authenticatable_id' => $vet->id,
            'role_id' => $role->id,
        ]);

        Contact::create([
            'contactable_type' => 'user_profile',
            'contactable_id' => $profile->id,
            'type' => ContactType::Whatsapp,
            'value' => '5491122334455',
            'is_primary' => true,
            'use_for_alerts' => true,
        ]);

        return $profile;
    }

    public function test_alert_casts_type_payload_and_scheduled_at(): void
    {
        $alert = Alert::create([
            'type' => AlertType::ProgramTaskDue,
            'payload' => ['protocolTaskAlertGuid' => 'alert-guid-1', 'message' => 'Vacunar'],
            'scheduled_at' => now()->addDay(),
            'status' => 'pending',
        ]);

        $this->assertNotEmpty($alert->guid);
        $this->assertSame(AlertType::ProgramTaskDue, $alert->type);
        $this->assertIsArray($alert->payload);
        $this->assertSame('Vacunar', $alert->payload['message']);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $alert->scheduled_at);
    }

    public function test_alert_recipient_belongs_to_alert_and_profile_with_unique_channel_per_alert(): void
    {
        $profile = $this->createManagerProfile();

        $alert = Alert::create([
            'type' => AlertType::ProgramCreated,
            'payload' => [],
            'scheduled_at' => now(),
            'status' => 'pending',
        ]);

        $recipient = AlertRecipient::create([
            'alert_id' => $alert->id,
            'user_profile_id' => $profile->id,
            'channel' => Channel::Whatsapp,
            'status' => DeliveryStatus::Pending,
            'idempotency_key' => Str::uuid()->toString(),
        ]);

        $this->assertTrue($alert->is($recipient->alert));
        $this->assertTrue($profile->is($recipient->userProfile));
        $this->assertSame(Channel::Whatsapp, $recipient->channel);
        $this->assertSame(DeliveryStatus::Pending, $recipient->status);
        $this->assertCount(1, $alert->fresh()->recipients);
    }

    public function test_alert_recipient_to_dto_resolves_whatsapp_contact(): void
    {
        $profile = $this->createManagerProfile();

        $alert = Alert::create([
            'type' => AlertType::ProgramCreated,
            'payload' => [],
            'scheduled_at' => now(),
            'status' => 'pending',
        ]);

        $recipient = AlertRecipient::create([
            'alert_id' => $alert->id,
            'user_profile_id' => $profile->id,
            'channel' => Channel::Whatsapp,
            'status' => DeliveryStatus::Pending,
            'idempotency_key' => Str::uuid()->toString(),
        ]);

        $dto = $recipient->toDto();

        $this->assertSame($profile->user_id, $dto->userId);
        $this->assertSame('5491122334455', $dto->phone);
        $this->assertSame($profile->user->name, $dto->name);
        $this->assertSame(Channel::Whatsapp, $dto->channel);
    }

    #[DataProvider('unnormalizedPhoneProvider')]
    public function test_alert_recipient_to_dto_normalizes_the_phone_to_digits_only(string $stored): void
    {
        $profile = $this->createManagerProfile();
        $profile->contacts()->where('type', ContactType::Whatsapp)->update(['value' => $stored]);

        $alert = Alert::create([
            'type' => AlertType::ProgramCreated,
            'payload' => [],
            'scheduled_at' => now(),
            'status' => 'pending',
        ]);

        $recipient = AlertRecipient::create([
            'alert_id' => $alert->id,
            'user_profile_id' => $profile->id,
            'channel' => Channel::Whatsapp,
            'status' => DeliveryStatus::Pending,
            'idempotency_key' => Str::uuid()->toString(),
        ]);

        $this->assertSame('5491122334455', $recipient->toDto()->phone);
    }

    /** @return array<string, array{string}> */
    public static function unnormalizedPhoneProvider(): array
    {
        return [
            'already normalized' => ['5491122334455'],
            'leading plus' => ['+5491122334455'],
            'spaced' => ['54 9 11 2233 4455'],
            'plus and spaces' => ['+54 9 11 2233 4455'],
            'dashes and parens' => ['+54 (9) 11 2233-4455'],
        ];
    }

    public function test_alert_recipient_to_dto_throws_when_no_contact_for_alerts(): void
    {
        $profile = $this->createManagerProfile();
        $profile->contacts()->update(['use_for_alerts' => false]);

        $alert = Alert::create([
            'type' => AlertType::ProgramCreated,
            'payload' => [],
            'scheduled_at' => now(),
            'status' => 'pending',
        ]);

        $recipient = AlertRecipient::create([
            'alert_id' => $alert->id,
            'user_profile_id' => $profile->id,
            'channel' => Channel::Whatsapp,
            'status' => DeliveryStatus::Pending,
            'idempotency_key' => Str::uuid()->toString(),
        ]);

        $this->expectException(\App\Notifications\Exceptions\RecipientContactNotFoundException::class);

        $recipient->toDto();
    }

    public function test_alert_recipient_unique_constraint_on_alert_profile_channel(): void
    {
        $profile = $this->createManagerProfile();
        $alert = Alert::create([
            'type' => AlertType::ProgramCreated,
            'payload' => [],
            'scheduled_at' => now(),
            'status' => 'pending',
        ]);

        AlertRecipient::create([
            'alert_id' => $alert->id,
            'user_profile_id' => $profile->id,
            'channel' => Channel::Whatsapp,
            'status' => DeliveryStatus::Pending,
            'idempotency_key' => Str::uuid()->toString(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        AlertRecipient::create([
            'alert_id' => $alert->id,
            'user_profile_id' => $profile->id,
            'channel' => Channel::Whatsapp,
            'status' => DeliveryStatus::Pending,
            'idempotency_key' => Str::uuid()->toString(),
        ]);
    }

    public function test_opt_out_casts_channel_and_enforces_unique_phone_channel(): void
    {
        OptOut::create(['phone' => '5491122334455', 'channel' => Channel::Whatsapp]);

        $optOut = OptOut::query()->first();
        $this->assertSame(Channel::Whatsapp, $optOut->channel);

        $this->expectException(\Illuminate\Database\QueryException::class);
        OptOut::create(['phone' => '5491122334455', 'channel' => Channel::Whatsapp]);
    }

    public function test_alert_recipient_to_dto_resolves_email_contact_with_no_phone(): void
    {
        $profile = $this->createManagerProfile();

        Contact::create([
            'contactable_type' => 'user_profile',
            'contactable_id' => $profile->id,
            'type' => ContactType::Email,
            'value' => 'juan@example.com',
            'is_primary' => true,
            'use_for_alerts' => true,
        ]);

        $alert = Alert::create([
            'type' => AlertType::ProgramCreated,
            'payload' => [],
            'scheduled_at' => now(),
            'status' => 'pending',
        ]);

        $recipient = AlertRecipient::create([
            'alert_id' => $alert->id,
            'user_profile_id' => $profile->id,
            'channel' => Channel::Email,
            'status' => DeliveryStatus::Pending,
            'idempotency_key' => Str::uuid()->toString(),
        ]);

        $dto = $recipient->toDto();

        $this->assertNull($dto->phone);
        $this->assertSame('juan@example.com', $dto->email);
        $this->assertSame(Channel::Email, $dto->channel);
    }
}
