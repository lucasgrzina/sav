<?php

namespace App\Repositories;

use App\Contracts\Repositories\HealthPlanCategoryRepositoryInterface;
use App\Models\HealthPlanCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class HealthPlanCategoryRepositoryEloquent extends BaseRepositoryEloquent
    implements HealthPlanCategoryRepositoryInterface
{
    protected function model(): string { return HealthPlanCategory::class; }

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = $this->newQuery()->withCount('templates');
        if (!empty($filters['search'])) {
            $query->where('name', 'like', '%' . $filters['search'] . '%');
        }
        return $query->orderBy('name')->paginate($perPage);
    }

    public function findByGuid(string $guid): ?HealthPlanCategory
    {
        /** @var HealthPlanCategory|null */
        return $this->newQuery()->where('guid', $guid)->first();
    }

    public function listAll(): Collection
    {
        return $this->newQuery()->orderBy('name')->get();
    }

    public function create(array $data): HealthPlanCategory
    {
        /** @var HealthPlanCategory */
        return parent::create($data);
    }

    public function hasTemplates(HealthPlanCategory $category): bool
    {
        return $category->templates()->exists();
    }
}
