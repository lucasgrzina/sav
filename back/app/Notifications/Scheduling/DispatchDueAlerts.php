<?php

namespace App\Notifications\Scheduling;

use App\Notifications\Enums\AlertStatus;
use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Jobs\DeliverAlertJob;
use App\Notifications\Models\Alert;
use App\Notifications\Models\AlertRecipient;
use Illuminate\Console\Command;
use App\Notifications\Support\AlertsSchemaGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class DispatchDueAlerts extends Command
{
    protected $signature = 'alerts:dispatch-due';

    protected $description = 'Fan-out de las alertas pendientes cuya scheduled_at ya llegó, un DeliverAlertJob por destinatario.';

    public function handle(AlertsSchemaGuard $schema): int
    {
        // Code deployed before SQL-01/SQL-02: claiming alerts now would create jobs that cannot
        // record their outcome. Leave everything `pending` so the next run picks it up once fixed.
        if (! $schema->isReady()) {
            Log::critical('alerts:dispatch-due: alerts schema is out of date, nothing dispatched', ['missing' => $schema->missing()]);
            $this->error('Alerts schema out of date (apply the Fase 3 SQL scripts). Missing: '.implode(', ', $schema->missing()));

            return Command::FAILURE;
        }

        $dispatched = 0;
        $failed = 0;

        Alert::query()
            ->where('status', AlertStatus::Pending->value)
            ->where('scheduled_at', '<=', now())
            ->chunkById(100, function ($alerts) use (&$dispatched, &$failed) {
                foreach ($alerts as $alert) {
                    // One poison alert must not starve the ones behind it on every run.
                    try {
                        $dispatched += $this->dispatchAlert($alert);
                    } catch (Throwable $e) {
                        $failed++;
                        report($e);
                    }
                }
            });

        $this->info(now()->toDateTimeString()." - Se despacharon {$dispatched} destinatarios.");

        if ($failed > 0) {
            $this->error("{$failed} alertas no se pudieron despachar (ver logs).");

            return Command::FAILURE;
        }

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
