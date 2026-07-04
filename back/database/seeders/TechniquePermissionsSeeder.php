<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TechniquePermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'techniques.read',
            'techniques.create',
            'techniques.update',
            'techniques.delete',
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
    }
}
