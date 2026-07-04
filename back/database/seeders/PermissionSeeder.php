<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'users.read',
            'users.create',
            'users.update',
            'users.delete',
            'roles.read',
            'roles.create',
            'roles.update',
            'roles.delete',
            'exports.create',
            'support-messages.read',
            'support-messages.create',
            'support-messages.update',
            'support-messages.delete',
            'support-messages.reply',
            'support-messages.close',
            'system-settings.manage',
            'vets.read',
            'vets.create',
            'vets.update',
            'vets.delete',
            'vets.validate',
            'vets.staff.read',
            'vets.staff.create',
            'vets.staff.update',
            'vets.staff.delete',
            'clients.read',
            'clients.create',
            'clients.update',
            'clients.delete',
            'establishments.read',
            'establishments.create',
            'establishments.update',
            'establishments.delete',
            'clients.owners.read',
            'clients.owners.create',
            'clients.staff.read',
            'clients.staff.create',
            'clients.staff.update',
            'clients.staff.delete',
            'tutorials.read',
            'tutorials.create',
            'tutorials.update',
            'tutorials.delete',
            'techniques.read',
            'techniques.create',
            'techniques.update',
            'techniques.delete',
            // Health Activities
            'health-activities.read',
            'health-activities.create',
            'health-activities.update',
            'health-activities.delete',
            // Health Plan Categories
            'health-plan-categories.read',
            'health-plan-categories.create',
            'health-plan-categories.update',
            'health-plan-categories.delete',
            // Health Plan Templates
            'health-plan-templates.read',
            'health-plan-templates.create',
            'health-plan-templates.update',
            'health-plan-templates.delete',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['guid' => Str::uuid()->toString()],
            );
        }
    }
}
