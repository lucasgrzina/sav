<?php

namespace App\Contracts\Repositories;

use App\Models\Client;
use App\Models\Establishment;
use App\Models\UserProfile;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

interface EstablishmentRepositoryInterface
{
    public function findByGuidForClient(string $guid, Client $client): ?Establishment;

    /**
     * @param bool $withStaff load linked staff (personal data). When false only `staff_count` is available.
     */
    public function listForClient(Client $client, bool $withStaff = true): Collection;

    public function create(array $data): Establishment;

    public function update(Model $establishment, array $data): Establishment;

    public function destroy(Model $establishment): bool|null;

    /**
     * Syncs the linked staff (final desired state, internal profile ids).
     *
     * @param int[] $profileIds
     * @return array{attached: int[], detached: int[], updated: int[]}
     */
    public function syncStaff(Establishment $establishment, array $profileIds): array;

    public function hasStaff(Establishment $establishment, UserProfile $profile): bool;

    /**
     * Linked staff that is not blocked, with `user` and `role` loaded (program manager options).
     *
     * @return Collection<int, UserProfile>
     */
    public function listActiveStaff(Establishment $establishment): Collection;
}
