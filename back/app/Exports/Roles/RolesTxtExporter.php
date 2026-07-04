<?php

namespace App\Exports\Roles;

use App\Contracts\Exports\ExporterInterface;
use App\Contracts\Repositories\RoleRepositoryInterface;
use Illuminate\Support\Facades\Storage;

class RolesTxtExporter implements ExporterInterface
{
    public function __construct(
        private readonly RoleRepositoryInterface $roleRepository,
    ) {}

    public function export(array $filters, array $columns, string $filePath): string
    {
        $roles = $this->roleRepository->exportQuery($filters)->get();

        $allHeaders    = ['guid', 'name', 'type', 'permissions', 'permissions_count', 'created_at'];
        $activeHeaders = empty($columns) ? $allHeaders : array_intersect($allHeaders, $columns);

        $lines   = [];
        $lines[] = implode("\t", $activeHeaders);

        foreach ($roles as $role) {
            $row = array_map(fn ($col) => match ($col) {
                'permissions'       => $role->permissions->pluck('name')->join(', '),
                'permissions_count' => $role->permissions->count(),
                'created_at'        => $role->created_at?->format('d/m/Y H:i') ?? '',
                default             => $role->{$col} ?? '',
            }, $activeHeaders);

            $lines[] = implode("\t", $row);
        }

        Storage::disk('local')->put($filePath, implode(PHP_EOL, $lines));

        return $filePath;
    }

    public function getExtension(): string { return 'txt'; }
    public function getMimeType(): string  { return 'text/plain'; }
}
