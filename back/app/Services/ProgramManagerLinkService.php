<?php

namespace App\Services;

use App\Contracts\Repositories\ProgramRepositoryInterface;
use App\Events\ProgramTargetsChangedEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps the invariant "client manager => linked to the program's establishment".
 * Alert regeneration is isolated per program: a failure is logged and never aborts the others.
 */
class ProgramManagerLinkService
{
    public function __construct(private ProgramRepositoryInterface $programRepository) {}

    /**
     * Deletes and recreates the pending task_due alerts of one program atomically.
     * Returns false (after logging) when the regeneration failed and was rolled back.
     *
     * @param int[] $profileIds profiles that triggered the regeneration (log context only)
     */
    public function regenerateAlerts(int $programId, int $establishmentId, array $profileIds): bool
    {
        try {
            DB::transaction(function () use ($programId) {
                $program = $this->programRepository->findForAlertRegeneration($programId);

                if ($program !== null) {
                    event(new ProgramTargetsChangedEvent($program));
                }
            });

            return true;
        } catch (Throwable $e) {
            Log::error('Failed to regenerate program alerts after unlinking client staff', [
                'establishment_id' => $establishmentId,
                'program_id'       => $programId,
                'profile_ids'      => $profileIds,
                'error'            => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Idempotent repair. Two kinds of drift are fixed:
     *  - client managers not linked to the establishment of their (active) program: detached;
     *  - pending task_due alerts still addressed to profiles that are no longer managers
     *    (e.g. a regeneration that failed after the unlink): regenerated.
     * Alerts of every touched program are regenerated.
     *
     * @return array<int, array{establishment_id: int, profile_ids: int[], regenerated: bool|null}>
     *         keyed by program id; regenerated is null on dry run
     */
    public function reconcileUnlinkedManagers(bool $dryRun = false): array
    {
        $unlinked = $this->programRepository->findActiveProgramsWithUnlinkedClientManagers();
        $stale    = $this->programRepository->findActiveProgramsWithStaleAlertRecipients();
        $report   = [];

        foreach ($unlinked + $stale as $programId => $info) {
            $profileIds = array_values(array_unique(array_merge(
                $unlinked[$programId]['profile_ids'] ?? [],
                $stale[$programId]['profile_ids'] ?? [],
            )));
            $regenerated = null;

            if (!$dryRun) {
                if (isset($unlinked[$programId])) {
                    DB::transaction(fn () => $this->programRepository->detachManagers($programId, $unlinked[$programId]['profile_ids']));
                }
                $regenerated = $this->regenerateAlerts($programId, $info['establishment_id'], $profileIds);

                Log::info('Reconciled unlinked client managers', [
                    'establishment_id' => $info['establishment_id'],
                    'program_id'       => $programId,
                    'profile_ids'      => $profileIds,
                    'regenerated'      => $regenerated,
                ]);
            }

            $report[$programId] = [
                'establishment_id' => $info['establishment_id'],
                'profile_ids'      => $profileIds,
                'regenerated'      => $regenerated,
            ];
        }

        return $report;
    }
}
