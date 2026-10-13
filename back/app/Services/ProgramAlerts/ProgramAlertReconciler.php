<?php

namespace App\Services\ProgramAlerts;

use App\Models\Program;
use App\Models\ProgramAlertReconciliation;
use App\Models\UserProfile;
use App\Notifications\Enums\AlertCancelReason;
use App\Notifications\Enums\AlertStatus;
use App\Notifications\Enums\AlertType;
use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Enums\FailureCode;
use App\Notifications\Models\Alert;
use App\Notifications\Models\AlertRecipient;
use App\Notifications\Services\AlertRecipientFactory;
use App\Services\ProgramAlerts\Data\ProjectedAlert;
use App\Services\ProgramAlerts\Data\ProjectedRecipient;
use App\Services\ProgramAlerts\Data\ProjectionInput;
use App\Services\ProgramAlerts\Data\ReconcilePlan;
use App\Services\ProgramAlerts\Data\ReconcileReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Brings the persisted `program.task_due` alerts of a program in line with what the projector
 * says they should be, keyed by logical key. Replaces the old "delete every pending alert and
 * recreate" listener: alerts that still apply keep their guid, the ones that no longer apply are
 * soft-cancelled with a reason, removed recipients are suppressed (not deleted).
 *
 * Mutual exclusion with the dispatcher is compare-and-set: every change to an existing alert is
 * `UPDATE ... WHERE status = 'pending'` and must affect exactly one row; otherwise the alert was
 * claimed meanwhile and is counted as untouched_dispatched.
 */
final class ProgramAlertReconciler
{
    /** Triggers that run unattended: subject to the kill switch and the mass-cancel valve. */
    public const AUTOMATIC_TRIGGERS = ['protocol_updated', 'nightly'];

    public function __construct(
        private readonly ProgramAlertProjector $projector,
        private readonly AlertRecipientFactory $recipientFactory,
    ) {}

    /** Side-effect free. */
    public function plan(Program $program, ?CarbonImmutable $now = null): ReconcilePlan
    {
        $now ??= CarbonImmutable::instance(now());

        return $this->planFor($program, ProjectionInput::fromProgram($program, $now));
    }

    /**
     * Side-effect free: what reconciling the persisted alerts of `$program` against `$in` would do.
     * `$in` is the program's own state for a real reconciliation, or a proposed draft (new groups,
     * dates, protocol, responsibles) for the edit preview. Groups still unsaved must carry a
     * placeholder guid so their logical keys can be computed.
     */
    public function planFor(Program $program, ProjectionInput $in): ReconcilePlan
    {
        $plan = new ReconcilePlan();

        $projection = $this->projector->project($in);
        $cancelled = $program->cancelled_at !== null;

        /** @var array<string, ProjectedAlert> $desired */
        $desired = [];
        if (! $cancelled) {
            foreach ($projection->items as $item) {
                if ($item->recipients !== [] && $item->logicalKey !== null) {
                    $desired[$item->logicalKey] = $item;
                }
            }
        }

        $existing = $this->existingAlerts($program);
        $plan->pendingCount = $existing->where('status', AlertStatus::Pending->value)->count();

        $byKey = $existing->whereNotNull('logical_key')->keyBy('logical_key');
        $legacy = $existing->whereNull('logical_key')->values()->all();
        $matchedIds = [];

        foreach ($desired as $key => $item) {
            /** @var Alert|null $alert */
            $alert = $byKey->get($key);
            $adopted = false;

            if ($alert === null) {
                $alert = $this->takeLegacyMatch($legacy, $item);
                $adopted = $alert !== null;
            }

            if ($alert === null) {
                if ($item->isPast) {
                    $plan->skippedPast[] = $item;
                } else {
                    $plan->create[] = $item;
                }

                continue;
            }

            $matchedIds[$alert->id] = true;

            if ($alert->status === AlertStatus::Dispatched->value) {
                // "Already exists": never recreated, never modified, no new recipients.
                if ($adopted) {
                    $plan->adopt[] = ['alert' => $alert, 'item' => $item];
                }
                $plan->untouchedDispatched[] = $alert;

                continue;
            }

            if ($adopted) {
                $plan->adopt[] = ['alert' => $alert, 'item' => $item];
            } elseif ($this->payloadDiffers($alert, $item)) {
                $plan->update[] = ['alert' => $alert, 'item' => $item];
            } else {
                $plan->keep[] = $alert;
            }

            $this->planRecipients($plan, $alert, $item);
        }

        foreach ($existing as $alert) {
            if (isset($matchedIds[$alert->id])) {
                continue;
            }

            if ($alert->status !== AlertStatus::Pending->value) {
                // Already sent with data that no longer applies (e.g. the previous group date): informational.
                $plan->staleDispatched[] = $alert;

                continue;
            }

            $plan->cancel[] = [
                'alert' => $alert,
                'reason' => $this->cancelReason($alert, $in, $cancelled, $projection->items),
            ];
        }

        return $plan;
    }

    public function apply(Program $program, ReconcilePlan $plan, string $trigger, ?int $userId = null): ReconcileReport
    {
        $report = new ReconcileReport(
            kept: count($plan->keep),
            untouchedDispatched: count($plan->untouchedDispatched),
            skippedPast: count($plan->skippedPast),
            alreadySentNotModified: count($plan->staleDispatched),
            reschedulePairs: $this->countReschedulePairs($plan),
        );
        $now = Carbon::now();
        $claimed = [];

        foreach ($plan->adopt as ['alert' => $alert, 'item' => $item]) {
            if ($alert->status === AlertStatus::Dispatched->value) {
                // Already sent: only record its identity so it is recognised from now on.
                Alert::whereKey($alert->id)->whereNull('logical_key')->update(['logical_key' => $item->logicalKey]);
                $report->adopted++;

                continue;
            }

            if ($this->claim($alert, ['logical_key' => $item->logicalKey, 'payload' => json_encode($item->toPayload())])) {
                $claimed[$alert->id] = true;
                $report->adopted++;
            } else {
                $report->untouchedDispatched++;
            }
        }

        foreach ($plan->update as ['alert' => $alert, 'item' => $item]) {
            if ($this->claim($alert, ['payload' => json_encode($item->toPayload())])) {
                $claimed[$alert->id] = true;
                $report->updated++;
            } else {
                $report->untouchedDispatched++;
            }
        }

        foreach ($plan->recipientChanges as $change) {
            $alert = $change['alert'];

            if (! isset($claimed[$alert->id])) {
                // Recipient-only change: touch the row through the same CAS so the dispatcher cannot slip in.
                if (! $this->claim($alert, [])) {
                    $report->untouchedDispatched++;

                    continue;
                }
                $claimed[$alert->id] = true;
            }

            $ensured = $this->recipientFactory->ensureForManagers($alert, [...$change['add'], ...$change['reactivate']]);
            $report->recipientsAdded += $ensured['created'];
            $report->recipientsReactivated += $ensured['reactivated'];

            if ($change['suppress'] !== []) {
                $report->recipientsSuppressed += AlertRecipient::whereIn('id', $change['suppress'])
                    ->where('status', DeliveryStatus::Pending->value)
                    ->update([
                        'status' => DeliveryStatus::Suppressed->value,
                        'failure_reason' => FailureCode::NoLongerApplicable->value,
                        'failure_code' => FailureCode::NoLongerApplicable->value,
                    ]);
            }
        }

        foreach ($plan->cancel as ['alert' => $alert, 'reason' => $reason]) {
            $done = $this->claim($alert, [
                'status' => AlertStatus::Cancelled->value,
                'cancelled_at' => $now,
                'cancel_reason' => $reason->value,
                'logical_key' => null, // frees the key so the same date can be recreated later
            ]);

            if ($done) {
                $report->cancelledByReason[$reason->value] = ($report->cancelledByReason[$reason->value] ?? 0) + 1;
            } else {
                $report->untouchedDispatched++;
            }
        }

        foreach ($plan->create as $item) {
            $alert = new Alert([
                'type' => AlertType::ProgramTaskDue,
                'payload' => $item->toPayload(),
                'scheduled_at' => Carbon::instance($item->scheduledAtUtc),
                'status' => AlertStatus::Pending->value,
                'require_confirmation' => $item->requireConfirmation,
                'vet_id' => $program->vet_id,
                'logical_key' => $item->logicalKey,
            ]);
            $alert->subject()->associate($program);
            $alert->save();

            $this->recipientFactory->ensureForManagers(
                $alert,
                collect($item->recipients)->map(fn (ProjectedRecipient $r) => $r->profile),
            );
            $report->created++;
        }

        return $report;
    }

    /**
     * Transaction + per-program lock + plan + valve + apply + audit. Safe to call from inside
     * another transaction (ProgramService, ProgramManagerLinkService).
     */
    public function reconcile(
        Program $program,
        string $trigger,
        ?int $userId = null,
        ?CarbonImmutable $now = null,
    ): ReconcileReport {
        if (in_array($trigger, self::AUTOMATIC_TRIGGERS, true) && ! config('notifications.reconciler.enabled', true)) {
            Log::info('program.alerts.reconcile_skipped', ['program_id' => $program->id, 'trigger' => $trigger, 'reason' => 'disabled']);

            return ReconcileReport::aborted('disabled');
        }

        return DB::transaction(function () use ($program, $trigger, $userId, $now) {
            // Serializes concurrent reconciliations of the same program on MySQL (no-op on SQLite).
            $locked = Program::whereKey($program->id)->lockForUpdate()->firstOrFail();
            $locked->load('targets', 'protocol.tasks.alerts', 'managers.role', 'managers.user', 'vet.country');

            $plan = $this->plan($locked, $now);

            if ($this->violatesValve($plan, $trigger)) {
                $report = ReconcileReport::aborted('mass_cancel');
                Log::error('program.alerts.reconcile_aborted', [
                    'program_id' => $locked->id,
                    'trigger' => $trigger,
                    'pending' => $plan->pendingCount,
                    'would_cancel' => count($plan->cancel),
                ]);
                $this->audit($locked, $trigger, $userId, $report);

                return $report;
            }

            $report = $this->apply($locked, $plan, $trigger, $userId);

            if ($report->hasChanges()) {
                $this->audit($locked, $trigger, $userId, $report);
            }

            Log::info('program.alerts.reconciled', ['program_id' => $locked->id, 'trigger' => $trigger] + $report->counts());

            return $report;
        });
    }

    /**
     * Send-time revalidation: does this (possibly already dispatched, possibly retrying) alert still
     * apply to this person? Read-only. Rules: alert not cancelled; program active; the profile is
     * still a responsible whose role is in the protocol alert's roles; v2 payload: the group still
     * exists and the key recomputed from live data matches `logical_key` (same task/alert, same
     * instant); legacy payload: the protocol alert is still part of the current protocol.
     */
    public function isStillValid(Alert $alert, int $userProfileId): bool
    {
        if ($alert->status === AlertStatus::Cancelled->value) {
            return false;
        }

        $program = $alert->subject;
        if (! $program instanceof Program) {
            return false;
        }

        // Fresh state: a retry must see changes made after the job was queued.
        $program = Program::with('targets', 'protocol.tasks.alerts', 'managers.role', 'managers.user', 'vet.country')
            ->find($program->id);

        if ($program === null || $program->cancelled_at !== null) {
            return false;
        }

        $manager = $program->managers->firstWhere('id', $userProfileId);
        $taskAlertGuid = $alert->payload['protocolTaskAlertGuid'] ?? null;

        if ($manager === null || $taskAlertGuid === null) {
            return false;
        }

        // A deleted protocol or a profile without role can no longer receive this alert: not applicable, not an error.
        $roleName = $manager->role?->name;
        $protocolAlert = $program->protocol?->tasks
            ->flatMap(fn ($task) => $task->alerts)
            ->firstWhere('guid', $taskAlertGuid);

        if ($protocolAlert === null || $roleName === null || ! in_array($roleName, $protocolAlert->roles ?? [], true)) {
            return false;
        }

        $targetGuid = $alert->payload['programTargetGuid'] ?? null;

        if ($targetGuid === null || $alert->logical_key === null) {
            return true; // legacy payload: program active + still responsible + alert still in the protocol
        }

        $projection = $this->projector->project(ProjectionInput::fromProgram($program, CarbonImmutable::instance(now())));

        foreach ($projection->items as $item) {
            if ($item->targetGuid === $targetGuid && $item->logicalKey === $alert->logical_key) {
                return true;
            }
        }

        return false;
    }

    /**
     * A reschedule is a cancel (`rescheduled`) plus the creation for the same group and protocol
     * alert on the new date; the RF-11 summary reports the pair once.
     */
    private function countReschedulePairs(ReconcilePlan $plan): int
    {
        $creates = array_map(
            fn (ProjectedAlert $item) => $item->targetGuid . '|' . $item->protocolTaskAlertGuid,
            $plan->create,
        );
        $pairs = 0;

        foreach ($plan->cancel as ['alert' => $alert, 'reason' => $reason]) {
            if ($reason !== AlertCancelReason::Rescheduled) {
                continue;
            }

            $key = ($alert->payload['programTargetGuid'] ?? null) . '|' . ($alert->payload['protocolTaskAlertGuid'] ?? null);
            $index = array_search($key, $creates, true);

            if ($index !== false) {
                unset($creates[$index]);
                $pairs++;
            }
        }

        return $pairs;
    }

    /** More than half of the pending alerts would be cancelled by an unattended trigger: refuse. */
    private function violatesValve(ReconcilePlan $plan, string $trigger): bool
    {
        return in_array($trigger, self::AUTOMATIC_TRIGGERS, true)
            && $plan->pendingCount > 0
            && count($plan->cancel) * 2 > $plan->pendingCount;
    }

    /** The audit row is traceability only: failing to write it must not undo the reconciliation (nor the save around it). */
    private function audit(Program $program, string $trigger, ?int $userId, ReconcileReport $report): void
    {
        try {
            // Savepoint so a failed insert cannot poison the surrounding transaction.
            DB::transaction(fn () => ProgramAlertReconciliation::create([
                'program_id' => $program->id,
                'user_id' => $userId,
                'trigger' => $trigger,
                'counts' => $report->counts(),
                'aborted' => $report->aborted,
            ]));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** CAS: change a still-pending alert. Returns false when it was claimed by the dispatcher meanwhile. */
    private function claim(Alert $alert, array $attributes): bool
    {
        $attributes += ['updated_at' => Carbon::now()];

        return Alert::whereKey($alert->id)
            ->where('status', AlertStatus::Pending->value)
            ->update($attributes) === 1;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Alert> */
    private function existingAlerts(Program $program)
    {
        return Alert::query()
            ->where('type', AlertType::ProgramTaskDue)
            ->where('subject_type', 'program')
            ->where('subject_id', $program->id)
            ->whereIn('status', [AlertStatus::Pending->value, AlertStatus::Dispatched->value])
            ->with('recipients')
            ->get();
    }

    /**
     * Legacy (key-less) alert for the same protocol task alert and instant, used at most once.
     *
     * @param list<Alert> $legacy
     */
    private function takeLegacyMatch(array &$legacy, ProjectedAlert $item): ?Alert
    {
        foreach ($legacy as $index => $alert) {
            if (($alert->payload['protocolTaskAlertGuid'] ?? null) === $item->protocolTaskAlertGuid
                && $alert->scheduled_at->getTimestamp() === $item->scheduledAtUtc->getTimestamp()) {
                unset($legacy[$index]);

                return $alert;
            }
        }

        return null;
    }

    private function payloadDiffers(Alert $alert, ProjectedAlert $item): bool
    {
        return ($alert->payload ?? []) != $item->toPayload();
    }

    /** Diff the desired responsibles against the alert's recipient rows. */
    private function planRecipients(ReconcilePlan $plan, Alert $alert, ProjectedAlert $item): void
    {
        $desiredProfiles = collect($item->recipients)->map(fn (ProjectedRecipient $r) => $r->profile)->keyBy('id');
        $rowsByProfile = $alert->recipients->groupBy('user_profile_id');

        $add = [];
        $reactivate = [];
        $suppress = [];

        /** @var UserProfile $profile */
        foreach ($desiredProfiles as $profileId => $profile) {
            $rows = $rowsByProfile->get($profileId);

            if ($rows === null || $rows->isEmpty()) {
                $add[] = $profile;
            } elseif ($rows->every(fn (AlertRecipient $row) => $row->status === DeliveryStatus::Suppressed
                && $row->failure_code === FailureCode::NoLongerApplicable)) {
                $reactivate[] = $profile;
            }
        }

        foreach ($rowsByProfile as $profileId => $rows) {
            if ($desiredProfiles->has($profileId)) {
                continue;
            }

            foreach ($rows as $row) {
                if ($row->status === DeliveryStatus::Pending) {
                    $suppress[] = $row->id;
                }
            }
        }

        if ($add !== [] || $reactivate !== [] || $suppress !== []) {
            $plan->recipientChanges[] = [
                'alert' => $alert,
                'add' => $add,
                'reactivate' => $reactivate,
                'suppress' => $suppress,
            ];
        }
    }

    /** @param list<ProjectedAlert> $items every projected item, including the ones without recipients */
    private function cancelReason(Alert $alert, ProjectionInput $in, bool $programCancelled, array $items): AlertCancelReason
    {
        if ($programCancelled) {
            return AlertCancelReason::ProgramCancelled;
        }

        $payload = $alert->payload ?? [];
        $targetGuid = $payload['programTargetGuid'] ?? null;
        $taskAlertGuid = $payload['protocolTaskAlertGuid'] ?? null;

        if ($targetGuid !== null && ! collect($in->targets)->contains(fn ($target) => $target->guid === $targetGuid)) {
            return AlertCancelReason::GroupRemoved;
        }

        $currentAlertGuids = $in->protocol->tasks->flatMap(fn ($task) => $task->alerts->pluck('guid'));
        $payloadProtocol = $payload['protocolGuid'] ?? null;

        if (($payloadProtocol !== null && $payloadProtocol !== $in->protocol->guid)
            || ($taskAlertGuid !== null && ! $currentAlertGuids->contains($taskAlertGuid))) {
            return AlertCancelReason::ProtocolChanged;
        }

        $sameSlot = array_filter(
            $items,
            fn (ProjectedAlert $item) => $item->targetGuid === $targetGuid && $item->protocolTaskAlertGuid === $taskAlertGuid,
        );

        if ($targetGuid !== null && $sameSlot !== []) {
            $withRecipients = array_filter($sameSlot, fn (ProjectedAlert $item) => $item->recipients !== []);

            return $withRecipients !== [] ? AlertCancelReason::Rescheduled : AlertCancelReason::RecipientRemoved;
        }

        return AlertCancelReason::Superseded;
    }
}
