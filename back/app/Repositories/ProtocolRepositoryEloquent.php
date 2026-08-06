<?php

namespace App\Repositories;

use App\Contracts\Repositories\ProtocolRepositoryInterface;
use App\Models\Protocol;
use App\Models\Technique;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ProtocolRepositoryEloquent extends BaseRepositoryEloquent implements ProtocolRepositoryInterface
{
    protected function model(): string
    {
        return Protocol::class;
    }

    public function paginateByRootTechnique(Technique $root, array $filters, int $perPage): LengthAwarePaginator
    {
        $childIds = $root->children()->pluck('id');

        $query = $this->newQuery()
            ->whereIn('technique_id', $childIds)
            ->whereNull('vet_id') // capa plantilla: NUNCA protocolos propios de una vet (ver DEC-08)
            ->with('technique:id,guid,name', 'country:id,guid,name')
            ->withCount('tasks');

        if (!empty($filters['technique_guid'])) {
            $query->whereHas('technique', fn ($q) => $q->where('guid', $filters['technique_guid']));
        }
        if (array_key_exists('country_guid', $filters)) {
            $filters['country_guid'] === null
                ? $query->whereNull('country_id')
                : $query->whereHas('country', fn ($q) => $q->where('guid', $filters['country_guid']));
        }
        if (!empty($filters['search'])) {
            $query->where('name', 'like', '%' . $filters['search'] . '%');
        }

        return $query->orderBy('name')->paginate($perPage);
    }

    public function paginateForVet(int $vetId, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = $this->newQuery()
            ->where(fn ($q) => $q->whereNull('vet_id')->orWhere('vet_id', $vetId))
            ->with('technique:id,guid,name')
            ->withCount('tasks');

        if (!empty($filters['technique_guid'])) {
            $query->whereHas('technique', fn ($q) => $q->where('guid', $filters['technique_guid']));
        }
        if (!empty($filters['search'])) {
            $query->where('name', 'like', '%' . $filters['search'] . '%');
        }

        return $query->orderBy('name')->paginate($perPage);
    }

    public function findByGuid(string $guid): ?Protocol
    {
        /** @var Protocol|null */
        return $this->newQuery()->where('guid', $guid)->first();
    }

    public function findByGuidWithTasks(string $guid): ?Protocol
    {
        /** @var Protocol|null */
        return $this->newQuery()->with(['technique', 'country', 'tasks.alerts'])->where('guid', $guid)->first();
    }

    public function findByGuidForVet(string $guid, int $vetId): ?Protocol
    {
        /** @var Protocol|null */
        return $this->newQuery()
            ->with(['technique', 'tasks.alerts'])
            ->where('guid', $guid)
            ->where('vet_id', $vetId) // scope estricto: nunca resuelve protocolos globales ni de otra vet
            ->first();
    }

    public function create(array $data): Protocol
    {
        /** @var Protocol */
        return parent::create($data);
    }

    public function existsDuplicate(int $techniqueId, ?int $countryId, string $name, ?int $vetId = null, ?string $excludeGuid = null): bool
    {
        $query = $this->newQuery()->where('technique_id', $techniqueId)->where('name', $name);

        $countryId === null ? $query->whereNull('country_id') : $query->where('country_id', $countryId);
        $vetId === null ? $query->whereNull('vet_id') : $query->where('vet_id', $vetId);

        if ($excludeGuid) {
            $query->where('guid', '!=', $excludeGuid);
        }

        return $query->exists();
    }
}
