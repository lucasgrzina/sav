<?php

namespace App\Exports\Roles;

use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Exports\Pdf\BasePdfExporter;
use Illuminate\Support\Collection;

class RolesPdfExporter extends BasePdfExporter
{
    public function __construct(
        private readonly RoleRepositoryInterface $roleRepository,
    ) {}

    protected function title(): string
    {
        return 'Listado de Roles';
    }

    protected function allColumnDefinitions(): array
    {
        return [
            'guid'              => 'ID',
            'name'              => 'Nombre',
            'type'              => 'Tipo',
            'permissions'       => 'Permisos',
            'permissions_count' => 'Cantidad de permisos',
            'created_at'        => 'Fecha de creación',
        ];
    }

    protected function fetchData(array $filters): Collection
    {
        return $this->roleRepository->exportQuery($filters)->get();
    }

    protected function mapRow(mixed $role, array $activeKeys): array
    {
        $all = [
            'guid'              => $role->guid,
            'name'              => $role->name,
            'type'              => $role->type,
            'permissions'       => $role->permissions->pluck('name')->join(', '),
            'permissions_count' => $role->permissions->count(),
            'created_at'        => $role->created_at?->format('d/m/Y H:i') ?? '-',
        ];

        return array_map(fn (string $key) => $all[$key] ?? '-', $activeKeys);
    }
}
