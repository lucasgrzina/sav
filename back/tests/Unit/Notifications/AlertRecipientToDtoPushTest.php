<?php

namespace Tests\Unit\Notifications;

use App\Models\Country;
use App\Models\DocumentType;
use App\Models\PushSubscription;
use App\Models\Role;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Vet;
use App\Notifications\Enums\AlertType;
use App\Notifications\Enums\Channel;
use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Exceptions\RecipientContactNotFoundException;
use App\Notifications\Models\Alert;
use App\Notifications\Models\AlertRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AlertRecipientToDtoPushTest extends TestCase
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

        return UserProfile::create([
            'user_id' => $user->id,
            'authenticatable_type' => 'vet',
            'authenticatable_id' => $vet->id,
            'role_id' => $role->id,
        ]);
    }

    private function createPushRecipient(UserProfile $profile): AlertRecipient
    {
        $alert = Alert::create([
            'type' => AlertType::ProgramCreated,
            'payload' => [],
            'scheduled_at' => now(),
            'status' => 'pending',
        ]);

        return AlertRecipient::create([
            'alert_id' => $alert->id,
            'user_profile_id' => $profile->id,
            'channel' => Channel::Push,
            'status' => DeliveryStatus::Pending,
            'idempotency_key' => Str::uuid()->toString(),
        ]);
    }

    public function test_returns_a_recipient_dto_when_the_profile_has_an_active_subscription(): void
    {
        $profile = $this->createManagerProfile();
        PushSubscription::create([
            'user_id' => $profile->user_id,
            'endpoint' => 'https://fcm.test/active',
            'endpoint_hash' => hash('sha256', 'https://fcm.test/active'),
            'p256dh' => 'key',
            'auth_key' => 'auth',
        ]);

        $recipient = $this->createPushRecipient($profile);
        $dto = $recipient->toDto();

        $this->assertSame($profile->user_id, $dto->userId);
        $this->assertNull($dto->phone);
        $this->assertSame(Channel::Push, $dto->channel);
    }

    public function test_throws_when_the_profile_has_no_active_subscription(): void
    {
        $profile = $this->createManagerProfile();
        $recipient = $this->createPushRecipient($profile);

        $this->expectException(RecipientContactNotFoundException::class);

        $recipient->toDto();
    }
}
