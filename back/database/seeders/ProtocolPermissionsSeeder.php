<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ProtocolPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'protocols.read',
            'protocols.create',
            'protocols.update',
            'protocols.delete',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['guid' => Str::uuid()->toString()],
            );
        }

        $superAdmin = Role::where('name', 'super-admin')->first();
        if ($superAdmin) {
            $superAdmin->syncPermissions(Permission::all());
        }

        // Roles tenant: vet, vet-administrative y vet-assistant reciben el set completo
        // read/create/update/delete (DEC-10). givePermissionTo() es aditivo: no pisa
        // permisos de otros módulos (clients.*, tutorials.read, etc.) ya asignados por
        // RoleSeeder, que corre ANTES que este seeder.
        $vet = Role::where('name', 'vet')->first();
        $vet?->givePermissionTo(Permission::whereIn('name', $permissions)->get());

        $vetAdmin = Role::where('name', 'vet-administrative')->first();
        $vetAdmin?->givePermissionTo(Permission::whereIn('name', $permissions)->get());

        $vetAssistant = Role::where('name', 'vet-assistant')->first();
        $vetAssistant?->givePermissionTo(Permission::whereIn('name', $permissions)->get());
    }
}
