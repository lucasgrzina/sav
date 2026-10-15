<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardAdminReadPermissionMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSION = 'dashboard.admin.read';

    private function migration(): object
    {
        return require database_path('migrations/2026_10_14_000001_add_dashboard_admin_read_permission.php');
    }

    private function role(string $name, string $type = Role::TYPE_PLATFORM): void
    {
        Role::firstOrCreate(
            ['name' => $name, 'guard_name' => 'web'],
            ['guid' => Str::uuid()->toString(), 'type' => $type],
        );
    }

    /** @return list<string> */
    private function rolesWithPermission(): array
    {
        $id = Permission::where('name', self::PERMISSION)->value('id');

        return DB::table('role_has_permissions')
            ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
            ->where('permission_id', $id)
            ->orderBy('roles.name')
            ->pluck('roles.name')
            ->all();
    }

    public function test_grants_only_super_admin_and_admin(): void
    {
        $this->role('super-admin');
        $this->role('admin');
        $this->role('operador');
        $this->role('vet', Role::TYPE_TENANT);

        $this->migration()->up();

        $this->assertSame(['admin', 'super-admin'], $this->rolesWithPermission());
    }

    public function test_is_idempotent_and_reversible(): void
    {
        $this->role('admin');
        $migration = $this->migration();

        $migration->up();
        $migration->up();

        $this->assertSame(1, Permission::where('name', self::PERMISSION)->count());
        $this->assertSame(['admin'], $this->rolesWithPermission());

        $migration->down();

        $this->assertSame(0, Permission::where('name', self::PERMISSION)->count());
    }
}
