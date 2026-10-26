<?php

namespace App\Services\ProgramAlerts;

use App\Models\ProtocolTask;
use App\Models\ProtocolTaskAlert;
use App\Notifications\Enums\AlertType;
use App\Services\ProgramAlerts\Data\LocalDateTime;
use App\Services\ProgramAlerts\Data\ProgramProjection;
use App\Services\ProgramAlerts\Data\ProjectedAlert;
use App\Services\ProgramAlerts\Data\ProjectedRecipient;
use App\Services\ProgramAlerts\Data\ProjectedTask;
use App\Services\ProgramAlerts\Data\ProjectionInput;
use App\Services\ProgramAlerts\Data\TargetInput;
use App\Support\AlertMessagePlaceholders;
use App\Support\DateOffset;
use Carbon\CarbonImmutable;

/**
 * Single source of truth for "which alerts does this program imply". Pure: no writes, no
 * dispatching. It never discards anything: past alerts are flagged (`isPast`) and alerts
 * without recipients carry an empty `recipients` list. Deciding what to persist belongs to
 * the caller.
 */
final class ProgramAlertProjector
{
    public function project(ProjectionInput $in): ProgramProjection
    {
        $timezone = $in->vet->timezone();
        $multiGroup = count($in->targets) > 1;
        $tasks = [];
        $items = [];

        foreach ($in->targets as $target) {
            foreach ($in->protocol->tasks as $task) {
                $taskDate = DateOffset::apply($target->date, $task->days_offset, $task->time_of_day)->toDateString();

                $alerts = [];
                foreach ($task->alerts as $alert) {
                    $alerts[] = $items[] = $this->projectAlert($in, $target, $task, $alert, $taskDate, $timezone, $multiGroup);
                }

                $tasks[] = new ProjectedTask(
                    targetRef: $target->ref,
                    targetGuid: $target->guid,
                    protocolTaskGuid: $task->guid,
                    description: $task->description,
                    date: $taskDate,
                    time: (string) $task->time,
                    important: (bool) $task->important,
                    alerts: $alerts,
                );
            }
        }

        usort($items, fn (ProjectedAlert $a, ProjectedAlert $b) => $a->scheduledAtUtc <=> $b->scheduledAtUtc);

        return new ProgramProjection(
            items: $items,
            tasks: $tasks,
            tasksWithoutAlerts: $this->tasksWithoutAlerts($in),
            timezone: $timezone,
        );
    }

    private function projectAlert(
        ProjectionInput $in,
        TargetInput $target,
        ProtocolTask $task,
        ProtocolTaskAlert $alert,
        string $taskDate,
        string $timezone,
        bool $multiGroup,
    ): ProjectedAlert {
        $alertDate = DateOffset::apply($taskDate, $alert->offset_days, $alert->time_of_day);

        // The configured time is the vet's local time: interpret it in the vet's timezone, keep UTC for storage/comparison.
        $scheduledAtLocal = CarbonImmutable::parse($alertDate->toDateString() . ' ' . $alert->time, $timezone);
        $scheduledAtUtc = $scheduledAtLocal->utc();

        $recipients = $in->managers
            ->filter(fn ($manager) => in_array($manager->role->name, $alert->roles, true))
            ->map(fn ($manager) => new ProjectedRecipient($manager, $manager->role->name))
            ->values()
            ->all();

        $logicalKey = $in->programGuid !== null && $target->guid !== null
            ? LogicalKey::for($in->programGuid, AlertType::ProgramTaskDue, $target->guid, $alert->guid, $scheduledAtUtc)
            : null;

        return new ProjectedAlert(
            targetRef: $target->ref,
            targetGuid: $target->guid,
            targetDate: $target->date,
            protocolGuid: $in->protocol->guid,
            protocolTaskGuid: $task->guid,
            taskDescription: $task->description,
            taskDate: $taskDate,
            taskTime: substr((string) $task->time, 0, 5),
            important: (bool) $task->important,
            protocolTaskAlertGuid: $alert->guid,
            alertTime: (string) $alert->time,
            message: AlertMessagePlaceholders::resolve($alert->message, $in->establishmentName, $target->date, $in->locale, $target->name, $target->animalCount),
            requireConfirmation: (bool) $alert->require_confirmation,
            roles: $alert->roles ?? [],
            scheduledAtLocal: LocalDateTime::from($scheduledAtLocal, $in->locale),
            scheduledAtUtc: $scheduledAtUtc,
            isPast: $scheduledAtUtc <= $in->now,
            multiGroup: $multiGroup,
            recipients: $recipients,
            logicalKey: $logicalKey,
            targetName: $target->name !== null && trim($target->name) !== '' ? trim($target->name) : null,
        );
    }

    /** @return list<array{protocolTaskGuid: string, description: string}> */
    private function tasksWithoutAlerts(ProjectionInput $in): array
    {
        return $in->protocol->tasks
            ->filter(fn ($task) => $task->alerts->isEmpty())
            ->map(fn ($task) => ['protocolTaskGuid' => $task->guid, 'description' => $task->description])
            ->values()
            ->all();
    }
}
