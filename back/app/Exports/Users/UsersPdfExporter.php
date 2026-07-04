<?php

namespace App\Exports\Users;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Exports\Pdf\BasePdfExporter;
use Illuminate\Support\Collection;

class UsersPdfExporter extends BasePdfExporter
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
    ) {}

    protected function title(): string
    {
        return 'Listado de Usuarios';
    }

    protected function allColumnDefinitions(): array
    {
        return [
            'guid'          => 'ID',
            'first_name'    => 'Nombre',
            'last_name'     => 'Apellido',
            'email'         => 'Email',
            'status'        => 'Estado',
            'last_login_at' => 'Último login',
            'created_at'    => 'Fecha de creación',
        ];
    }

    protected function fetchData(array $filters): Collection
    {
        return $this->userRepository->exportQuery($filters)->get();
    }

    protected function mapRow(mixed $user, array $activeKeys): array
    {
        $all = [
            'guid'          => $user->guid,
            'first_name'    => $user->first_name,
            'last_name'     => $user->last_name,
            'email'         => $user->email,
            'status'        => $user->locked_at
                                ? 'Bloqueado'
                                : ($user->email_verified_at ? 'Verificado' : 'No verificado'),
            'last_login_at' => $user->last_login_at?->format('d/m/Y H:i') ?? '-',
            'created_at'    => $user->created_at?->format('d/m/Y H:i') ?? '-',
        ];

        return array_map(fn (string $key) => $all[$key] ?? '-', $activeKeys);
    }

    // view() no se sobreescribe → usa 'exports.generic' por defecto.
}
