<?php

namespace Tests\Concerns;

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
use Illuminate\Support\Str;

/**
 * Minimal object graph an alert needs: a vet-scoped UserProfile with a WhatsApp contact
 * enabled for alerts. The country/document-type/vet chain exists only to satisfy the
 * foreign keys.
 */
trait CreatesNotificationFixtures
{
    protected function createManagerProfile(string $phone = '5491122334455'): UserProfile
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

        // Spatie's Role::create() throws on duplicates and this helper runs more than once
        // per test: the role is shared, only the profile is per-call.
        $role = Role::where('name', 'vet_vet')->where('guard_name', 'web')->first()
            ?? Role::create(['name' => 'vet_vet', 'guard_name' => 'web', 'type' => Role::TYPE_TENANT]);

        $profile = UserProfile::create([
            'user_id' => User::factory()->create()->id,
            'authenticatable_type' => 'vet',
            'authenticatable_id' => $vet->id,
            'role_id' => $role->id,
        ]);

        Contact::create([
            'contactable_type' => 'user_profile',
            'contactable_id' => $profile->id,
            'type' => ContactType::Whatsapp,
            'value' => $phone,
            'is_primary' => true,
            'use_for_alerts' => true,
        ]);

        return $profile;
    }

    protected function createAlert(AlertType $type = AlertType::ProgramCreated): Alert
    {
        return Alert::create([
            'type' => $type,
            'payload' => [],
            'scheduled_at' => now(),
            'status' => 'pending',
        ]);
    }

    protected function createRecipient(
        UserProfile $profile,
        Alert $alert,
        Channel $channel = Channel::Whatsapp,
        DeliveryStatus $status = DeliveryStatus::Pending,
        ?string $providerMessageId = null,
    ): AlertRecipient {
        return AlertRecipient::create([
            'alert_id' => $alert->id,
            'user_profile_id' => $profile->id,
            'channel' => $channel,
            'status' => $status,
            'provider_message_id' => $providerMessageId,
            'idempotency_key' => Str::uuid()->toString(),
        ]);
    }
}
