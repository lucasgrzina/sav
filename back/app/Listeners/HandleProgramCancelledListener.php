<?php

namespace App\Listeners;

use App\Events\ProgramCancelledEvent;
use App\Notifications\Enums\AlertType;
use App\Notifications\Enums\Channel;
use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Models\Alert;
use App\Notifications\Models\AlertRecipient;
use Illuminate\Support\Str;

class HandleProgramCancelledListener
{
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

        foreach ($program->managers as $manager) {
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
