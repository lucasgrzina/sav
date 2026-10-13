<?php

namespace App\Listeners;

use App\Events\ProgramCancelledEvent;
use App\Notifications\Enums\AlertType;
use App\Notifications\Models\Alert;
use App\Notifications\Services\AlertRecipientFactory;
use App\Services\ProgramAlerts\ProgramAlertReconciler;

class HandleProgramCancelledListener
{
    public function __construct(
        private readonly AlertRecipientFactory $recipientFactory,
        private readonly ProgramAlertReconciler $reconciler,
    ) {}

    public function handle(ProgramCancelledEvent $event): void
    {
        $program = $event->program;

        // Soft-cancels the pending task_due alerts (reason program_cancelled); dispatched ones that are
        // still queued or retrying are stopped by the send-time revalidator.
        $this->reconciler->reconcile($program, 'program_cancelled', auth()->id());

        $alert = new Alert([
            'type' => AlertType::ProgramCancelled,
            'payload' => [],
            'scheduled_at' => now(),
            'status' => 'pending',
            'vet_id' => $program->vet_id,
        ]);
        $alert->subject()->associate($program);
        $alert->save();

        $this->recipientFactory->createForManagers($alert, $program->managers);
    }
}
