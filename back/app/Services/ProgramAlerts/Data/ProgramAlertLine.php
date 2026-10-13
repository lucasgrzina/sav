<?php

namespace App\Services\ProgramAlerts\Data;

use App\Models\UserProfile;
use App\Notifications\Models\Alert;
use App\Notifications\Models\AlertRecipient;
use Illuminate\Support\Collection;

/** One row of the program "Avisos" tab: one alert x one person, with every delivery attempt. */
final readonly class ProgramAlertLine
{
    /**
     * @param Collection<int, AlertRecipient> $attempts
     * @param array{allowed: bool, recipient_guid: ?string, blocked_reason: ?string}|null $retry
     */
    public function __construct(
        public Alert $alert,
        public UserProfile $profile,
        public Collection $attempts,
        public AlertLineState $state,
        public LocalDateTime $scheduledAtLocal,
        public ?string $cancelReason,
        public ?RecipientDiagnosis $contact,
        public ?array $retry,
        public ?string $renderedBody,
    ) {}
}
