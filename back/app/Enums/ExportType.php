<?php

namespace App\Enums;

enum ExportType: string
{
    case USERS = 'users';
    case ROLES = 'roles';
    case PROGRAM = 'program';

    public function label(): string
    {
        return match ($this) {
            self::USERS => 'Usuarios',
            self::ROLES => 'Roles',
            self::PROGRAM => 'Programa (PDF)',
        };
    }
}
