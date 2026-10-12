<?php

namespace App\Listeners;

use App\Events\ProgramTargetsChangedEvent;
use App\Models\Program;
use App\Notifications\Enums\AlertType;
use App\Notifications\Models\Alert;
use App\Notifications\Services\AlertRecipientFactory;
use App\Services\ProgramAlerts\Data\ProjectedAlert;
use App\Services\ProgramAlerts\Data\ProjectedRecipient;
use App\Services\ProgramAlerts\Data\ProjectionInput;
use App\Services\ProgramAlerts\ProgramAlertProjector;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Persists real Alert/AlertRecipient rows from ProgramAlertProjector (the same calculation the
 * program detail uses). Runs on both Program creation and edition — deleting and
 * regenerating pending program.task_due alerts keeps them in sync with the program's
 * current targets/protocol (confirmed decision: edits DO regenerate pending alerts).
 *
 * Overdue pending alerts (scheduled_at already passed, dispatcher not run yet) are NOT
 * regenerated (they are in the past) and must not be lost either: they are kept for the
 * dispatcher as long as their logical key (protocol task alert guid + scheduled_at) is still
 * valid for the program, pruning only the recipients that are no longer responsible.
 */
class GenerateProgramTaskDueAlertsListener
{
    public function __construct(
        private readonly AlertRecipientFactory $recipientFactory,
        private readonly ProgramAlertProjector $projector,
    ) {}

    public function handle(ProgramTargetsChangedEvent $event): void
    {
        $program = $event->program;
        $program->loadMissing('targets', 'protocol.tasks.alerts', 'managers.role', 'managers.user', 'vet.country');

        // Delete + recreate must be atomic: a failure midway must not leave the program without alerts.
        DB::transaction(function () use ($program) {
            $desired = $this->desiredAlerts($program);

            $this->reconcilePendingTaskDueAlerts($program, $desired);

            foreach ($desired as $item) {
                if ($item->isPast) {
                    continue; // already handled above (kept for the dispatcher if still valid)
                }

                $this->createAlert($program, $item);
            }
        });
    }

    /**
     * Every alert the program should have right now, past ones included, keyed by logical key.
     * Computed by the shared projector (RT-01); alerts without recipients are not persisted.
     *
     * @return array<string, ProjectedAlert>
     */
    private function desiredAlerts(Program $program): array
    {
        $projection = $this->projector->project(
            ProjectionInput::fromProgram($program, CarbonImmutable::instance(now()))
        );

        $desired = [];

        foreach ($projection->items as $item) {
            if ($item->recipients === []) {
                continue;
            }

            $desired[$this->logicalKey($item->protocolTaskAlertGuid, $item->scheduledAtUtc)] = $item;
        }

        return $desired;
    }

    private function logicalKey(string $protocolTaskAlertGuid, CarbonInterface $scheduledAt): string
    {
        return $protocolTaskAlertGuid . '|' . CarbonImmutable::instance($scheduledAt)->utc()->format('Y-m-d H:i:s');
    }

    /**
     * Future pending alerts are deleted (they get regenerated). Overdue pending alerts are kept
     * when still valid, with recipients pruned to the current responsibles; otherwise deleted.
     */
    private function reconcilePendingTaskDueAlerts(Program $program, array $desired): void
    {
        Alert::query()
            ->where('type', AlertType::ProgramTaskDue)
            ->where('subject_type', 'program')
            ->where('subject_id', $program->id)
            ->where('status', 'pending')
            ->get()
            ->each(function (Alert $pending) use ($desired) {
                $key = isset($pending->payload['protocolTaskAlertGuid'])
                    ? $this->logicalKey($pending->payload['protocolTaskAlertGuid'], $pending->scheduled_at)
                    : null;

                if (! $pending->scheduled_at->isPast() || $key === null || ! isset($desired[$key])) {
                    $pending->delete();

                    return;
                }

                $pending->recipients()
                    ->whereNotIn('user_profile_id', collect($desired[$key]->recipients)->map(fn (ProjectedRecipient $r) => $r->profile->id))
                    ->delete();
            });
    }

    private function createAlert(Program $program, ProjectedAlert $item): void
    {
        $alert = new Alert([
            'type' => AlertType::ProgramTaskDue,
            'payload' => [
                'protocolTaskAlertGuid' => $item->protocolTaskAlertGuid,
                'message' => $item->message,
            ],
            'scheduled_at' => Carbon::instance($item->scheduledAtUtc),
            'status' => 'pending',
            'require_confirmation' => $item->requireConfirmation,
            'vet_id' => $program->vet_id,
        ]);
        $alert->subject()->associate($program);
        $alert->save();

        $this->recipientFactory->createForManagers(
            $alert,
            collect($item->recipients)->map(fn (ProjectedRecipient $r) => $r->profile),
        );
    }
}
