<?php

namespace App\Services\ProgramAlerts;

use App\Exceptions\ProgramAlertRetryException as Refused;
use App\Models\Program;
use App\Notifications\Enums\AlertStatus;
use App\Notifications\Enums\AlertType;
use App\Notifications\Enums\Channel;
use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Enums\FailureCode;
use App\Notifications\Jobs\DeliverAlertJob;
use App\Notifications\Models\Alert;
use App\Notifications\Models\AlertRecipient;
use App\Services\ProgramAlerts\Data\RecipientDiagnosis;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Manual resend of a failed / suppressed attempt (RF-15, DEC-11). The unique
 * (alert, profile, channel) guard is kept: the SAME row goes back to `pending` with a rotated
 * idempotency key and the send job is queued again. Every check runs here, under a row lock and
 * against live data; the `retry` hint of the tab is only indicative.
 */
final class ProgramAlertRetryService
{
    public const COOLDOWN_MINUTES = 10;

    /** Suppressed attempts that a later retry can fix (a gate that is no longer closed). */
    private const RETRYABLE_SUPPRESSED = [FailureCode::RateLimited, FailureCode::QuietHours];

    public function __construct(
        private readonly RecipientDiagnostics $diagnostics,
        private readonly ProgramAlertReconciler $reconciler,
    ) {}

    /** Whether the attempt itself (ignoring live context) is of a kind that can be resent. */
    public static function isEligibleAttempt(AlertRecipient $attempt): bool
    {
        $code = $attempt->failure_code;

        return match ($attempt->status) {
            DeliveryStatus::Failed => $code === null || $code->isRetryable(),
            DeliveryStatus::Suppressed => $code !== null && in_array($code, self::RETRYABLE_SUPPRESSED, true),
            default => false,
        };
    }

    /** @throws Refused */
    public function retry(Program $program, string $recipientGuid, int $vetId, ?int $userId, ?CarbonImmutable $now = null): AlertRecipient
    {
        $now ??= CarbonImmutable::instance(now());

        return DB::transaction(function () use ($program, $recipientGuid, $vetId, $userId, $now) {
            $recipient = $this->findLocked($program, $recipientGuid, $vetId);
            $alert = $recipient->alert;

            // Fresh read: the program may have been cancelled after it was loaded.
            $freshProgram = Program::find($program->id);
            if ($freshProgram === null || $freshProgram->cancelled_at !== null) {
                throw new Refused(Refused::PROGRAM_CANCELLED);
            }

            if ($alert->status === AlertStatus::Cancelled->value) {
                throw new Refused(Refused::ALERT_CANCELLED);
            }

            // A scheduled (not yet dispatched) alert has nothing to resend.
            if ($alert->status === AlertStatus::Pending->value || ! self::isEligibleAttempt($recipient)) {
                throw new Refused(Refused::NOT_RETRYABLE);
            }

            // Task reminders must also still apply to this person (responsible, group, date).
            if ($alert->type === AlertType::ProgramTaskDue && ! $this->reconciler->isStillValid($alert, $recipient->user_profile_id)) {
                throw new Refused(Refused::NOT_RETRYABLE);
            }

            $recipient->loadMissing('userProfile.contacts');
            $diagnosis = $this->diagnostics->diagnose(collect([$recipient->userProfile]))[$recipient->userProfile->guid] ?? null;

            if ($recipient->channel === Channel::Whatsapp && $diagnosis?->optedOut === true) {
                throw new Refused(Refused::OPTED_OUT);
            }

            if ($this->siblingReached($recipient)) {
                throw new Refused(Refused::ALREADY_DELIVERED);
            }

            if ($recipient->last_retry_at !== null
                && $recipient->last_retry_at->greaterThan($now->subMinutes(self::COOLDOWN_MINUTES))) {
                throw new Refused(Refused::RECENTLY_RETRIED);
            }

            if ($this->contactStillMissing($recipient, $diagnosis)) {
                throw new Refused(Refused::CONTACT_STILL_MISSING);
            }

            $previousCode = $recipient->failure_code?->value;
            $previousStatus = $recipient->status->value;

            $recipient->forceFill([
                'idempotency_key' => Str::uuid()->toString(),
                'status' => DeliveryStatus::Pending,
                'failure_reason' => null,
                'failure_code' => null,
                'provider_message_id' => null,
                'retry_count' => (int) $recipient->retry_count + 1,
                'last_retry_at' => $now,
                'last_retry_by_user_id' => $userId,
            ])->save();

            DeliverAlertJob::dispatch($recipient->id)->afterCommit();

            Log::info('program.alert.retry', [
                'program_id' => $program->id,
                'alert_id' => $alert->id,
                'recipient_id' => $recipient->id,
                'channel' => $recipient->channel->value,
                'previous_status' => $previousStatus,
                'previous_failure_code' => $previousCode,
                'user_id' => $userId,
                'retry_count' => $recipient->retry_count,
            ]);

            return $recipient;
        });
    }

    /** Tenant-scoped lookup of a non-push attempt of one of the program's alerts, locked for update. */
    private function findLocked(Program $program, string $recipientGuid, int $vetId): AlertRecipient
    {
        $recipient = AlertRecipient::query()
            ->where('guid', $recipientGuid)
            ->where('channel', '!=', Channel::Push->value)
            ->whereHas('alert', fn ($q) => $q
                ->where('vet_id', $vetId)
                ->where('subject_type', 'program')
                ->where('subject_id', $program->id)
                ->whereIn('type', array_map(fn (AlertType $t) => $t->value, ProgramAlertLineQuery::TYPES)))
            ->lockForUpdate()
            ->first();

        if ($recipient === null) {
            throw new Refused(Refused::NOT_FOUND);
        }

        $recipient->setRelation('alert', Alert::with('subject')->findOrFail($recipient->alert_id));

        return $recipient;
    }

    /** Another attempt (any channel) of the same person on the same alert already reached them. */
    private function siblingReached(AlertRecipient $recipient): bool
    {
        return AlertRecipient::query()
            ->where('alert_id', $recipient->alert_id)
            ->where('user_profile_id', $recipient->user_profile_id)
            ->where('id', '!=', $recipient->id)
            ->whereIn('status', [DeliveryStatus::Sent->value, DeliveryStatus::Delivered->value, DeliveryStatus::Read->value])
            ->exists();
    }

    /** Contact failures only make sense to resend once the contact was actually fixed. */
    private function contactStillMissing(AlertRecipient $recipient, ?RecipientDiagnosis $diagnosis): bool
    {
        if ($diagnosis === null) {
            return false;
        }

        return match ($recipient->failure_code) {
            FailureCode::NoWhatsappContact, FailureCode::InvalidNumber => $diagnosis->whatsappStatus !== RecipientDiagnosis::OK,
            FailureCode::NoEmailContact => $diagnosis->emailMasked === null,
            default => false,
        };
    }
}
