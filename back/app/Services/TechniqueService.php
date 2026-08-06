<?php

namespace App\Services;

use App\Contracts\Repositories\TechniqueRepositoryInterface;
use App\Exceptions\TechniqueCannotBeDeletedException;
use App\Exceptions\TechniqueChildHasProgramsException;
use App\Models\Protocol;
use App\Models\Technique;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class TechniqueService
{
    public function __construct(
        private TechniqueRepositoryInterface $techniqueRepository,
    ) {}

    /**
     * Lista paginada de raíces para el panel admin.
     */
    public function paginateRoots(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->techniqueRepository->paginateRoots($filters, $perPage);
    }

    /**
     * Crea una técnica raíz y sus hijos dentro de una transacción.
     *
     * @param  array  $data  {name, type, target_date_name?, protocols_name?, children: [{name, protocols_name?}]}
     */
    public function create(array $data): Technique
    {
        return DB::transaction(function () use ($data) {
            $children = $data['children'] ?? [];
            unset($data['children']);

            $technique = $this->techniqueRepository->create($data);

            foreach ($children as $childData) {
                $this->techniqueRepository->createChild($technique, $childData);
            }

            return $technique->load('children');
        });
    }

    /**
     * Busca una técnica raíz por guid.
     */
    public function findRootByGuid(string $guid): ?Technique
    {
        return $this->techniqueRepository->findRootByGuid($guid);
    }

    /**
     * Carga el detalle de una raíz: children + programs paginados del árbol.
     */
    public function getDetail(Technique $technique, int $programsPage = 1, int $programsPerPage = 15): array
    {
        $technique->load('children');

        // STUB: Programas del árbol (raíz + hijos). Retorna paginación vacía hasta que
        // el módulo de programas exista. El dev de programas reemplaza esta lógica.
        $programs = [
            'data'         => [],
            'current_page' => 1,
            'last_page'    => 1,
            'per_page'     => $programsPerPage,
            'total'        => 0,
        ];

        return compact('technique', 'programs');
    }

    /**
     * Actualiza una técnica raíz y sincroniza sus hijos.
     * Valida que ningún hijo a eliminar tenga programas vinculados antes de ejecutar.
     *
     * @throws TechniqueChildHasProgramsException si algún hijo tiene programas
     */
    public function update(Technique $technique, array $data): Technique
    {
        return DB::transaction(function () use ($technique, $data) {
            $childrenData = $data['children'] ?? [];
            unset($data['children']);

            // Actualizar campos raíz
            $this->techniqueRepository->update($technique, $data);

            // Preparar sync sin ejecutarlo todavía
            $sync = $this->techniqueRepository->prepareSync($technique, $childrenData);

            // Validar que los hijos a eliminar no tengan programas
            $conflicts = [];
            foreach ($sync['toDelete'] as $child) {
                $count = $this->countProgramsForTechnique($child);
                if ($count > 0) {
                    $conflicts[] = [
                        'guid'           => $child->guid,
                        'name'           => $child->name,
                        'programs_count' => $count,
                    ];
                }
            }

            if (!empty($conflicts)) {
                throw new TechniqueChildHasProgramsException($conflicts);
            }

            // Eliminar hijos que ya no están en la lista
            foreach ($sync['toDelete'] as $child) {
                $this->techniqueRepository->deleteChild($child);
            }

            // Actualizar hijos existentes
            foreach ($sync['toUpdate'] as $item) {
                $this->techniqueRepository->update($item['model'], [
                    'name'           => $item['data']['name'],
                    'protocols_name' => $item['data']['protocols_name'] ?? null,
                ]);
            }

            // Crear nuevos hijos
            foreach ($sync['toCreate'] as $childData) {
                $this->techniqueRepository->createChild($technique, (array) $childData);
            }

            return $technique->fresh()->load('children');
        });
    }

    /**
     * Elimina una técnica raíz con validación completa.
     *
     * @throws TechniqueCannotBeDeletedException
     */
    public function destroy(Technique $technique): void
    {
        $technique->load('children');

        // 1. Verificar si tiene hijos con programas
        foreach ($technique->children as $child) {
            $count = $this->countProgramsForTechnique($child);
            if ($count > 0) {
                throw new TechniqueCannotBeDeletedException(
                    reason: 'children_have_programs',
                    count: $count,
                    message: 'La técnica tiene sub-técnicas con programas vinculados.',
                );
            }
        }

        // 2. Verificar si la raíz tiene protocolos directos
        $protocolCount = $this->countProtocolsForTechnique($technique);
        if ($protocolCount > 0) {
            throw new TechniqueCannotBeDeletedException(
                reason: 'has_protocols',
                count: $protocolCount,
                message: 'La técnica tiene protocolos vinculados.',
            );
        }

        // 3. Verificar si tiene programas directos
        $programCount = $this->countProgramsForTechnique($technique);
        if ($programCount > 0) {
            throw new TechniqueCannotBeDeletedException(
                reason: 'has_programs',
                count: $programCount,
                message: 'La técnica tiene programas vinculados.',
            );
        }

        DB::transaction(function () use ($technique) {
            // Eliminar hijos primero (la FK es nullable, no cascade)
            foreach ($technique->children as $child) {
                $this->techniqueRepository->deleteChild($child);
            }
            $this->techniqueRepository->destroy($technique);
        });
    }

    /**
     * Lista todas las técnicas para la API del panel vet (jerarquía completa).
     */
    public function listForApi(?string $type = null): Collection
    {
        return $this->techniqueRepository->listAll($type);
    }

    /**
     * STUB: Retorna cantidad de programas vinculados a una técnica.
     * Cuando el módulo de programas exista, reemplazar con:
     *   return Program::where('technique_id', $technique->id)->count();
     * (o la query correspondiente según el modelo de Program).
     */
    private function countProgramsForTechnique(Technique $technique): int
    {
        // TODO: implementar cuando exista el modelo Program
        return 0;
    }

    /**
     * Retorna cantidad de protocolos vinculados directamente a una técnica.
     */
    private function countProtocolsForTechnique(Technique $technique): int
    {
        return Protocol::where('technique_id', $technique->id)->count();
    }
}
