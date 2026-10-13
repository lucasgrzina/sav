<?php

namespace App\Services\ProgramAlerts;

use App\Contracts\Repositories\ProgramRepositoryInterface;
use App\Models\Program;
use App\Models\ProgramAlertReconciliation;
use App\Services\ProgramAlerts\Data\ExistingAlertIndex;
use App\Services\ProgramAlerts\Data\ProjectionInput;
use App\Services\ProgramAlerts\Data\WarningMode;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Read model of the program "Avisos" tab: the lines of one page plus the context the tab needs
 * (counters, advisories in "Tab" mode, timezone, whether the persisted alerts drifted from the plan).
 * Read-only: nothing is written, queued or notified.
 */
final class ProgramAlertsService
{
    public function __construct(
        private readonly ProgramRepositoryInterface $programRepository,
        private readonly ProgramAlertLineQuery $lineQuery,
        private readonly ProgramAlertProjector $projector,
        private readonly ProgramAlertReconciler $reconciler,
        private readonly ProgramAlertWarningService $warningService,
    ) {}

    /** Tenant-scoped lookup; null when the program does not exist or belongs to another vet. */
    public function findProgram(string $guid, int $vetId): ?Program
    {
        return $this->programRepository->findByGuidForVet($guid, $vetId);
    }

    /**
     * @param array{status?: ?string, profile?: ?string, target?: ?string, type?: ?string} $filters
     * @return array{paginator: LengthAwarePaginator, meta: array<string, mixed>}
     */
    public function list(Program $program, int $vetId, array $filters, int $page, int $perPage): array
    {
        $now = CarbonImmutable::instance(now());
        $result = $this->lineQuery->paginate($program, $vetId, $filters, $page, $perPage, $now);

        $paginator = new LengthAwarePaginator($result['lines'], $result['total'], $perPage, $page);

        return [
            'paginator' => $paginator,
            'meta' => ['counters' => $result['counters']] + $this->meta($program, $now),
        ];
    }

    /** @return array<string, mixed> */
    private function meta(Program $program, CarbonImmutable $now): array
    {
        $program->loadMissing('vet.country');
        $active = $program->cancelled_at === null;
        $warnings = [];

        if ($active) {
            $input = ProjectionInput::fromProgram($program, $now);
            $warnings = array_map(
                fn ($warning) => $warning->toArray(),
                $this->warningService->build(
                    $input,
                    $this->projector->project($input),
                    WarningMode::Tab,
                    ExistingAlertIndex::forProgram($program),
                ),
            );
        }

        $lastReconciled = ProgramAlertReconciliation::where('program_id', $program->id)
            ->where('aborted', false)
            ->max('created_at');

        return [
            'warnings' => $warnings,
            'timezone' => $program->vet->timezone(),
            'out_of_sync' => $active && ! $this->reconciler->plan($program, $now)->isEmpty(),
            'multi_group' => $program->targets->count() > 1,
            'program_cancelled_at' => $program->cancelled_at?->toISOString(),
            'last_reconciled_at' => $lastReconciled !== null ? CarbonImmutable::parse($lastReconciled, 'UTC')->toISOString() : null,
        ];
    }
}
