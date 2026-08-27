<?php

namespace App\Listeners;

use App\Events\ProgramCancelledEvent;
use App\Notifications\Enums\AlertType;
use App\Notifications\Models\Alert;
use App\Notifications\Services\AlertRecipientFactory;

class HandleProgramCancelledListener
{
    public function __construct(private readonly AlertRecipientFactory $recipientFactory) {}

    public function handle(ProgramCancelledEvent $event): void
    {
        $program = $event->program;

        Alert::query()
            ->where('type', AlertType::ProgramTaskDue)
            ->where('subject_type', 'program')
            ->where('subject_id', $program->id)
            ->where('status', 'pending')
            ->get()
            ->each(fn (Alert $pending) => $pending->delete());

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
