<?php

namespace App\Services\ProgramAlerts\Data;

/** Outcome of an applied (or aborted) reconciliation. `warnings` is filled by the warning service (later PR). */
final class ReconcileReport
{
    /**
     * @param array<string, int> $cancelledByReason
     * @param list<Warning> $warnings
     */
    public function __construct(
        public int $kept = 0,
        public int $updated = 0,
        public int $created = 0,
        public array $cancelledByReason = [],
        public int $untouchedDispatched = 0,
        public int $skippedPast = 0,
        public int $adopted = 0,
        public int $recipientsAdded = 0,
        public int $recipientsReactivated = 0,
        public int $recipientsSuppressed = 0,
        public array $warnings = [],
        public bool $aborted = false,
        public ?string $abortReason = null,
        public int $alreadySentNotModified = 0,
        public int $reschedulePairs = 0,
    ) {}

    public static function aborted(string $reason): self
    {
        return new self(aborted: true, abortReason: $reason);
    }

    public function cancelledTotal(): int
    {
        return array_sum($this->cancelledByReason);
    }

    public function hasChanges(): bool
    {
        return $this->updated > 0
            || $this->created > 0
            || $this->adopted > 0
            || $this->cancelledTotal() > 0
            || $this->recipientsAdded > 0
            || $this->recipientsReactivated > 0
            || $this->recipientsSuppressed > 0;
    }

    /**
     * RF-11 summary shown after saving. A rescheduled alert is a cancel plus its recreation for the
     * same group and protocol alert (`reschedulePairs`): reported once, not as cancelled + created.
     *
     * @return array{kept: int, rescheduled: int, created: int, cancelled: int, already_sent_not_modified: int}
     */
    public function summary(): array
    {
        return [
            'kept' => $this->kept + $this->adopted,
            'rescheduled' => $this->reschedulePairs,
            'created' => max(0, $this->created - $this->reschedulePairs),
            'cancelled' => max(0, $this->cancelledTotal() - $this->reschedulePairs),
            'already_sent_not_modified' => $this->alreadySentNotModified,
        ];
    }

    /** @return array<string, mixed> */
    public function counts(): array
    {
        return [
            'kept' => $this->kept,
            'updated' => $this->updated,
            'created' => $this->created,
            'cancelled' => $this->cancelledByReason,
            'untouched_dispatched' => $this->untouchedDispatched,
            'skipped_past' => $this->skippedPast,
            'adopted' => $this->adopted,
            'recipients' => [
                'added' => $this->recipientsAdded,
                'reactivated' => $this->recipientsReactivated,
                'suppressed' => $this->recipientsSuppressed,
            ],
            'abort_reason' => $this->abortReason,
        ];
    }
}
