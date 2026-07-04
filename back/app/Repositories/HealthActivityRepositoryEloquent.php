<?php

namespace App\Repositories;

use App\Contracts\Repositories\HealthActivityRepositoryInterface;
use App\Models\HealthActivity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class HealthActivityRepositoryEloquent extends BaseRepositoryEloquent
    implements HealthActivityRepositoryInterface
{
    protected function model(): string { return HealthActivity::class; }

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = $this->newQuery();
        if (!empty($filters['search'])) {
            $query->where('name', 'like', '%' . $filters['search'] . '%');
        }
        return $query->orderBy('name')->paginate($perPage);
    }

    public function findByGuid(string $guid): ?HealthActivity
    {
        /** @var HealthActivity|null */
        return $this->newQuery()->where('guid', $guid)->first();
    }

    public function findByGuidOrFail(string $guid): HealthActivity
    {
        /** @var HealthActivity */
        return $this->newQuery()->where('guid', $guid)->firstOrFail();
    }

    /**
     * Recibe array de guids, devuelve map ['guid' => id].
     * Los guids no encontrados no aparecen en el resultado.
     */
    public function resolveGuidsToIds(array $guids): array
    {
        return $this->newQuery()
            ->whereIn('guid', $guids)
            ->pluck('id', 'guid')
            ->all();
    }

    public function listAll(): Collection
    {
        return $this->newQuery()->orderBy('name')->get();
    }

    public function create(array $data): HealthActivity
    {
        /** @var HealthActivity */
        return parent::create($data);
    }

    public function isUsedInTemplates(HealthActivity $activity): bool
    {
        return $activity->templates()->exists();
    }
}
