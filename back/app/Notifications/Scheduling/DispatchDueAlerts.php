<?php

namespace App\Notifications\Scheduling;

use App\Notifications\Enums\AlertStatus;
use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Jobs\DeliverAlertJob;
use App\Notifications\Models\Alert;
use App\Notifications\Models\AlertRecipient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchDueAlerts extends Command
{
    protected $signature = 'alerts:dispatch-due';

    protected $description = 'Fan-out de las alertas pendientes cuya scheduled_at ya llegó, un DeliverAlertJob por destinatario.';

    public function handle(): int
    {
        $dispatched = 0;

        Alert::query()
            ->where('status', AlertStatus::Pending->value)
            ->where('scheduled_at', '<=', now())
            ->chunkById(100, function ($alerts) use (&$dispatched) {
                foreach ($alerts as $alert) {
                    $dispatched += $this->dispatchAlert($alert);
                }
            });

        $this->info(now()->toDateTimeString()." - Se despacharon {$dispatched} destinatarios.");

        return Command::SUCCESS;
    }

    /**
     * Claims the alert with a single compare-and-set (pending -> dispatched) and only then loads
     * its recipients, so anything that changes the alert concurrently (edit, cancellation) either
     * wins the CAS first (we skip it) or is serialized after it. Jobs are pushed after commit.
     */
    private function dispatchAlert(Alert $alert): int
    {
        return DB::transaction(function () use ($alert): int {
            $claimed = Alert::query()
                ->whereKey($alert->id)
                ->where('status', AlertStatus::Pending->value)
                ->update(['status' => AlertStatus::Dispatched->value]);

            if ($claimed !== 1) {
                return 0;
            }

            $recipients = AlertRecipient::query()
                ->where('alert_id', $alert->id)
                ->where('status', DeliveryStatus::Pending->value)
                ->get(['id']);

            foreach ($recipients as $recipient) {
                DeliverAlertJob::dispatch($recipient->id)->afterCommit();
            }

            return $recipients->count();
        });
    }
}
