<?php

namespace App\Policies;

use App\Models\Export;
use App\Models\User;

class ExportPolicy
{
    /**
     * Si puede iniciar una exportación (cualquier tipo).
     */
    public function create(User $user, string $exportType): bool
    {
        return $user->hasPermissionTo('exports.create');
    }

    /**
     * Si puede ver el estado de una exportación y descargarla.
     * Solo el dueño puede descargar su propia exportación.
     */
    public function download(User $user, Export $export): bool
    {
        return $user->id === $export->user_id;
    }

    /**
     * Si puede ver el listado de sus exportaciones.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('exports.create');
    }
}
