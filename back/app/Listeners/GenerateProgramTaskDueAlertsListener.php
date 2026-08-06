<?php

namespace App\Listeners;

use App\Events\ProgramTargetsChangedEvent;
use App\Models\Program;
use App\Models\ProgramTarget;
use App\Models\ProtocolTask;
use App\Models\ProtocolTaskAlert;
use App\Notifications\Enums\AlertType;
use App\Notifications\Enums\Channel;
use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Models\Alert;
use App\Notifications\Models\AlertRecipient;
use App\Support\DateOffset;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Mirrors ProgramService::projectTargetTasks (read-only preview) but persists real
 * Alert/AlertRecipient rows. Runs on both Program creation and edition — deleting and
 * regenerating pending program.task_due alerts keeps them in sync with the program's
 * current targets/protocol (confirmed decision: edits DO regenerate pending alerts).
 */
class GenerateProgramTaskDueAlertsListener
{
    public function handle(ProgramTargetsChangedEvent $event): void
    {
        $program = $event->program;
        $program->loadMissing('targets', 'protocol.tasks.alerts', 'managers.role');

        $this->deletePendingTaskDueAlerts($program);

        foreach ($program->targets as $target) {
            foreach ($program->protocol->tasks as $task) {
                $this->generateAlertsForTask($program, $target, $task);
            }
        }
    }

    private function deletePendingTaskDueAlerts(Program $program): void
    {
        Alert::query()
            ->where('type', AlertType::ProgramTaskDue)
            ->where('subject_type', 'program')
            ->where('subject_id', $program->id)
            ->where('status', 'pending')
            ->get()
            ->each(fn (Alert $pending) => $pending->delete());
    }

    private function generateAlertsForTask(Program $program, ProgramTarget $target, ProtocolTask $task): void
    {
        $taskDate = DateOffset::apply($target->target_date, $task->days_offset, $task->time_of_day);

        foreach ($task->alerts as $protocolTaskAlert) {
            $this->generateAlertForProtocolTaskAlert($program, $taskDate, $protocolTaskAlert);
        }
    }

    private function generateAlertForProtocolTaskAlert(Program $program, Carbon $taskDate, ProtocolTaskAlert $protocolTaskAlert): void
    {
        $alertDate = DateOffset::apply($taskDate, $protocolTaskAlert->offset_days, $protocolTaskAlert->time_of_day);
        $scheduledAt = Carbon::parse($alertDate->toDateString() . ' ' . $protocolTaskAlert->time);

        if ($scheduledAt->isPast()) {
            return; // descarte silencioso, sin log — regla 2.1
        }

        $recipients = $program->managers->filter(
            fn ($manager) => in_array($manager->role->name, $protocolTaskAlert->roles, true)
        );

        if ($recipients->isEmpty()) {
            return;
        }

        $alert = new Alert([
            'type' => AlertType::ProgramTaskDue,
            'payload' => [
                'protocolTaskAlertGuid' => $protocolTaskAlert->guid,
                'message' => $protocolTaskAlert->message,
            ],
            'scheduled_at' => $scheduledAt,
            'status' => 'pending',
            'require_confirmation' => $protocolTaskAlert->require_confirmation,
            'vet_id' => $program->vet_id,
        ]);
        $alert->subject()->associate($program);
        $alert->save();

        foreach ($recipients as $manager) {
            AlertRecipient::create([
                'alert_id' => $alert->id,
                'user_profile_id' => $manager->id,
                'channel' => Channel::Whatsapp,
                'status' => DeliveryStatus::Pending,
                'idempotency_key' => Str::uuid()->toString(),
            ]);
        }
    }
}
