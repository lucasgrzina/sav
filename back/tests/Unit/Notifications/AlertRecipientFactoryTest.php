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
use App\Notifications\Models\Alert;
use App\Notifications\Services\AlertRecipientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AlertRecipientFactoryTest extends TestCase
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

        $role = Role::firstOrCreate(
            ['name' => 'vet_vet', 'guard_name' => 'web'],
            ['type' => Role::TYPE_TENANT],
        );
        $user = User::factory()->create();

        return UserProfile::create([
            'user_id' => $user->id,
            'authenticatable_type' => 'vet',
            'authenticatable_id' => $vet->id,
            'role_id' => $role->id,
        ]);
    }

    private function createAlert(): Alert
    {
        return Alert::create([
            'type' => AlertType::ProgramCreated,
            'payload' => [],
            'scheduled_at' => now(),
            'status' => 'pending',
        ]);
    }

    public function test_creates_whatsapp_and_push_for_every_manager_with_an_active_subscription(): void
    {
        config()->set('notifications.default_channels', [Channel::Whatsapp, Channel::Push]);

        $managerA = $this->createManagerProfile();
        $managerB = $this->createManagerProfile();
        PushSubscription::create([
            'user_id' => $managerA->user_id,
            'endpoint' => 'https://fcm.test/a',
            'endpoint_hash' => hash('sha256', 'https://fcm.test/a'),
            'p256dh' => 'key',
            'auth_key' => 'auth',
        ]);
        PushSubscription::create([
            'user_id' => $managerB->user_id,
            'endpoint' => 'https://fcm.test/b',
            'endpoint_hash' => hash('sha256', 'https://fcm.test/b'),
            'p256dh' => 'key',
            'auth_key' => 'auth',
        ]);

        $alert = $this->createAlert();
        $created = (new AlertRecipientFactory())->createForManagers($alert, collect([$managerA, $managerB]));

        $this->assertCount(4, $created);
        $this->assertSame(2, $created->where('channel', Channel::Whatsapp)->count());
        $this->assertSame(2, $created->where('channel', Channel::Push)->count());
    }

    public function test_creates_only_whatsapp_when_push_is_not_in_default_channels(): void
    {
        config()->set('notifications.default_channels', [Channel::Whatsapp]);

        $managerA = $this->createManagerProfile();
        $managerB = $this->createManagerProfile();
        PushSubscription::create([
            'user_id' => $managerA->user_id,
            'endpoint' => 'https://fcm.test/a',
            'endpoint_hash' => hash('sha256', 'https://fcm.test/a'),
            'p256dh' => 'key',
            'auth_key' => 'auth',
        ]);

        $alert = $this->createAlert();
        $created = (new AlertRecipientFactory())->createForManagers($alert, collect([$managerA, $managerB]));

        $this->assertCount(2, $created);
        $this->assertTrue($created->every(fn ($recipient) => $recipient->channel === Channel::Whatsapp));
    }

    public function test_skips_the_push_channel_in_silence_for_managers_without_an_active_subscription(): void
    {
        config()->set('notifications.default_channels', [Channel::Whatsapp, Channel::Push]);

        $withSubscription = $this->createManagerProfile();
        $withoutSubscription = $this->createManagerProfile();
        PushSubscription::create([
            'user_id' => $withSubscription->user_id,
            'endpoint' => 'https://fcm.test/with-sub',
            'endpoint_hash' => hash('sha256', 'https://fcm.test/with-sub'),
            'p256dh' => 'key',
            'auth_key' => 'auth',
        ]);

        $alert = $this->createAlert();
        $created = (new AlertRecipientFactory())->createForManagers(
            $alert,
            collect([$withSubscription, $withoutSubscription]),
        );

        $this->assertCount(3, $created);
        $this->assertSame(2, $created->where('channel', Channel::Whatsapp)->count());
        $this->assertSame(1, $created->where('channel', Channel::Push)->count());
        $this->assertSame(
            $withSubscription->id,
            $created->firstWhere('channel', Channel::Push)->user_profile_id,
        );
    }

    public function test_creates_only_whatsapp_when_no_manager_has_an_active_subscription(): void
    {
        config()->set('notifications.default_channels', [Channel::Whatsapp, Channel::Push]);

        $managerA = $this->createManagerProfile();
        $managerB = $this->createManagerProfile();

        $alert = $this->createAlert();
        $created = (new AlertRecipientFactory())->createForManagers($alert, collect([$managerA, $managerB]));

        $this->assertCount(2, $created);
        $this->assertTrue($created->every(fn ($recipient) => $recipient->channel === Channel::Whatsapp));
    }
}
