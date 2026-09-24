<?php

namespace Tests\Unit;

use App\Models\Role;
use Database\Seeders\EstablishmentHealthPlanPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EstablishmentHealthPlanPermissionsSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super-admin', 'vet', 'vet-assistant', 'vet-administrative', 'client-owner', 'client-manager'] as $roleName) {
            Role::firstOrCreate(
                ['name' => $roleName, 'guard_name' => 'web'],
                ['guid' => Str::uuid()->toString(), 'type' => Role::TYPE_TENANT],
            );
        }

        (new EstablishmentHealthPlanPermissionsSeeder())->run();
    }

    public function test_template_permissions_are_granted_only_to_vet_and_vet_assistant(): void
    {
        $templatePermissions = [
            'establishment-health-plans.templates.create',
            'establishment-health-plans.templates.update',
            'establishment-health-plans.templates.delete',
        ];

        foreach ($templatePermissions as $permission) {
            $this->assertTrue(Role::where('name', 'vet')->first()->hasPermissionTo($permission));
            $this->assertTrue(Role::where('name', 'vet-assistant')->first()->hasPermissionTo($permission));
            $this->assertFalse(Role::where('name', 'vet-administrative')->first()->hasPermissionTo($permission));
        }
    }

    public function test_super_admin_receives_template_permissions_via_sync_all(): void
    {
        $superAdmin = Role::where('name', 'super-admin')->first();

        $this->assertTrue($superAdmin->hasPermissionTo('establishment-health-plans.templates.create'));
        $this->assertTrue($superAdmin->hasPermissionTo('establishment-health-plans.templates.update'));
        $this->assertTrue($superAdmin->hasPermissionTo('establishment-health-plans.templates.delete'));
    }
}
