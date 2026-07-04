<?php

namespace App\Exports\Roles;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class RolesExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    public function __construct(
        private readonly Collection $roles,
        private readonly array $columns,
    ) {}

    public function collection(): Collection
    {
        return $this->roles;
    }

    public function headings(): array
    {
        return $this->filterColumns([
            'guid'              => 'ID',
            'name'              => 'Nombre',
            'type'              => 'Tipo',
            'permissions'       => 'Permisos',
            'permissions_count' => 'Cantidad de permisos',
            'created_at'        => 'Fecha de creación',
        ]);
    }

    public function map($role): array
    {
        $allColumns = [
            'guid'              => $role->guid,
            'name'              => $role->name,
            'type'              => $role->type,
            'permissions'       => $role->permissions->pluck('name')->join(', '),
            'permissions_count' => $role->permissions->count(),
            'created_at'        => $role->created_at?->format('d/m/Y H:i'),
        ];

        return array_values($this->filterColumns($allColumns));
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }

    private function filterColumns(array $all): array
    {
        if (empty($this->columns)) {
            return $all;
        }

        return array_intersect_key($all, array_flip($this->columns));
    }
}
