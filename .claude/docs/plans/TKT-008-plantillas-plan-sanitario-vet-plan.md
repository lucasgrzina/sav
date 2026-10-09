# Plan técnico: Plantillas de plan sanitario propias del vet

## Input procesado
`.claude/docs/tickets/TKT-008-plantillas-plan-sanitario-vet.md`

## Resumen ejecutivo
Se agrega `vet_id` nullable a `health_plan_templates` (nullable = plantilla global, no nulo = plantilla propia de un vet). El vet gestiona sus propias plantillas combinando `HealthActivity`/`HealthPlanCategory` ya existentes, con permisos nuevos (`establishment-health-plans.templates.{create,update,delete}`) restringidos a `vet`/`vet-assistant`. El listado tenant devuelve plantillas propias + globales combinadas con flags `is_own`/`is_locked`/`vet_guid`, y admite filtro `scope=own|global|all`. El bloqueo de edición/borrado (DEC-NEG-04) se resuelve con una query de existencia directa sobre `EstablishmentHealthPlan.health_plan_template_id` (FK ya presente, confirmado en el modelo), sin necesidad de Policy ni versionado. En frontend se extiende el módulo `establishment-health-plans` (tenant) con una página de listado con tabs "Mis plantillas"/"Plantillas del sistema" y un drawer de alta/edición que reutiliza el componente `ActivityMonthMatrix` ya existente en el módulo `health`.

## Investigación resuelta

**Cómo `EstablishmentHealthPlan` referencia su template de origen (riesgo principal del ticket):** `EstablishmentHealthPlan` tiene FK directa `health_plan_template_id` (`back/app/Models/EstablishmentHealthPlan.php:16,47-50`), sin nullable, sin softDeletes. La detección de "plantilla en uso" es una simple existencia:
`EstablishmentHealthPlan::where('health_plan_template_id', $templateId)->exists()`.
No hace falta Policy: el proyecto solo tiene una Policy en todo el código (`ExportPolicy`) — el patrón dominante es guard-clause + excepción de dominio en el Service (ver `EstablishmentHealthPlanNotEditableException`), que es el que replicamos.

**Patrón de tenant scope:** no hay Global Scope ni trait reutilizable — el proyecto scopea manualmente en el repositorio vía métodos `paginateForVet(int $vetId, ...)` / `findByGuidForVet(string $guid, int $vetId)` (ver `EstablishmentHealthPlanRepositoryEloquent`). Replicamos ese mismo patrón para `HealthPlanTemplateRepositoryEloquent`.

**Precedente de naming de permisos:** el endpoint de solo-lectura tenant (`VetHealthPlanTemplateController@index/show`) ya reutiliza el permiso `establishment-health-plans.read` en vez de crear uno nuevo (comentario `DEC-07` en `routes/api/establishment-health-plans.php:16`). Esto confirma que el namespace de permisos para todo lo relacionado a planes sanitarios tenant (instancias Y plantillas) es `establishment-health-plans.*`, mientras que `health-plan-templates.*` queda reservado exclusivamente para el CRUD global de `super-admin`.

## Decisiones tomadas

### DEC-01 — Modelado de ownership
**Decisión:** `vet_id` (unsignedBigInteger, nullable, FK a `vets.id`, `cascadeOnDelete`) directo en `health_plan_templates`. `null` = plantilla global. Índice simple sobre `vet_id` (no compuesto).
**Justificación:** es el mismo modelo que ya usa `EstablishmentHealthPlan.vet_id` para tenant scope; agregar una tabla separada duplicaría toda la lógica de pivot de actividades (`health_plan_template_activity`) sin beneficio. El índice compuesto no aporta: los únicos filtros combinados con `vet_id` son OR-con-null (scope) y opcionalmente `health_plan_category_id`, que ya tiene su propio índice de la migración original.
**Alternativa descartada:** tabla `vet_health_plan_templates` separada — más consistente en teoría, pero obliga a duplicar el pivot de actividades y las Resources, y complica el listado combinado propias+globales (requeriría UNION). Backfill de la migración: automático — una columna nueva `nullable` sin default deja todas las filas existentes en `NULL`, no requiere `UPDATE` explícito.

### DEC-02 — Detección de "plantilla en uso" (bloqueo DEC-NEG-04)
**Decisión:** `HealthPlanTemplateRepositoryEloquent::hasInstantiatedPlans(int $templateId): bool` ejecuta `EstablishmentHealthPlan::where('health_plan_template_id', $templateId)->exists()`. Se llama desde `HealthPlanTemplateService::updateOwnedByVet()`/`destroyOwnedByVet()` como guard-clause, lanzando `HealthPlanTemplateLockedException` (nueva, análoga a `EstablishmentHealthPlanNotEditableException`). El controller la mapea a 422 con `reason: template_locked`.
**Justificación:** no hay soft delete ni cancelación en `EstablishmentHealthPlan` que invalide el conteo — cualquier instancia (cancelada o no) cuenta como "ya generó un plan", tal como pide el ticket ("al menos un `EstablishmentHealthPlan` instanciado", sin distinguir estado).
**Alternativa descartada:** `HealthPlanTemplatePolicy` — sobre-ingeniería para una sola regla de negocio en un proyecto que no usa Policies como patrón establecido.

### DEC-03 — Naming de permisos
**Decisión:** nuevos permisos `establishment-health-plans.templates.create`, `establishment-health-plans.templates.update`, `establishment-health-plans.templates.delete`. Read NO se duplica: se sigue reutilizando `establishment-health-plans.read` (ya lo usa el índice/show tenant existente, DEC-07 previo). Se seedean en `EstablishmentHealthPlanPermissionsSeeder.php` (no en `PermissionSeeder.php`, que es exclusivo del namespace admin `health-plan-templates.*`) y se otorgan únicamente a `vet` y `vet-assistant`.
**Justificación:** si reutilizáramos `establishment-health-plans.create`/`.update` (los permisos de instancias) para las plantillas, `vet-administrative` heredaría automáticamente permiso de escritura sobre plantillas — ese rol YA tiene `establishment-health-plans.create/update` para instanciar planes (`EstablishmentHealthPlanPermissionsSeeder.php:42-46`), lo cual violaría DEC-NEG-02 (vet-administrative debe quedar solo-lectura sobre plantillas). Permisos dedicados evitan ese acoplamiento accidental. `super-admin` los recibe automáticamente vía `syncPermissions(Permission::all())` en `RoleSeeder.php`, sin cambios ahí.
**Alternativa descartada:** `establishment-health-plan-templates.*` (con guión en vez de anidado) — el punto anidado (`establishment-health-plans.templates.*`) dentro del mismo prefijo ya usado por `.read` es más consistente y evita un cuarto prefijo top-level nuevo.

### DEC-04 — Controller y archivo de rutas
**Decisión:** se extiende `VetHealthPlanTemplateController` (ya existe, solo tiene `index`/`show`) agregando `store`/`update`/`destroy`. Rutas nuevas en el mismo archivo `back/routes/api/establishment-health-plans.php`, mismo grupo `v1/vets/{vet}/health-plan-templates`.
**Justificación:** el controller ya está scopeado a vet y ya inyecta `HealthPlanTemplateService`; separarlo en un controller nuevo solo para escritura duplicaría el constructor y la resolución de `current_vet` sin necesidad. El archivo de rutas ya agrupa el prefijo correcto.
**Alternativa descartada:** controller separado `VetHealthPlanTemplateWriteController` — no hay precedente de ese split en el resto del código (`EstablishmentHealthPlanController` mezcla lectura y escritura en un solo controller).

### DEC-05 — Separación de listados propias vs. globales
**Decisión:** un solo endpoint (`GET /v1/vets/{vet}/health-plan-templates`) con filtro opcional `scope` (`own` | `global` | `all`, default `all`). El Resource devuelve `vet_guid` (nullable), `is_own` (bool) e `is_locked` (bool) por item, calculados en la query (no en PHP tras el hecho) vía `selectRaw` + `withExists`.
**Justificación:** evita duplicar endpoint/controller/paginación para un caso que es 100% un filtro de columna. El frontend arma las dos tabs ("Mis plantillas" / "Plantillas del sistema") pasando `scope=own` y `scope=global` respectivamente al mismo composable de query.
**Alternativa descartada:** dos endpoints separados — más superficie de API para el mismo dato, sin beneficio real ya que el filtro es un `WHERE` trivial.

### DEC-06 — Ubicación de la sección frontend
**Decisión:** nueva entrada "Plantillas" dentro de la sección "Sanidad" existente en `VetMenu.vue` (mismo array `sanidadNavItems`, gate `establishment-health-plans.read`), apuntando a `/vets/:vetGuid/health-plan-templates`. La página y componentes nuevos viven en el módulo `establishment-health-plans` (no en `health`, que es exclusivamente admin/global), reutilizando por import cruzado el componente presentacional `ActivityMonthMatrix.vue` y el validator `health-plan-template.validator.ts` del módulo `health` (ambos ya son agnósticos de owner).
**Justificación:** "Plantillas" es conceptualmente parte de "Sanidad" (mismo dominio que "Planes Sanitarios"), no amerita una sección nueva de menú. El módulo `health` es explícitamente el catálogo global gestionado por `super-admin` — mezclar ahí controladores/páginas tenant rompería la separación admin/tenant que ya existe en el resto del proyecto (compárese con `establishment-health-plans` vs. `health` en backend).
**Alternativa descartada:** sección de menú nueva "Plantillas" al tope — fragmenta la navegación sin necesidad real.

## Cambios en BACKEND

### Migrations

#### `back/database/migrations/2026_XX_XX_add_vet_id_to_health_plan_templates_table.php`
```php
Schema::table('health_plan_templates', function (Blueprint $table) {
    $table->unsignedBigInteger('vet_id')->nullable()->after('health_plan_category_id')
          ->comment('null = plantilla global (catálogo super-admin); no nulo = plantilla propia del vet');
    $table->foreign('vet_id')->references('id')->on('vets')->cascadeOnDelete();
    $table->index('vet_id');
});
```
`down()`: dropForeign + dropColumn. Sin backfill explícito: columna nueva nullable sin default → todas las filas existentes quedan en `NULL` automáticamente (DEC-01).

### Archivos a crear

#### `back/app/Exceptions/HealthPlanTemplateLockedException.php`
**Propósito:** señalizar bloqueo DEC-NEG-04.
```php
class HealthPlanTemplateLockedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('La plantilla ya generó planes sanitarios instanciados y no puede editarse ni eliminarse.');
    }
}
```

### Archivos a modificar

#### `back/app/Models/HealthPlanTemplate.php`
**Cambio:** agregar ownership y relaciones nuevas.
- `$fillable` += `'vet_id'`.
- Nueva relación `public function vet(): BelongsTo { return $this->belongsTo(Vet::class); }`.
- Nueva relación `public function establishmentHealthPlans(): HasMany { return $this->hasMany(EstablishmentHealthPlan::class); }` (usada por `withExists` para `is_locked`).

#### `back/app/Contracts/Repositories/HealthPlanTemplateRepositoryInterface.php`
**Cambio:** agregar firmas nuevas, dejar `paginate()`/`findByGuid()` existentes intactas (uso admin/global).
```php
public function paginateForVetScope(int $vetId, array $filters, int $perPage): LengthAwarePaginator;
public function findByGuidForVetScope(string $guid, int $vetId): ?HealthPlanTemplate;
public function findOwnByGuidForVet(string $guid, int $vetId): ?HealthPlanTemplate;
public function hasInstantiatedPlans(int $templateId): bool;
```

#### `back/app/Repositories/HealthPlanTemplateRepositoryEloquent.php`
**Cambio 1 (fix de scope, necesario por la tabla compartida):** `paginate()` (uso admin) agrega `->whereNull('vet_id')` para que el catálogo global de `super-admin` nunca mezcle plantillas propias de vets. Sin este fix, `AdminHealthPlanTemplateController@index` mostraría plantillas privadas de vets en el panel super-admin — regresión de tenant isolation introducida por compartir tabla, no un cambio funcional del ciclo de vida admin (el ticket exige "no modificar el ciclo de vida de las plantillas globales", esto lo preserva).
**Cambio 2:** agregar los 4 métodos nuevos de la interface.
```php
public function paginateForVetScope(int $vetId, array $filters, int $perPage): LengthAwarePaginator
{
    $query = $this->newQuery()
        ->selectRaw('health_plan_templates.*, CASE WHEN vet_id = ? THEN 1 ELSE 0 END as is_own', [$vetId])
        ->withExists(['establishmentHealthPlans as is_locked'])
        ->with(['category', 'vet:id,guid'])
        ->withCount('activities');

    match ($filters['scope'] ?? 'all') {
        'own'    => $query->where('vet_id', $vetId),
        'global' => $query->whereNull('vet_id'),
        default  => $query->where(fn ($q) => $q->where('vet_id', $vetId)->orWhereNull('vet_id')),
    };

    if (!empty($filters['search'])) {
        $query->where('name', 'like', '%' . $filters['search'] . '%');
    }
    if (!empty($filters['health_plan_category_guid'])) {
        $query->whereHas('category', fn ($q) => $q->where('guid', $filters['health_plan_category_guid']));
    }

    return $query->orderByDesc('is_own')->orderBy('name')->paginate($perPage);
}

public function findByGuidForVetScope(string $guid, int $vetId): ?HealthPlanTemplate
{
    return $this->newQuery()
        ->selectRaw('health_plan_templates.*, CASE WHEN vet_id = ? THEN 1 ELSE 0 END as is_own', [$vetId])
        ->withExists(['establishmentHealthPlans as is_locked'])
        ->with(['category', 'activities', 'vet:id,guid'])
        ->where('guid', $guid)
        ->where(fn ($q) => $q->where('vet_id', $vetId)->orWhereNull('vet_id'))
        ->first();
}

public function findOwnByGuidForVet(string $guid, int $vetId): ?HealthPlanTemplate
{
    return $this->newQuery()
        ->withExists(['establishmentHealthPlans as is_locked'])
        ->with(['category', 'activities'])
        ->where('guid', $guid)
        ->where('vet_id', $vetId)
        ->first();
}

public function hasInstantiatedPlans(int $templateId): bool
{
    return EstablishmentHealthPlan::where('health_plan_template_id', $templateId)->exists();
}
```
Nota de seguridad: `findByGuidForVetScope` es para lectura (permite ver globales + propias, nunca privadas de OTRO vet). `findOwnByGuidForVet` es exclusivo para update/destroy (solo propias — si el guid pertenece a otro vet o es global, no matchea y el controller responde 404, mismo patrón que `EstablishmentHealthPlanController::show`, evita filtrar existencia).

#### `back/app/Services/HealthPlanTemplateService.php`
**Cambio:** agregar métodos tenant-aware, reutilizando `buildSyncData()` privado ya existente.
```php
public function paginateForVet(int $vetId, array $filters, int $perPage): LengthAwarePaginator
{
    return $this->templateRepo->paginateForVetScope($vetId, $filters, $perPage);
}

public function findByGuidForVetScope(string $guid, int $vetId): ?HealthPlanTemplate
{
    return $this->templateRepo->findByGuidForVetScope($guid, $vetId);
}

public function findOwnByGuidForVet(string $guid, int $vetId): ?HealthPlanTemplate
{
    return $this->templateRepo->findOwnByGuidForVet($guid, $vetId);
}

/** @throws \RuntimeException si la categoría no existe */
public function createForVet(array $data, int $vetId): HealthPlanTemplate
{
    return DB::transaction(function () use ($data, $vetId) {
        $category = $this->categoryRepo->findByGuid($data['health_plan_category_guid']);
        if (!$category) {
            throw new \RuntimeException('Categoría no encontrada.');
        }

        $template = $this->templateRepo->create([
            'name'                    => $data['name'],
            'health_plan_category_id' => $category->id,
            'vet_id'                  => $vetId,
        ]);

        $this->templateRepo->syncActivities($template, $this->buildSyncData($data['activities'] ?? []));

        return $template->load(['category', 'activities']);
    });
}

/** @throws HealthPlanTemplateLockedException|\RuntimeException */
public function updateOwnedByVet(HealthPlanTemplate $template, array $data): HealthPlanTemplate
{
    if ($this->templateRepo->hasInstantiatedPlans($template->id)) {
        throw new HealthPlanTemplateLockedException();
    }
    return $this->update($template, $data);
}

/** @throws HealthPlanTemplateLockedException */
public function destroyOwnedByVet(HealthPlanTemplate $template): void
{
    if ($this->templateRepo->hasInstantiatedPlans($template->id)) {
        throw new HealthPlanTemplateLockedException();
    }
    $this->templateRepo->destroy($template);
}
```

#### `back/app/Http/Controllers/V1/VetHealthPlanTemplateController.php`
**Cambio:** `index`/`show` pasan a estar scopeados por vet (antes no filtraban nada — bug latente sin efecto hasta ahora porque no existían plantillas privadas); se agregan `store`/`update`/`destroy`.
```php
public function index(IndexHealthPlanTemplateRequest $request): JsonResponse
{
    try {
        $vet       = $request->attributes->get('current_vet');
        $perPage   = $request->integer('per_page', 15);
        $paginator = $this->service->paginateForVet($vet->id, $request->validated(), $perPage);
        return $this->makeSuccessPagination($paginator, HealthPlanTemplateListResource::class);
    } catch (\Exception $e) {
        return $this->makeFromException($e);
    }
}

public function show(Request $request): JsonResponse
{
    try {
        $vet      = $request->attributes->get('current_vet');
        $template = $this->service->findByGuidForVetScope($request->route('guid'), $vet->id);
        if (!$template) {
            return $this->makeNotFound('Plantilla no encontrada.');
        }
        return $this->makeSuccess(new HealthPlanTemplateResource($template));
    } catch (\Exception $e) {
        return $this->makeFromException($e);
    }
}

public function store(StoreHealthPlanTemplateRequest $request): JsonResponse
{
    try {
        $vet      = $request->attributes->get('current_vet');
        $template = $this->service->createForVet($request->validated(), $vet->id);
        return $this->makeSuccess(new HealthPlanTemplateResource($template), 'Plantilla creada correctamente.', 201);
    } catch (\RuntimeException $e) {
        return $this->makeError(null, $e->getMessage(), 422);
    } catch (\Exception $e) {
        return $this->makeFromException($e);
    }
}

public function update(UpdateHealthPlanTemplateRequest $request, string $guid): JsonResponse
{
    try {
        $vet      = $request->attributes->get('current_vet');
        $template = $this->service->findOwnByGuidForVet($guid, $vet->id);
        if (!$template) {
            return $this->makeNotFound('Plantilla no encontrada.');
        }
        $template = $this->service->updateOwnedByVet($template, $request->validated());
        return $this->makeSuccess(new HealthPlanTemplateResource($template), 'Plantilla actualizada correctamente.');
    } catch (HealthPlanTemplateLockedException $e) {
        return $this->makeError(['reason' => 'template_locked'], $e->getMessage(), 422);
    } catch (\RuntimeException $e) {
        return $this->makeError(null, $e->getMessage(), 422);
    } catch (\Exception $e) {
        return $this->makeFromException($e);
    }
}

public function destroy(string $guid, Request $request): JsonResponse
{
    try {
        $vet      = $request->attributes->get('current_vet');
        $template = $this->service->findOwnByGuidForVet($guid, $vet->id);
        if (!$template) {
            return $this->makeNotFound('Plantilla no encontrada.');
        }
        $this->service->destroyOwnedByVet($template);
        return $this->makeSuccess(null, 'Plantilla eliminada correctamente.');
    } catch (HealthPlanTemplateLockedException $e) {
        return $this->makeError(['reason' => 'template_locked'], $e->getMessage(), 422);
    } catch (\Exception $e) {
        return $this->makeFromException($e);
    }
}
```
Imports nuevos: `App\Http\Requests\Health\StoreHealthPlanTemplateRequest`, `UpdateHealthPlanTemplateRequest`, `App\Exceptions\HealthPlanTemplateLockedException` (los Request se **reutilizan tal cual** de admin — misma validación: name, health_plan_category_guid, activities[]; no se crean Requests nuevos).

#### `back/app/Http/Requests/Health/IndexHealthPlanTemplateRequest.php`
**Cambio:** agregar `'scope' => ['nullable', 'in:own,global,all']` a `rules()`. Inocuo para `AdminHealthPlanTemplateController` (su `paginate()` no lee esa clave).

#### `back/app/Http/Resources/V1/HealthPlanTemplateResource.php` y `HealthPlanTemplateListResource.php`
**Cambio:** agregar 3 campos calculados al array de salida:
```php
'vet_guid'  => $this->whenLoaded('vet', fn () => $this->vet?->guid),
'is_own'    => (bool) ($this->is_own ?? false),
'is_locked' => (bool) ($this->is_locked ?? false),
```
`vet_guid` ausente del JSON cuando no se cargó la relación (contexto admin); `is_own`/`is_locked` caen a `false` cuando la query no los seleccionó (contexto admin), sin romper el contrato existente.

#### `back/database/seeders/EstablishmentHealthPlanPermissionsSeeder.php`
**Cambio:** agregar 3 permisos nuevos y otorgarlos solo a `vet`/`vet-assistant`.
```php
$templatePermissions = [
    'establishment-health-plans.templates.create',
    'establishment-health-plans.templates.update',
    'establishment-health-plans.templates.delete',
];
foreach ($templatePermissions as $name) {
    Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web'], ['guid' => Str::uuid()->toString()]);
}
$superAdmin?->syncPermissions(Permission::all()); // ya cubre los nuevos, sin cambio adicional
$vet?->givePermissionTo(Permission::whereIn('name', $templatePermissions)->get());
$vetAssistant?->givePermissionTo(Permission::whereIn('name', $templatePermissions)->get());
// vet-administrative NO recibe estos permisos — DEC-NEG-02.
```

#### `back/routes/api/establishment-health-plans.php`
**Cambio:** agregar 3 rutas al grupo existente `v1/vets/{vet}/health-plan-templates`.
```php
Route::post('/',         [VetHealthPlanTemplateController::class, 'store'])->middleware('can:establishment-health-plans.templates.create');
Route::put('/{guid}',    [VetHealthPlanTemplateController::class, 'update'])->middleware('can:establishment-health-plans.templates.update');
Route::delete('/{guid}', [VetHealthPlanTemplateController::class, 'destroy'])->middleware('can:establishment-health-plans.templates.delete');
```

### Contrato del endpoint (tenant)

`GET /v1/vets/{vetGuid}/health-plan-templates?scope=own|global|all&search=&health_plan_category_guid=&page=&per_page=`
→ 200, paginado, cada item con `guid, name, category{guid,name}, activities_count, vet_guid, is_own, is_locked, created_at, updated_at`.

`GET /v1/vets/{vetGuid}/health-plan-templates/{guid}` → 200 con `activities[]` incluidas, o 404 si el guid no es propio ni global (pertenece a otro vet).

`POST /v1/vets/{vetGuid}/health-plan-templates`
Body: `{ name, health_plan_category_guid, activities: [{ health_activity_guid, months: number[] }] }`
→ 201 con el Resource completo. 422 si la categoría no existe.

`PUT /v1/vets/{vetGuid}/health-plan-templates/{guid}` — mismo body que store.
→ 200. 404 si no es propia. 422 `{ reason: 'template_locked' }` si ya generó planes instanciados.

`DELETE /v1/vets/{vetGuid}/health-plan-templates/{guid}`
→ 200. 404 si no es propia. 422 `{ reason: 'template_locked' }` si ya generó planes instanciados.

### Tests a generar
- `VetHealthPlanTemplateControllerTest` (extender el existente):
  - `index` devuelve solo propias + globales, nunca las de otro vet (crear 2 vets, verificar aislamiento).
  - `index` con `scope=own` / `scope=global` filtra correctamente.
  - `show` de plantilla de otro vet → 404.
  - `store` con permiso `vet`/`vet-assistant` → 201, `vet_id` seteado correctamente; sin permiso (`vet-administrative`) → 403.
  - `update`/`destroy` de plantilla propia sin instancias → 200.
  - `update`/`destroy` de plantilla propia CON al menos un `EstablishmentHealthPlan` instanciado → 422 `reason: template_locked`.
  - `update`/`destroy` de plantilla de otro vet → 404 (nunca 403, para no filtrar existencia).
  - `update`/`destroy` de plantilla global desde el panel tenant → 404 (no matchea `findOwnByGuidForVet`).
- `HealthPlanTemplateRepositoryEloquentTest` (o cobertura vía Feature test): `paginate()` admin nunca incluye filas con `vet_id` no nulo tras el fix de DEC-04.
- Test de permisos: `EstablishmentHealthPlanPermissionsSeeder` otorga los 3 permisos nuevos únicamente a `vet`/`vet-assistant`, no a `vet-administrative`.

## Cambios en FRONTEND

### Archivos a modificar

#### `front/src/modules/health/types/health.types.ts`
**Cambio:** agregar campos al contrato compartido (consumido por ambos módulos).
```ts
export interface HealthPlanTemplate {
  // ...existentes
  vet_guid: string | null
  is_own: boolean
  is_locked: boolean
}
export interface HealthPlanTemplateListItem {
  // ...existentes
  vet_guid: string | null
  is_own: boolean
  is_locked: boolean
}
export interface HealthPlanTemplateListParams {
  // ...existentes
  scope?: 'own' | 'global' | 'all'
}
```

#### `front/src/modules/establishment-health-plans/api/health-plan-templates-catalog.api.ts`
**Cambio:** agregar 3 funciones de escritura junto a las 2 de lectura existentes.
```ts
export async function createHealthPlanTemplateCatalogApi(
  vetGuid: string, payload: CreateHealthPlanTemplatePayload,
): Promise<HealthPlanTemplate> {
  const res = await http.post<HealthPlanTemplate>(`/v1/vets/${vetGuid}/health-plan-templates`, payload)
  return res.data
}

export async function updateHealthPlanTemplateCatalogApi(
  vetGuid: string, guid: string, payload: UpdateHealthPlanTemplatePayload,
): Promise<HealthPlanTemplate> {
  const res = await http.put<HealthPlanTemplate>(`/v1/vets/${vetGuid}/health-plan-templates/${guid}`, payload)
  return res.data
}

export async function deleteHealthPlanTemplateCatalogApi(vetGuid: string, guid: string): Promise<void> {
  await http.delete(`/v1/vets/${vetGuid}/health-plan-templates/${guid}`)
}
```

#### `front/src/modules/establishment-health-plans/components/tenant/EstablishmentHealthPlanForm.vue`
**Cambio:** en el `<a-select>` de plantilla, distinguir visualmente propia vs. global. Como `SelectOption`/`BaseSelect` no soportan grupos hoy, se resuelve con sufijo de label al construir `templateOptions` en `VetEstablishmentHealthPlanNewPage.vue` (no en este componente): `${t.name} — ${t.is_own ? 'Mi plantilla' : 'Plantilla del sistema'}`. Sin cambios estructurales en este archivo más allá de que ya recibe `templateOptions` como prop.

#### `front/src/modules/establishment-health-plans/pages/tenant/VetEstablishmentHealthPlanNewPage.vue`
**Cambio:** `templateOptions` computed agrega el sufijo distintivo:
```ts
const templateOptions = computed(
  () => templatesResponse.value?.data.map((t) => ({
    value: t.guid,
    label: `${t.name} — ${t.is_own ? 'Mi plantilla' : 'Plantilla del sistema'}`,
  })) ?? [],
)
```

#### `front/src/components/layouts/partials/VetMenu.vue`
**Cambio:** agregar entrada a `sanidadNavItems`:
```ts
{ path: `/vets/${vetGuid.value}/health-plan-templates`, label: 'Plantillas', icon: HeartOutlined, permission: 'establishment-health-plans.read' },
```

#### `front/src/modules/establishment-health-plans/router/vet-establishment-health-plans.routes.ts`
**Cambio:** agregar ruta:
```ts
{
  path: 'health-plan-templates',
  name: 'vet-health-plan-templates-list',
  component: () => import('@/modules/establishment-health-plans/pages/tenant/VetHealthPlanTemplatesListPage.vue'),
  meta: { requiresAuth: true, title: 'Plantillas de plan sanitario' },
},
```

### Archivos a crear

#### `front/src/modules/establishment-health-plans/composables/useHealthPlanTemplateCatalogMutations.ts`
**Propósito:** create/update/delete tenant, mismo patrón que `useHealthPlanTemplateMutations.ts` (módulo `health`), invalidando `['health-plan-templates-catalog', vetGuid]`.
**Firmas:** `useCreateHealthPlanTemplateCatalog()`, `useUpdateHealthPlanTemplateCatalog()`, `useDeleteHealthPlanTemplateCatalog()` — cada una lee `vetGuid` de `useRoute().params.vetGuid`, igual que `useHealthPlanTemplateCatalog.ts`.

#### `front/src/modules/establishment-health-plans/components/tenant/VetHealthPlanTemplateDrawer.vue`
**Propósito:** alta/edición de plantilla propia. Estructura calcada de `health/components/HealthPlanTemplateDrawer.vue`, con dos diferencias:
1. importa `ActivityMonthMatrix` desde `@/modules/health/components/ActivityMonthMatrix.vue` (reuso cruzado, sin duplicar).
2. usa `useCreateHealthPlanTemplateCatalog`/`useUpdateHealthPlanTemplateCatalog` en vez de las mutaciones admin.
3. si `props.template?.is_locked` es `true` en modo edit, deshabilita el submit y muestra `<a-alert type="warning">` con el mensaje de bloqueo (defensivo — la página no debería abrir el drawer en edit para filas bloqueadas, pero el componente no debe confiar solo en eso).
**Props:** `{ mode: 'create' | 'edit'; template?: HealthPlanTemplate | null }` (mismo shape que el admin).

#### `front/src/modules/establishment-health-plans/pages/tenant/VetHealthPlanTemplatesListPage.vue`
**Propósito:** página con `<a-tabs>`:
- Tab "Mis plantillas" (`scope=own`): tabla con columnas Nombre/Categoría/Actividades/Acciones. Botón "Nueva plantilla" con `<PermissionGuard permission="establishment-health-plans.templates.create">`. Acciones editar/eliminar con `<PermissionGuard permission="establishment-health-plans.templates.update">`/`.delete`, deshabilitadas (+ tooltip "Ya generó planes sanitarios, no se puede editar") cuando `record.is_locked`.
- Tab "Plantillas del sistema" (`scope=global`): misma tabla sin columna de acciones (solo lectura).
Usa `useHealthPlanTemplateCatalog({ scope, page, per_page })` (ya existente, solo se le pasa el nuevo param) para ambas tabs con estado de filtro independiente.

### Tests a generar
- Composable `useHealthPlanTemplateCatalogMutations`: invalidación de queries tras create/update/delete.
- `VetHealthPlanTemplatesListPage`: tab "Mis plantillas" muestra acciones solo con permiso; tab "Plantillas del sistema" nunca muestra acciones; fila con `is_locked=true` deshabilita editar/eliminar.
- `VetHealthPlanTemplateDrawer`: en modo edit con `is_locked=true`, el submit queda deshabilitado.

## Orden de implementación
1. Migración `add_vet_id_to_health_plan_templates_table` + `HealthPlanTemplateLockedException`.
2. Modelo `HealthPlanTemplate` (relaciones `vet`, `establishmentHealthPlans`, fillable).
3. Interface + Eloquent repo: métodos nuevos + fix de `paginate()` admin (DEC-04).
4. `HealthPlanTemplateService`: métodos tenant nuevos.
5. `VetHealthPlanTemplateController`: store/update/destroy + scope de index/show.
6. `IndexHealthPlanTemplateRequest` (agregar `scope`), Resources (agregar 3 campos).
7. `EstablishmentHealthPlanPermissionsSeeder` (3 permisos nuevos) + rutas.
8. Correr seeders en local, correr suite de tests backend, agregar tests nuevos (sección Tests a generar).
9. Frontend: types compartidos (`health.types.ts`) primero.
10. API + composables de mutaciones tenant.
11. `VetHealthPlanTemplateDrawer.vue` + `VetHealthPlanTemplatesListPage.vue`.
12. Router + `VetMenu.vue`.
13. Ajuste de `templateOptions` en `VetEstablishmentHealthPlanNewPage.vue` (distinción visual propia/global).
14. Tests frontend nuevos.

## Riesgos y consideraciones
- **Regresión de scope admin:** sin el fix de `paginate()` (DEC-04, punto 3), el panel `super-admin` de plantillas globales empezaría a mostrar plantillas privadas de vets apenas exista la primera. Es un cambio obligatorio, no opcional, aunque el ticket pide "no tocar el ciclo de vida admin" — se documenta explícitamente para que QA lo verifique.
- **`index`/`show` tenant no estaban scopeados antes:** hoy devuelven todo el catálogo sin filtrar por vet (no hay bug visible aún porque no existen plantillas privadas). Este plan lo corrige como parte natural de la feature, pero es un cambio de comportamiento que debe mencionarse en el PR.
- **Multi-tenant:** toda query nueva sobre `health_plan_templates` con intención de "propias" pasa por `vet_id = $vetId`; toda query de "propias o visibles" usa el OR explícito con `whereNull`. No hay acceso a plantillas de otro vet en ningún punto (verificado con `findOwnByGuidForVet` para escritura y `findByGuidForVetScope` para lectura).
- **UX del selector de plantillas al instanciar (`EstablishmentHealthPlanForm.vue`):** la solución de sufijo de texto en el label es mínima; si el catálogo de plantillas propias crece mucho, conviene agrupar con `<a-select-opt-group>` — no se aborda en esta iteración (ver Pendientes).
- **Multi-país:** sin impacto — la tabla no tiene lógica de país, hereda el país del vet indirectamente vía `Establishment.client.country` como ya ocurre para `EstablishmentHealthPlanYear`.

## Pendientes / fuera de alcance
- Agrupar visualmente el `<a-select>` de plantillas con `<a-select-opt-group>` (propias/globales) — mejora de UX, no bloqueante.
- Tests de `AdminHealthPlanTemplateController` (hoy no tiene ninguno) — fuera de alcance de este ticket, mencionado porque el fix de DEC-04 lo toca tangencialmente.
- Versionado/snapshot de plantillas al instanciar — explícitamente fuera de alcance por DEC-NEG-04.
