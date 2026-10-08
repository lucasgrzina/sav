<?php

namespace App\Contracts\Repositories;

use App\Models\Program;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

interface ProgramRepositoryInterface
{
    public function paginateForVet(int $vetId, array $filters, int $perPage): LengthAwarePaginator;

    public function findByGuidForVet(string $guid, int $vetId): ?Program;

    public function create(array $data): Program;

    public function update(Model $model, array $data): Model;

    /**
     * Returns the ids of client-type profiles (among $profileIds) that are NOT linked
     * to the given establishment. Vet-type profiles are never reported.
     *
     * @param  int[] $profileIds
     * @return int[]
     */
    public function unlinkedClientManagerIds(int $establishmentId, array $profileIds): array;

    /**
     * Detaches the given profiles as managers of the ACTIVE (not cancelled) programs of an
     * establishment. Returns the ids of the programs that actually lost at least one manager.
     *
     * @param  int[] $profileIds
     * @return int[]
     */
    public function detachManagersFromActivePrograms(int $establishmentId, array $profileIds): array;

    /**
     * Active programs that still have client-type managers NOT linked to the program's
     * establishment (invariant violations). Keyed by program id.
     *
     * @return array<int, array{establishment_id: int, profile_ids: int[]}>
     */
    public function findActiveProgramsWithUnlinkedClientManagers(): array;

    /**
     * Active programs whose PENDING program.task_due alerts still target profiles that are
     * no longer managers of the program (e.g. a regeneration that failed after the unlink).
     *
     * @return array<int, array{establishment_id: int, profile_ids: int[]}> keyed by program id
     */
    public function findActiveProgramsWithStaleAlertRecipients(): array;

    /**
     * @param int[] $profileIds
     */
    public function detachManagers(int $programId, array $profileIds): void;

    /** Program with the relations needed to regenerate its task_due alerts. */
    public function findForAlertRegeneration(int $programId): ?Program;
}
