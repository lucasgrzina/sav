<?php

namespace App\Services\ProgramAlerts\Data;

use App\Models\Program;
use App\Notifications\Enums\AlertStatus;
use App\Notifications\Enums\AlertType;
use App\Notifications\Models\Alert;

/**
 * Which projected alerts already exist for a program (any non-cancelled state), matched the
 * same way the reconciler matches them: by logical key, or for legacy key-less alerts by
 * (protocol task alert guid, instant). Lets warnings tell "past and never created" (ALERT_IN_PAST)
 * from "past but still pending / already sent".
 */
final class ExistingAlertIndex
{
    /** @param array<string, string> $byKey logical key => status */
    public function __construct(
        private array $byKey = [],
        private array $legacy = [],
    ) {}

    public static function empty(): self
    {
        return new self();
    }

    public static function forProgram(Program $program): self
    {
        $byKey = [];
        $legacy = [];

        $alerts = Alert::query()
            ->where('type', AlertType::ProgramTaskDue)
            ->where('subject_type', 'program')
            ->where('subject_id', $program->id)
            ->whereIn('status', [AlertStatus::Pending->value, AlertStatus::Dispatched->value])
            ->get(['id', 'status', 'logical_key', 'payload', 'scheduled_at']);

        foreach ($alerts as $alert) {
            if ($alert->logical_key !== null) {
                $byKey[$alert->logical_key] = $alert->status;
            } else {
                $legacy[($alert->payload['protocolTaskAlertGuid'] ?? '') . '|' . $alert->scheduled_at->getTimestamp()] = $alert->status;
            }
        }

        return new self($byKey, $legacy);
    }

    /** `pending`, `dispatched` or null when no alert exists for the item. */
    public function statusFor(ProjectedAlert $item): ?string
    {
        if ($item->logicalKey !== null && isset($this->byKey[$item->logicalKey])) {
            return $this->byKey[$item->logicalKey];
        }

        return $this->legacy[$item->protocolTaskAlertGuid . '|' . $item->scheduledAtUtc->getTimestamp()] ?? null;
    }

    public function has(ProjectedAlert $item): bool
    {
        return $this->statusFor($item) !== null;
    }
}
