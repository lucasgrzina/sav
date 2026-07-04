<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $superAdmin = Role::firstOrCreate(
            ['name' => 'super-admin', 'guard_name' => 'web'],
            ['guid' => Str::uuid()->toString(), 'type' => Role::TYPE_PLATFORM],
        );

        $admin = Role::firstOrCreate(
            ['name' => 'admin', 'guard_name' => 'web'],
            ['guid' => Str::uuid()->toString(), 'type' => Role::TYPE_PLATFORM],
        );

        $operador = Role::firstOrCreate(
            ['name' => 'operador', 'guard_name' => 'web'],
            ['guid' => Str::uuid()->toString(), 'type' => Role::TYPE_PLATFORM],
        );

        $superAdmin->syncPermissions(Permission::all());

        $admin->syncPermissions(Permission::whereIn('name', [
            'users.read',
            'users.create',
            'users.update',
            'roles.read',
            'exports.create',
        ])->get());

        $operador->syncPermissions(Permission::whereIn('name', [
            'users.read',
        ])->get());

        $tenantRoleNames = [
            'vet',
            'vet-assistant',
            'vet-administrative',
            'client-owner',
            'client-manager',
            'client-administrative',
        ];

        foreach ($tenantRoleNames as $roleName) {
            Role::firstOrCreate(
                ['name' => $roleName, 'guard_name' => 'web'],
                ['guid' => Str::uuid()->toString(), 'type' => Role::TYPE_TENANT],
            );
        }

        // Backfill: corregir roles tenant que quedaron con default 'platform' por la migration
        Role::whereIn('name', $tenantRoleNames)
            ->where('type', Role::TYPE_PLATFORM)
            ->update(['type' => Role::TYPE_TENANT]);

        // Permisos de roles tenant (resueltos por Gate::before en AppServiceProvider)
        $allClientPerms = [
            'clients.read', 'clients.create', 'clients.update', 'clients.delete',
            'clients.owners.read', 'clients.owners.create',
            'clients.staff.read', 'clients.staff.create', 'clients.staff.update', 'clients.staff.delete',
            'establishments.read', 'establishments.create', 'establishments.update', 'establishments.delete',
        ];

        Role::where('name', 'vet')->first()
            ?->syncPermissions(Permission::whereIn('name', [...$allClientPerms, 'tutorials.read'])->get());

        Role::where('name', 'vet-administrative')->first()
            ?->syncPermissions(Permission::whereIn('name', [...$allClientPerms, 'tutorials.read'])->get());

        Role::where('name', 'vet-assistant')->first()
            ?->syncPermissions(Permission::whereIn('name', [
                'clients.read',
                'establishments.read',
                'tutorials.read',
            ])->get());

        Role::where('name', 'client-owner')->first()
            ?->syncPermissions(Permission::whereIn('name', [
                'clients.read',
                'clients.owners.read',
                'clients.staff.read', 'clients.staff.create', 'clients.staff.update', 'clients.staff.delete',
                'establishments.read',
            ])->get());

        Role::where('name', 'client-manager')->first()
            ?->syncPermissions(Permission::whereIn('name', [
                'clients.read',
                'clients.staff.read',
                'establishments.read',
            ])->get());

        Role::where('name', 'client-administrative')->first()
            ?->syncPermissions(Permission::whereIn('name', [
                'clients.read',
                'establishments.read',
            ])->get());
    }
}
