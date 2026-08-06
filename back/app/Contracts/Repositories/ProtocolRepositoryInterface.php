<?php

namespace App\Contracts\Repositories;

use App\Models\Protocol;
use App\Models\Technique;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

interface ProtocolRepositoryInterface
{
    public function paginateByRootTechnique(Technique $root, array $filters, int $perPage): LengthAwarePaginator;

    public function paginateForVet(int $vetId, array $filters, int $perPage): LengthAwarePaginator;

    public function findByGuid(string $guid): ?Protocol;

    public function findByGuidWithTasks(string $guid): ?Protocol;

    public function findByGuidForVet(string $guid, int $vetId): ?Protocol;

    public function create(array $data): Protocol;

    public function update(Model $model, array $data): Model;

    public function destroy(Model $model): bool|null;

    public function existsDuplicate(int $techniqueId, ?int $countryId, string $name, ?int $vetId = null, ?string $excludeGuid = null): bool;
}
