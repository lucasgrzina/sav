<?php

namespace App\Jobs;

use App\Models\Program;
use App\Services\ProgramAlerts\ProgramAlertReconciler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Reconciles the alerts of ONE program (one job per program, so a failure never blocks the others).
 */
class ReconcileProgramAlertsJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(
        public readonly int $programId,
        public readonly string $trigger,
    ) {}

    public function handle(ProgramAlertReconciler $reconciler): void
    {
        $program = Program::find($this->programId);

        if ($program === null || $program->cancelled_at !== null) {
            return;
        }

        try {
            $reconciler->reconcile($program, $this->trigger);
        } catch (\Throwable $e) {
            Log::error('program.alerts.reconcile_failed', [
                'program_id' => $this->programId,
                'trigger' => $this->trigger,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
