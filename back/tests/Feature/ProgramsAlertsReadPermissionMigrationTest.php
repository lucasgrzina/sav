<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\ProgramPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProgramsAlertsReadPermissionMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSION = 'programs.alerts.read';

    private function migration(): object
    {
        return require database_path('migrations/2026_10_13_000003_add_programs_alerts_read_permission.php');
    }

    private function role(string $name, bool $withProgramsRead = true): Role
    {
        $role = Role::firstOrCreate(
            ['name' => $name, 'guard_name' => 'web'],
            ['guid' => Str::uuid()->toString(), 'type' => Role::TYPE_TENANT],
        );

        if ($withProgramsRead) {
            $role->givePermissionTo(Permission::firstOrCreate(
                ['name' => 'programs.read', 'guard_name' => 'web'],
                ['guid' => Str::uuid()->toString()],
            ));
        }

        return $role;
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

    public function test_grants_the_permission_to_staff_roles_with_programs_read_but_never_to_client_roles(): void
    {
        foreach (['super-admin', 'vet', 'vet-administrative', 'vet-assistant', 'client-owner', 'client-manager'] as $name) {
            $this->role($name);
        }
        $this->role('some-role-without-programs', withProgramsRead: false);

        $this->migration()->up();

        $this->assertSame(['super-admin', 'vet', 'vet-administrative', 'vet-assistant'], $this->rolesWithPermission());
    }

    public function test_is_idempotent_and_reversible(): void
    {
        $this->role('vet');
        $migration = $this->migration();

        $migration->up();
        $migration->up();

        $this->assertSame(1, Permission::where('name', self::PERMISSION)->count());
        $this->assertSame(['vet'], $this->rolesWithPermission());

        $migration->down();

        $this->assertSame(0, Permission::where('name', self::PERMISSION)->count());
    }

    public function test_seeder_creates_the_permission_for_the_staff_roles(): void
    {
        foreach (['super-admin', 'vet', 'client-owner'] as $name) {
            $this->role($name, withProgramsRead: false);
        }

        (new ProgramPermissionsSeeder())->run();

        $this->assertTrue(Role::where('name', 'vet')->first()->hasPermissionTo(self::PERMISSION));
        $this->assertTrue(Role::where('name', 'super-admin')->first()->hasPermissionTo(self::PERMISSION));
        $this->assertFalse(Role::where('name', 'client-owner')->first()->hasPermissionTo(self::PERMISSION));
    }
}
