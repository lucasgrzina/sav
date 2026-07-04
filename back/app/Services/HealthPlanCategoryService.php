<?php

namespace App\Services;

use App\Contracts\Repositories\HealthPlanCategoryRepositoryInterface;
use App\Models\HealthPlanCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class HealthPlanCategoryService
{
    public function __construct(
        private HealthPlanCategoryRepositoryInterface $repo,
    ) {}

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->repo->paginate($filters, $perPage);
    }

    public function listAll(): Collection
    {
        return $this->repo->listAll();
    }

    public function findByGuid(string $guid): ?HealthPlanCategory
    {
        return $this->repo->findByGuid($guid);
    }

    public function create(array $data): HealthPlanCategory
    {
        return $this->repo->create([
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
        ]);
    }

    public function update(HealthPlanCategory $category, array $data): HealthPlanCategory
    {
        /** @var HealthPlanCategory */
        return $this->repo->update($category, [
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
        ]);
    }

    /**
     * @throws \RuntimeException si la categoría tiene plantillas asociadas
     */
    public function destroy(HealthPlanCategory $category): void
    {
        if ($this->repo->hasTemplates($category)) {
            throw new \RuntimeException(
                'La categoría tiene plantillas asociadas y no puede eliminarse.'
            );
        }
        $this->repo->destroy($category);
    }
}
