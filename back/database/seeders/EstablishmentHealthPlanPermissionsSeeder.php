<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class EstablishmentHealthPlanPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'establishment-health-plans.read',
            'establishment-health-plans.create',
            'establishment-health-plans.update',
            'establishment-health-plans.confirm',
        ];

        // TKT-008: permisos dedicados al CRUD de plantillas propias del vet.
        // No se reutilizan los permisos de instancias de arriba porque vet-administrative
        // ya los tiene (DEC-NEG-02: vet-administrative debe quedar solo-lectura sobre plantillas).
        $templatePermissions = [
            'establishment-health-plans.templates.create',
            'establishment-health-plans.templates.update',
            'establishment-health-plans.templates.delete',
        ];

        foreach ([...$permissions, ...$templatePermissions] as $name) {
            Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['guid' => Str::uuid()->toString()],
            );
        }

        $superAdmin = Role::where('name', 'super-admin')->first();
        if ($superAdmin) {
            $superAdmin->syncPermissions(Permission::all());
        }

        // DU-07 + DU-08: vet y vet-assistant reciben el set completo (incluye .confirm).
        $vet = Role::where('name', 'vet')->first();
        $vet?->givePermissionTo(Permission::whereIn('name', $permissions)->get());

        $vetAssistant = Role::where('name', 'vet-assistant')->first();
        $vetAssistant?->givePermissionTo(Permission::whereIn('name', $permissions)->get());

        // TKT-008 / DEC-NEG-02: solo vet y vet-assistant pueden crear/editar/borrar plantillas propias.
        $vet?->givePermissionTo(Permission::whereIn('name', $templatePermissions)->get());
        $vetAssistant?->givePermissionTo(Permission::whereIn('name', $templatePermissions)->get());
        // vet-administrative NO recibe estos permisos.

        // DEC-06: vet-administrative puede instanciar/ver/editar pero NUNCA confirmar.
        $vetAdmin = Role::where('name', 'vet-administrative')->first();
        $vetAdmin?->givePermissionTo(Permission::whereIn('name', [
            'establishment-health-plans.read',
            'establishment-health-plans.create',
            'establishment-health-plans.update',
        ])->get());

        // DU-07 (Opción A): client-owner/client-manager solo confirman actividades.
        // Ver riesgo documentado en el plan de Fase 1 — hoy no hay portal de autenticación
        // tenant para estos roles, así que esto no tiene efecto práctico todavía.
        $clientOwner = Role::where('name', 'client-owner')->first();
        $clientOwner?->givePermissionTo(Permission::whereIn('name', ['establishment-health-plans.confirm'])->get());

        $clientManager = Role::where('name', 'client-manager')->first();
        $clientManager?->givePermissionTo(Permission::whereIn('name', ['establishment-health-plans.confirm'])->get());
    }
}
