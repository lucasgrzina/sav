<?php

namespace App\Listeners;

use App\Events\ProgramCreatedEvent;
use App\Notifications\Enums\AlertType;
use App\Notifications\Enums\Channel;
use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Models\Alert;
use App\Notifications\Models\AlertRecipient;
use Illuminate\Support\Str;

class ScheduleProgramCreatedAlertListener
{
    public function handle(ProgramCreatedEvent $event): void
    {
        $program = $event->program;

        $alert = new Alert([
            'type' => AlertType::ProgramCreated,
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
