<?php

namespace App\Exports\Users;

use App\Contracts\Exports\ExporterInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use Illuminate\Support\Facades\Storage;

class UsersTxtExporter implements ExporterInterface
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
    ) {}

    public function export(array $filters, array $columns, string $filePath): string
    {
        $users = $this->userRepository->exportQuery($filters)->get();

        $allHeaders   = ['guid', 'first_name', 'last_name', 'email', 'status', 'last_login_at', 'created_at'];
        $activeHeaders = empty($columns) ? $allHeaders : array_intersect($allHeaders, $columns);

        $lines   = [];
        $lines[] = implode("\t", $activeHeaders);

        foreach ($users as $user) {
            $row = array_map(fn ($col) => match ($col) {
                'status'        => $user->locked_at
                                    ? 'Bloqueado'
                                    : ($user->email_verified_at ? 'Verificado' : 'No verificado'),
                'last_login_at' => $user->last_login_at?->format('d/m/Y H:i') ?? '',
                'created_at'    => $user->created_at?->format('d/m/Y H:i') ?? '',
                default         => $user->{$col} ?? '',
            }, $activeHeaders);
            $lines[] = implode("\t", $row);
        }

        Storage::disk('local')->put($filePath, implode(PHP_EOL, $lines));

        return $filePath;
    }

    public function getExtension(): string { return 'txt'; }
    public function getMimeType(): string  { return 'text/plain'; }
}
