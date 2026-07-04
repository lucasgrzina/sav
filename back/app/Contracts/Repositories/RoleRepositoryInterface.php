<?php

namespace App\Contracts\Repositories;

use App\Contracts\QueryCriterion;
use App\Models\Role;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

interface RoleRepositoryInterface
{
    public function findByGuid(string $guid): ?Role;
    public function findManyByGuids(array $guids): Collection;
    public function list(int $perPage, QueryCriterion ...$criteria): LengthAwarePaginator;
    public function create(array $data): Role;
    public function update(Model $model, array $data): Role;
    public function destroy(Model $model): bool|null;
    public function exportQuery(QueryCriterion ...$criteria): Builder;

    /**
     * Busca un rol por nombre exacto. Retorna null si no existe.
     */
    public function findByName(string $name): ?Role;
}
