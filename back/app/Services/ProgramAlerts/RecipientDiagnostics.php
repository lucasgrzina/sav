<?php

namespace App\Services\ProgramAlerts;

use App\Enums\ContactType;
use App\Models\Contact;
use App\Models\UserProfile;
use App\Notifications\Enums\Channel;
use App\Notifications\Models\OptOut;
use App\Notifications\Support\PhoneMasker;
use App\Notifications\Support\PhoneNumber;
use App\Notifications\Support\PhoneValidator;
use App\Services\ProgramAlerts\Data\RecipientDiagnosis;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Read-only "how would this person be reached today" check, using the same contact lookup,
 * phone validator and opt-out table as the real send, so what is previewed is what happens.
 * Contacts of every profile are loaded at once and opt-outs are queried a single time.
 */
final class RecipientDiagnostics
{
    /**
     * @param Collection<int, UserProfile> $profiles
     * @return array<string, RecipientDiagnosis> keyed by profile guid
     */
    public function diagnose(Collection $profiles): array
    {
        $profiles = new EloquentCollection($profiles->unique('id')->values()->all());
        $profiles->loadMissing('contacts');

        $emailFallbackEnabled = in_array(Channel::Email->value, (array) config('notifications.fallback.whatsapp', []), true);

        /** @var array<string, array{phone: ?string, email: ?string}> $contacts */
        $contacts = [];
        $phones = [];

        foreach ($profiles as $profile) {
            $alertContacts = $profile->contacts->filter(fn (Contact $c) => $c->use_for_alerts);

            // Same pick as AlertRecipient::toDto(): first alert-enabled contact of the type.
            $whatsapp = $alertContacts->first(fn (Contact $c) => $c->type === ContactType::Whatsapp);
            $email = $alertContacts->first(fn (Contact $c) => $c->type === ContactType::Email);

            $phone = $whatsapp !== null ? PhoneNumber::normalize($whatsapp->value) : null;
            $contacts[$profile->guid] = ['phone' => $phone, 'email' => $email?->value];

            if ($phone !== null && $phone !== '') {
                $phones[] = $phone;
            }
        }

        $optedOut = $phones === []
            ? []
            : array_flip(OptOut::query()
                ->whereIn('phone', array_unique($phones))
                ->where('channel', Channel::Whatsapp->value)
                ->pluck('phone')
                ->all());

        $result = [];

        foreach ($profiles as $profile) {
            ['phone' => $phone, 'email' => $email] = $contacts[$profile->guid];

            $status = match (true) {
                $phone === null => RecipientDiagnosis::MISSING,
                PhoneValidator::isPlausible($phone) => RecipientDiagnosis::OK,
                default => RecipientDiagnosis::INVALID_NUMBER,
            };

            $result[$profile->guid] = new RecipientDiagnosis(
                whatsappStatus: $status,
                masked: $phone !== null && $phone !== '' ? PhoneMasker::mask($phone) : null,
                phone: $phone,
                emailFallback: $emailFallbackEnabled && $email !== null,
                emailMasked: $email !== null ? PhoneMasker::maskEmail($email) : null,
                optedOut: $phone !== null && isset($optedOut[$phone]),
            );
        }

        return $result;
    }
}
