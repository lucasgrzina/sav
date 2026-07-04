<?php

namespace App\Services;

use App\Models\Permission;
use Illuminate\Support\Collection;

class PermissionService
{
    public function groupedByModule(): Collection
    {
        return Permission::orderBy('name')
            ->get()
            ->groupBy(fn($p) => explode('.', $p->name)[0])
            ->map(fn($perms, $module) => [
                'module'      => $module,
                'permissions' => $perms->values(),
            ])
            ->values();
    }
}
