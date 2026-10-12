<?php

namespace App\Listeners;

use App\Events\ProgramTargetsChangedEvent;
use App\Models\Program;
use App\Models\ProtocolTaskAlert;
use App\Notifications\Enums\AlertType;
use App\Notifications\Models\Alert;
use App\Notifications\Services\AlertRecipientFactory;
use App\Support\DateOffset;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Mirrors ProgramService::projectTargetTasks (read-only preview) but persists real
 * Alert/AlertRecipient rows. Runs on both Program creation and edition — deleting and
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
    public function __construct(private readonly AlertRecipientFactory $recipientFactory) {}

    public function handle(ProgramTargetsChangedEvent $event): void
    {
        $program = $event->program;
        $program->loadMissing('targets', 'protocol.tasks.alerts', 'managers.role', 'vet.country');

        // Delete + recreate must be atomic: a failure midway must not leave the program without alerts.
        DB::transaction(function () use ($program) {
            $desired = $this->desiredAlerts($program);

            $this->reconcilePendingTaskDueAlerts($program, $desired);

            foreach ($desired as $entry) {
                if ($entry['scheduledAt']->isPast()) {
                    continue; // already handled above (kept for the dispatcher if still valid)
                }

                $this->createAlert($program, $entry);
            }
        });
    }

    /**
     * Every alert the program should have right now, past ones included, keyed by logical key.
     *
     * @return array<string, array{protocolTaskAlert: ProtocolTaskAlert, scheduledAt: Carbon, recipients: Collection}>
     */
    private function desiredAlerts(Program $program): array
    {
        $desired = [];

        foreach ($program->targets as $target) {
            foreach ($program->protocol->tasks as $task) {
                $taskDate = DateOffset::apply($target->target_date, $task->days_offset, $task->time_of_day);

                foreach ($task->alerts as $protocolTaskAlert) {
                    $entry = $this->desiredEntry($program, $taskDate, $protocolTaskAlert);

                    if ($entry !== null) {
                        $desired[$this->logicalKey($protocolTaskAlert->guid, $entry['scheduledAt'])] = $entry;
                    }
                }
            }
        }

        return $desired;
    }

    private function desiredEntry(Program $program, Carbon $taskDate, ProtocolTaskAlert $protocolTaskAlert): ?array
    {
        $alertDate = DateOffset::apply($taskDate, $protocolTaskAlert->offset_days, $protocolTaskAlert->time_of_day);
        // The configured time is the vet's local time: interpret it in the vet country's timezone
        // and store it as UTC (also used for the isPast() comparison).
        $scheduledAt = Carbon::parse($alertDate->toDateString() . ' ' . $protocolTaskAlert->time, $program->vet->timezone())->utc();

        $recipients = $program->managers->filter(
            fn ($manager) => in_array($manager->role->name, $protocolTaskAlert->roles, true)
        );

        if ($recipients->isEmpty()) {
            return null;
        }

        return [
            'protocolTaskAlert' => $protocolTaskAlert,
            'scheduledAt' => $scheduledAt,
            'recipients' => $recipients,
        ];
    }

    private function logicalKey(string $protocolTaskAlertGuid, Carbon $scheduledAt): string
    {
        return $protocolTaskAlertGuid . '|' . $scheduledAt->copy()->utc()->format('Y-m-d H:i:s');
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
                    ->whereNotIn('user_profile_id', $desired[$key]['recipients']->pluck('id'))
                    ->delete();
            });
    }

    private function createAlert(Program $program, array $entry): void
    {
        /** @var ProtocolTaskAlert $protocolTaskAlert */
        $protocolTaskAlert = $entry['protocolTaskAlert'];

        $alert = new Alert([
            'type' => AlertType::ProgramTaskDue,
            'payload' => [
                'protocolTaskAlertGuid' => $protocolTaskAlert->guid,
                'message' => $protocolTaskAlert->message,
            ],
            'scheduled_at' => $entry['scheduledAt'],
            'status' => 'pending',
            'require_confirmation' => $protocolTaskAlert->require_confirmation,
            'vet_id' => $program->vet_id,
        ]);
        $alert->subject()->associate($program);
        $alert->save();

        $this->recipientFactory->createForManagers($alert, $entry['recipients']);
    }
}
