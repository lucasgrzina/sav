<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ProgramPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'programs.read',
            'programs.create',
            'programs.update',
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

        // Roles tenant: vet, vet-administrative y vet-assistant reciben el set completo.
        // givePermissionTo() es aditivo: no pisa permisos de otros módulos ya asignados.
        $vet = Role::where('name', 'vet')->first();
        $vet?->givePermissionTo(Permission::whereIn('name', $permissions)->get());

        $vetAdmin = Role::where('name', 'vet-administrative')->first();
        $vetAdmin?->givePermissionTo(Permission::whereIn('name', $permissions)->get());

        $vetAssistant = Role::where('name', 'vet-assistant')->first();
        $vetAssistant?->givePermissionTo(Permission::whereIn('name', $permissions)->get());
    }
}
