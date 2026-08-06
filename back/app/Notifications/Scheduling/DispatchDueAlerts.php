<?php

namespace App\Notifications\Scheduling;

use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Jobs\DeliverAlertJob;
use App\Notifications\Models\Alert;
use Illuminate\Console\Command;

class DispatchDueAlerts extends Command
{
    protected $signature = 'alerts:dispatch-due';

    protected $description = 'Fan-out de las alertas pendientes cuya scheduled_at ya llegó, un DeliverAlertJob por destinatario.';

    public function handle(): int
    {
        $dispatched = 0;

        Alert::query()
            ->where('status', 'pending')
            ->where('scheduled_at', '<=', now())
            ->with('recipients')
            ->chunkById(100, function ($alerts) use (&$dispatched) {
                foreach ($alerts as $alert) {
                    foreach ($alert->recipients as $recipient) {
                        if ($recipient->status === DeliveryStatus::Pending) {
                            DeliverAlertJob::dispatch($recipient->id);
                            $dispatched++;
                        }
                    }

                    $alert->update(['status' => 'dispatched']);
                }
            });

        $this->info("Se despacharon {$dispatched} destinatarios.");

        return Command::SUCCESS;
    }
}
