<?php

namespace App\Contracts\Repositories;

use App\Models\Technique;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

interface TechniqueRepositoryInterface
{
    public function paginateRoots(array $filters, int $perPage): LengthAwarePaginator;

    public function findByGuid(string $guid): ?Technique;

    public function findRootByGuid(string $guid): ?Technique;

    public function listAll(?string $type = null): Collection;

    public function create(array $data): Technique;

    public function update(Model $model, array $data): Model;

    public function destroy(Model $model): bool|null;

    public function createChild(Technique $parent, array $data): Technique;

    public function deleteChild(Technique $child): bool|null;

    /**
     * Prepara el sync de hijos sin ejecutarlo.
     * Retorna ['toDelete' => Collection, 'toUpdate' => Collection, 'toCreate' => Collection]
     */
    public function prepareSync(Technique $parent, array $childrenData): array;
}
