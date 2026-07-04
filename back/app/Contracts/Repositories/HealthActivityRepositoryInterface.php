<?php

namespace App\Contracts\Repositories;

use App\Models\HealthActivity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

interface HealthActivityRepositoryInterface
{
    public function paginate(array $filters, int $perPage): LengthAwarePaginator;
    public function findByGuid(string $guid): ?HealthActivity;
    public function findByGuidOrFail(string $guid): HealthActivity;
    public function resolveGuidsToIds(array $guids): array;  // ['guid' => id, ...]
    public function listAll(): Collection;
    public function create(array $data): HealthActivity;
    public function update(Model $model, array $data): Model;
    public function destroy(Model $model): bool|null;
    public function isUsedInTemplates(HealthActivity $activity): bool;
}
