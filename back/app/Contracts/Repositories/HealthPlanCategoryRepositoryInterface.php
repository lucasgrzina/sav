<?php

namespace App\Contracts\Repositories;

use App\Models\HealthPlanCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

interface HealthPlanCategoryRepositoryInterface
{
    public function paginate(array $filters, int $perPage): LengthAwarePaginator;
    public function findByGuid(string $guid): ?HealthPlanCategory;
    public function listAll(): Collection;
    public function create(array $data): HealthPlanCategory;
    public function update(Model $model, array $data): Model;
    public function destroy(Model $model): bool|null;
    public function hasTemplates(HealthPlanCategory $category): bool;
}
