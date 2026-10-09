<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSION = 'programs.managers.read';

    /** Roles holding any of these get the new permission. */
    private const SOURCE_PERMISSIONS = ['programs.create', 'programs.update'];

    public function up(): void
    {
        $permission = DB::table('permissions')
            ->where('name', self::PERMISSION)
            ->where('guard_name', 'web')
            ->first();

        $permissionId = $permission?->id ?? DB::table('permissions')->insertGetId([
            'guid'       => Str::uuid()->toString(),
            'name'       => self::PERMISSION,
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sourceIds = DB::table('permissions')->whereIn('name', self::SOURCE_PERMISSIONS)->pluck('id');

        $roleIds = DB::table('role_has_permissions')
            ->whereIn('permission_id', $sourceIds)
            ->distinct()
            ->pluck('role_id');

        $alreadyAssigned = DB::table('role_has_permissions')
            ->where('permission_id', $permissionId)
            ->pluck('role_id');

        $rows = $roleIds->diff($alreadyAssigned)
            ->map(fn ($roleId) => ['permission_id' => $permissionId, 'role_id' => $roleId])
            ->values()
            ->all();

        if ($rows) {
            DB::table('role_has_permissions')->insert($rows);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('name', self::PERMISSION)->value('id');

        if ($id) {
            DB::table('role_has_permissions')->where('permission_id', $id)->delete();
            DB::table('model_has_permissions')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
