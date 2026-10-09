# QA Review — Backend: TKT-008 Plantillas de plan sanitario propias del vet
Fecha: 2026-09-24
Scope: archivos backend nuevos/modificados de TKT-008 (ver lista al final). No se revisó el resto del working tree (módulo de notificaciones/Twilio en curso, fuera de scope).

## Resumen ejecutivo
- Críticos: 1
- Mayores: 0
- Menores: 2
- Estado: **BLOQUEANTE**

## Problemas críticos (bloquean merge)

### [DEC-NEG-04 / Aislamiento] `AdminHealthPlanTemplateController` puede leer, editar y (parcialmente) intentar borrar plantillas privadas de un vet, y bypassea el bloqueo de "plantilla en uso"

El plan documentó explícitamente el fix de `whereNull('vet_id')` solo en `paginate()` (usado por `index`). Pero `show`, `update` y `destroy` del controller admin usan `HealthPlanTemplateService::findByGuid()` → `HealthPlanTemplateRepositoryEloquent::findByGuid()`, que **no tiene ese scope**. Verificado que `findByGuid()` del repo de plantillas solo se usa desde este controller (grep completo del proyecto), así que el fix es autocontenido y seguro.

Consecuencia real, no teórica:
1. **Exposición cross-tenant**: `GET /health-plan-templates/{guid}` (admin, permiso `health-plan-templates.read`) devuelve el detalle completo de una plantilla privada de un vet, aunque el índice ya la excluye correctamente de la lista — inconsistencia directa entre `index` y `show`.
2. **Bypass de DEC-NEG-04 en `update`**: `AdminHealthPlanTemplateController::update()` llama a `HealthPlanTemplateService::update()` (la variante genérica), que **nunca** invoca `hasInstantiatedPlans()`. Si una plantilla propia de un vet ya generó un `EstablishmentHealthPlan` (debería estar bloqueada según el ticket, sin excepciones documentadas para admin), el panel admin puede modificarla igual, en silencio, sin ningún error.
3. **`destroy` inconsistente**: no hay guard-clause de `hasInstantiatedPlans()` tampoco en el path admin. La migración de `establishment_health_plans` usa `restrictOnDelete()` sobre `health_plan_template_id` (`back/database/migrations/2026_09_24_000001_create_establishment_health_plans_table.php:18`), así que hoy el `DELETE` fallaría a nivel de FK con una excepción genérica capturada por `makeFromException($e)` en vez del 422 `{reason: 'template_locked'}` limpio que sí devuelve el path tenant — comportamiento inconsistente y frágil (si en el futuro cambia a `cascadeOnDelete`, se convierte en borrado silencioso).

**Archivo**: `back/app/Repositories/HealthPlanTemplateRepositoryEloquent.php` líneas 88-95
**Código actual**:
```php
public function findByGuid(string $guid): ?HealthPlanTemplate
{
    /** @var HealthPlanTemplate|null */
    return $this->newQuery()
        ->with(['category', 'activities'])
        ->where('guid', $guid)
        ->first();
}
```
**Corrección**:
```php
public function findByGuid(string $guid): ?HealthPlanTemplate
{
    /** @var HealthPlanTemplate|null */
    return $this->newQuery()
        ->whereNull('vet_id')
        ->with(['category', 'activities'])
        ->where('guid', $guid)
        ->first();
}
```
Con este único cambio, `AdminHealthPlanTemplateController::show/update/destroy` dejan de poder alcanzar plantillas de vet por completo (404, mismo patrón que ya usa el path tenant para plantillas ajenas), y el bypass de DEC-NEG-04 desaparece de raíz porque el admin ya no puede tocar filas con `vet_id` no nulo por ningún punto de entrada.

**Test que falta agregar** (no existe hoy, confirmar tras el fix): en `HealthPlanTemplateRepositoryEloquentTest` o un nuevo test de `AdminHealthPlanTemplateController`, verificar que `show`/`update`/`destroy` sobre el guid de una plantilla con `vet_id` no nulo devuelven 404.

## Problemas mayores
Ninguno detectado.

## Problemas menores

### [m-04, estilo] `VetHealthPlanTemplateController::update`/`destroy` no reciben `$guid` como parámetro tipado
**Archivo**: `back/app/Http/Controllers/V1/VetHealthPlanTemplateController.php` líneas 63-84
**Código actual**:
```php
public function update(UpdateHealthPlanTemplateRequest $request): JsonResponse
{
    ...
    $guid     = $request->route('guid');
    ...
}
```
El resto del proyecto (incluido el propio `AdminHealthPlanTemplateController::update(UpdateHealthPlanTemplateRequest $request, string $guid)`) declara `$guid` como parámetro de método. Aquí se extrae manualmente de `$request->route('guid')` en `update`, `show` y `destroy`. No es un bug (sigue siendo guid, no id numérico), pero es una inconsistencia de estilo dentro del mismo ticket.
**Corrección**: declarar `string $guid` como parámetro explícito, igual que en el controller admin:
```php
public function update(UpdateHealthPlanTemplateRequest $request, string $guid): JsonResponse
public function destroy(string $guid, Request $request): JsonResponse
```

### [M-08, observación] Naming de permisos no sigue el patrón formal documentado
**Archivo**: `back/database/seeders/EstablishmentHealthPlanPermissionsSeeder.php` líneas 24-28
Los permisos nuevos (`establishment-health-plans.templates.create/update/delete`) no siguen el patrón `{modulo}.lectura/alta/modificacion/baja` documentado en `backend-conventions.md`. Esto **no es una regresión de este ticket**: todo el namespace `establishment-health-plans.*` ya usa `.read/.create/.update/.confirm` en inglés desde antes (ver los 4 permisos base en el mismo archivo), y el plan lo justifica explícitamente (DEC-03) como continuidad del precedente existente. Se deja como observación para que el equipo decida si formaliza esta convención en inglés o la corrige en un ticket aparte — no bloquea este PR.

## Verificaciones cruzadas
- **Resource vs Controller**: OK. `HealthPlanTemplateResource`/`HealthPlanTemplateListResource` exponen exactamente `guid, name, category, activities(_count), vet_guid, is_own, is_locked, created_at, updated_at`, consistente con lo que arman `paginateForVetScope`/`findByGuidForVetScope` (alias `is_own` vía `selectRaw`, `is_locked` vía `withExists`). En contexto admin (`paginate()`/`findByGuid()`), `is_own`/`is_locked` caen a `false` y `vet_guid` a `null` sin romper el contrato, como documenta el plan.
- **FormRequest vs Migración**: OK. `StoreHealthPlanTemplateRequest`/`UpdateHealthPlanTemplateRequest` (reutilizados de admin, sin cambios) validan `name`, `health_plan_category_guid`, `activities[]` — todos existen en el modelo/tabla. `vet_id` correctamente **no** se acepta desde el payload del usuario; se inyecta server-side desde `current_vet` en el controller — buena práctica, evita mass-assignment de tenant.
- **Binding en AppServiceProvider**: OK. `HealthPlanTemplateRepositoryInterface` → `HealthPlanTemplateRepositoryEloquent` ya estaba bindeado (sin cambios necesarios, la interface solo agregó métodos).
- **Rutas incluidas en api.php**: OK. `routes/api.php` incluye todos los archivos de `routes/api/*.php` vía `glob()`, así que `establishment-health-plans.php` se carga automáticamente.
- **4 permisos en PermissionSeeder**: OK, con la salvedad de que los 3 permisos nuevos de escritura de plantillas se seedean en `EstablishmentHealthPlanPermissionsSeeder.php` (no en `PermissionSeeder.php`), tal como documenta el plan — es el archivo correcto porque `PermissionSeeder.php` es namespace exclusivo de `health-plan-templates.*` admin. Verificado con test dedicado (`EstablishmentHealthPlanPermissionsSeederTest`) que `vet`/`vet-assistant` los reciben y `vet-administrative` no.

## Puntos específicos de la auditoría solicitada

1. **Aislamiento multi-tenant (vet no puede leer/editar/borrar plantillas de otro vet)**: OK en el path tenant (`VetHealthPlanTemplateController`). `findByGuidForVetScope` (lectura: propias+globales) y `findOwnByGuidForVet` (escritura: solo propias) están correctamente scopeados por `vet_id`, y los tests (`VetHealthPlanTemplateControllerTest`) cubren explícitamente 404 para plantilla de otro vet y para plantilla global en update/destroy. **Pero ver el hallazgo crítico**: el path *admin* sí puede alcanzar plantillas de vets ajenos.
2. **Catálogo admin excluye plantillas privadas**: `paginate()` (usado por `index`) tiene el fix `whereNull('vet_id')` — OK. Pero `findByGuid()` (usado por `show`/`update`/`destroy`) no lo tiene — ver hallazgo crítico.
3. **Bloqueo DEC-NEG-04 en TODOS los puntos de entrada**: aplicado correctamente en el path tenant (`updateOwnedByVet`/`destroyOwnedByVet` llaman `hasInstantiatedPlans()` antes de mutar, con tests que verifican 422 `template_locked`). **No aplicado en el path admin** — ver hallazgo crítico.
4. **Permisos nuevos solo a vet/vet-assistant**: OK, verificado en código y en test dedicado.
5. **Gate de catálogo (`VetHealthActivityController`, `VetHealthPlanCategoryController`)**: OK. Ambos están bajo grupos de rutas con middleware `['auth:sanctum', 'vet.tenant']`, y `vet.tenant` (`EnsureUserBelongsToVet`) valida pertenencia real del usuario a esa vet (perfil activo, no bloqueado) antes de dejar pasar la request — confirmado con tests (`test_user_without_tenant_access_receives_403`).
6. **Convenciones SAV estándar**: respuesta API vía `ApiResponseTrait` (heredado del `Controller` base) OK; FormRequests con `authorize() => true` y `messages()` en español OK (reutilizados de admin sin cambios); Resources en namespace `V1` OK; naming de rutas kebab-case bajo `v1/vets/{vet}/...` consistente con el resto del archivo OK.

## Archivos revisados
- `.claude/docs/tickets/TKT-008-plantillas-plan-sanitario-vet.md`
- `.claude/docs/plans/TKT-008-plantillas-plan-sanitario-vet-plan.md`
- `back/app/Exceptions/HealthPlanTemplateLockedException.php`
- `back/app/Http/Controllers/V1/VetHealthActivityController.php`
- `back/app/Http/Controllers/V1/VetHealthPlanCategoryController.php`
- `back/app/Http/Controllers/V1/VetHealthPlanTemplateController.php`
- `back/app/Http/Controllers/V1/AdminHealthPlanTemplateController.php`
- `back/database/migrations/2026_09_24_193508_add_vet_id_to_health_plan_templates_table.php`
- `back/database/seeders/EstablishmentHealthPlanPermissionsSeeder.php`
- `back/routes/api/establishment-health-plans.php`
- `back/routes/api.php` (verificación de inclusión)
- `back/app/Models/HealthPlanTemplate.php`
- `back/app/Models/EstablishmentHealthPlan.php` (verificación de FK, solo lectura)
- `back/app/Contracts/Repositories/HealthPlanTemplateRepositoryInterface.php`
- `back/app/Repositories/HealthPlanTemplateRepositoryEloquent.php`
- `back/app/Services/HealthPlanTemplateService.php`
- `back/app/Http/Requests/Health/IndexHealthPlanTemplateRequest.php`
- `back/app/Http/Requests/Health/StoreHealthPlanTemplateRequest.php` (sin cambios, verificado por reuso)
- `back/app/Http/Requests/Health/UpdateHealthPlanTemplateRequest.php` (sin cambios, verificado por reuso)
- `back/app/Http/Resources/V1/HealthPlanTemplateResource.php`
- `back/app/Http/Resources/V1/HealthPlanTemplateListResource.php`
- `back/app/Providers/AppServiceProvider.php` (verificación de binding)
- `back/app/Http/Middleware/EnsureUserBelongsToVet.php` (verificación de gate tenant)
- `back/app/Traits/HasGuid.php` (verificación de `getRouteKeyName`)
- `back/app/Http/Controllers/Controller.php` (verificación de `ApiResponseTrait`)
- `back/tests/Feature/VetHealthActivityControllerTest.php`
- `back/tests/Feature/VetHealthPlanCategoryControllerTest.php`
- `back/tests/Feature/VetHealthPlanTemplateControllerTest.php`
- `back/tests/Unit/EstablishmentHealthPlanPermissionsSeederTest.php`
- `back/tests/Unit/HealthPlanTemplateRepositoryEloquentTest.php`
