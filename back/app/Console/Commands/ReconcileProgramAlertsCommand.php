<?php

namespace App\Console\Commands;

use App\Models\Program;
use App\Services\ProgramAlerts\Data\ReconcilePlan;
use App\Services\ProgramAlerts\ProgramAlertReconciler;
use Illuminate\Console\Command;

class ReconcileProgramAlertsCommand extends Command
{
    protected $signature = 'programs:reconcile-alerts
        {--program= : Only this program (guid)}
        {--stale-only : Only programs whose alerts are out of sync}
        {--dry-run : Only report what would change, write nothing}
        {--trigger=command : Trigger recorded in the audit trail}';

    protected $description = 'Reconciles the task_due alerts of active programs against their current protocol and groups.';

    public function handle(ProgramAlertReconciler $reconciler): int
    {
        $trigger = (string) $this->option('trigger');
        $dryRun = (bool) $this->option('dry-run');
        $staleOnly = (bool) $this->option('stale-only');

        if (! $dryRun
            && in_array($trigger, ProgramAlertReconciler::AUTOMATIC_TRIGGERS, true)
            && ! config('notifications.reconciler.enabled', true)) {
            $this->warn('Reconciler disabled (ALERTS_RECONCILER_ENABLED=false): nothing done.');

            return Command::SUCCESS;
        }

        $query = Program::query()->whereNull('cancelled_at');
        if ($guid = $this->option('program')) {
            $query->where('guid', $guid);
        }

        $rows = [];
        $failed = 0;

        $query->chunkById(100, function ($programs) use (&$rows, &$failed, $reconciler, $trigger, $dryRun, $staleOnly) {
            foreach ($programs as $program) {
                try {
                    $row = $this->process($reconciler, $program, $trigger, $dryRun, $staleOnly);
                    if ($row !== null) {
                        $rows[] = $row;
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    report($e);
                    $rows[] = [$program->guid, 'failed', '-', '-', '-', '-'];
                }
            }
        });

        if ($rows === []) {
            $this->info($staleOnly ? 'No programs out of sync.' : 'No active programs found.');
        } else {
            $this->table(['Program', 'Status', 'Create', 'Update', 'Cancel', 'Recipients'], $rows);
        }

        if ($failed > 0) {
            $this->error("{$failed} programs failed (see log).");

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /** @return list<string|int>|null null when the program is skipped (nothing to do in stale-only mode) */
    private function process(ProgramAlertReconciler $reconciler, Program $program, string $trigger, bool $dryRun, bool $staleOnly): ?array
    {
        if ($dryRun || $staleOnly) {
            $program->load('targets', 'protocol.tasks.alerts', 'managers.role', 'managers.user', 'vet.country');
            $plan = $reconciler->plan($program);

            if ($staleOnly && $plan->isEmpty()) {
                return null;
            }

            if ($dryRun) {
                return $this->planRow($program, $plan);
            }
        }

        $report = $reconciler->reconcile($program, $trigger);
        $counts = $report->counts();

        return [
            $program->guid,
            $report->aborted ? 'aborted:' . $report->abortReason : 'reconciled',
            $report->created,
            $report->updated + $report->adopted,
            $report->cancelledTotal(),
            $counts['recipients']['added'] + $counts['recipients']['reactivated'] + $counts['recipients']['suppressed'],
        ];
    }

    /** @return list<string|int> */
    private function planRow(Program $program, ReconcilePlan $plan): array
    {
        return [
            $program->guid,
            $plan->isEmpty() ? 'in sync' : 'would change',
            count($plan->create),
            count($plan->update) + count($plan->adopt),
            count($plan->cancel),
            count($plan->recipientChanges),
        ];
    }
}
