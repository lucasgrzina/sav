<?php

namespace App\Repositories;

use App\Contracts\Repositories\TechniqueRepositoryInterface;
use App\Models\Technique;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class TechniqueRepositoryEloquent extends BaseRepositoryEloquent implements TechniqueRepositoryInterface
{
    protected function model(): string
    {
        return Technique::class;
    }

    /**
     * Lista paginada de técnicas RAÍZ (parent_id IS NULL) con children_count.
     * Filtros aceptados: 'search' (nombre), 'type' ('technique'|'vaccine').
     */
    public function paginateRoots(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = $this->newQuery()
            ->whereNull('parent_id')
            ->withCount('children');

        if (!empty($filters['search'])) {
            $query->where('name', 'like', '%' . $filters['search'] . '%');
        }

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        return $query->orderBy('name')->paginate($perPage);
    }

    /**
     * Busca por guid sin restricción de raíz/hijo.
     */
    public function findByGuid(string $guid): ?Technique
    {
        /** @var Technique|null */
        return $this->newQuery()->where('guid', $guid)->first();
    }

    /**
     * Busca solo raíces por guid.
     */
    public function findRootByGuid(string $guid): ?Technique
    {
        /** @var Technique|null */
        return $this->newQuery()
            ->whereNull('parent_id')
            ->where('guid', $guid)
            ->first();
    }

    /**
     * Lista todas las técnicas para la API (filtro por type opcional).
     * Retorna solo raíces con sus hijos eager-loaded.
     */
    public function listAll(?string $type = null): Collection
    {
        $query = $this->newQuery()
            ->whereNull('parent_id')
            ->with(['children' => function ($q) {
                $q->orderBy('name');
            }])
            ->orderBy('name');

        if ($type) {
            $query->where('type', $type);
        }

        return $query->get();
    }

    public function create(array $data): Technique
    {
        /** @var Technique */
        return parent::create($data);
    }

    /**
     * Crea un hijo heredando el type del padre.
     */
    public function createChild(Technique $parent, array $data): Technique
    {
        /** @var Technique */
        return $this->newQuery()->create([
            'name'           => $data['name'],
            'protocols_name' => $data['protocols_name'] ?? null,
            'parent_id'      => $parent->id,
            'type'           => $parent->type,
        ]);
    }

    public function deleteChild(Technique $child): bool|null
    {
        return $child->delete();
    }

    /**
     * Prepara el sync de hijos de una técnica raíz sin ejecutarlo.
     *
     * - Hijos con 'guid' existente en la lista: marcados para actualizar.
     * - Hijos existentes cuyo guid NO aparece en la lista: marcados para eliminar.
     * - Elementos sin 'guid': marcados para crear.
     *
     * @param  array  $childrenData  Array de ['guid?' => string, 'name' => string, 'protocols_name' => string|null]
     * @return array{toDelete: Collection, toUpdate: \Illuminate\Support\Collection, toCreate: \Illuminate\Support\Collection}
     */
    public function prepareSync(Technique $parent, array $childrenData): array
    {
        $existingChildren = $parent->children()->get()->keyBy('guid');
        $incomingGuids    = collect($childrenData)->pluck('guid')->filter()->values();

        $toDelete = $existingChildren->whereNotIn('guid', $incomingGuids->all());

        $toUpdate = collect($childrenData)
            ->filter(fn($c) => isset($c['guid']))
            ->map(fn($c) => ['model' => $existingChildren->get($c['guid']), 'data' => $c])
            ->filter(fn($c) => $c['model'] !== null);

        $toCreate = collect($childrenData)->filter(fn($c) => !isset($c['guid']));

        return compact('toDelete', 'toUpdate', 'toCreate');
    }
}
