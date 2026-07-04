<?php

namespace App\Exports\Users;

use App\Models\User;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class UsersExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    public function __construct(
        private readonly Collection $users,
        private readonly array $columns,  // columnas seleccionadas (vacío = todas)
    ) {}

    public function collection(): Collection
    {
        return $this->users;
    }

    public function headings(): array
    {
        return $this->filterColumns([
            'guid'          => 'ID',
            'first_name'    => 'Nombre',
            'last_name'     => 'Apellido',
            'email'         => 'Email',
            'status'        => 'Estado',
            'last_login_at' => 'Último login',
            'created_at'    => 'Fecha de creación',
        ]);
    }

    public function map($user): array
    {
        $allColumns = [
            'guid'          => $user->guid,
            'first_name'    => $user->first_name,
            'last_name'     => $user->last_name,
            'email'         => $user->email,
            'status'        => $user->locked_at
                                ? 'Bloqueado'
                                : ($user->email_verified_at ? 'Verificado' : 'No verificado'),
            'last_login_at' => $user->last_login_at?->format('d/m/Y H:i'),
            'created_at'    => $user->created_at?->format('d/m/Y H:i'),
        ];

        return array_values($this->filterColumns($allColumns));
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }

    // Retorna solo las columnas seleccionadas (o todas si $this->columns está vacío)
    private function filterColumns(array $all): array
    {
        if (empty($this->columns)) {
            return $all;
        }

        return array_intersect_key($all, array_flip($this->columns));
    }
}
