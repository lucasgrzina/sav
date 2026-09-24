# Plan técnico: Planes Sanitarios por establecimiento — Fase 1 (instanciar + calendario + confirmación manual)

## Input procesado

`.claude/docs/specs/planes-sanitarios-establecimiento-spec.md` (agente `funcional`).

## Resumen ejecutivo

Se crea la entidad `EstablishmentHealthPlan` (+ detalle materializado `EstablishmentHealthPlanActivity`), independiente de `Protocol`/`Program`, que permite a un vet tenant instanciar un `HealthPlanTemplate` del catálogo admin existente sobre un establecimiento de su cliente, generando un calendario congelado (snapshot) de actividades por mes dentro del año ganadero, calculado por país. Se agrega un endpoint de lectura del catálogo para el panel tenant (reutilizando servicios admin existentes sin cambios) y la confirmación manual de actividades realizadas en campo. Sin alertas automáticas — eso es Fase 2, fuera de alcance. Módulo backend nuevo (`establishment-health-plans`) + módulo frontend nuevo (`establishment-health-plans`), ambos espejando el patrón ya usado por `programs`.

## Dudas abiertas resueltas

- **DU-05** (duplicados): resuelta por decisión técnica — bloqueo a nivel aplicación, no DB. Ver DEC-04.
- **DU-06** (snapshot vs. vivo): resuelta por diseño — la materialización exigida por RF-02 ya produce un snapshot. Ver DEC-05.
- **DU-07** (roles habilitados para confirmar): **confirmada por el usuario — Opción A**: `vet`, `vet-assistant`, `client-owner`, `client-manager`.
- **DU-08** (alcance de `vet-administrative`): resuelta por precedente de código — SÍ puede instanciar/ver (igual que `programs.create`), pero NO puede confirmar (no está en el set de DU-07). Ver DEC-06.
- **DU-09** (reasignación de vet): resuelta — fuera de alcance, sin comportamiento especial; no existe la funcionalidad de reasignación en el sistema hoy, y el patrón (`vet_id` fijo al crear) es idéntico al de `Program`.

## Decisiones tomadas

**DEC-01 — Módulo frontend nuevo, no reutilizar `front/src/modules/health/`**
Decisión: crear `front/src/modules/establishment-health-plans/` en vez de agregar submódulos a `health/`.
Justificación: `health/` es 100% admin/SuperAdmin (catálogo, sin rutas tenant, sin `vet.tenant`). Mezclar un flujo tenant ahí rompería la separación admin/tenant que el resto del proyecto respeta estrictamente (ver `programs` vs `protocols`, ambos separados aunque relacionados).
Alternativa descartada: agregar carpetas `tenant/` dentro de `health/` — generaría un módulo con dos audiencias y dos flujos de auth distintos, inconsistente con el patrón existente.

**DEC-02 — Naming real de Repository/Service (no el que dice `backend-conventions.md`)**
Decisión: usar `EstablishmentHealthPlanRepositoryInterface` / `EstablishmentHealthPlanRepositoryEloquent`.
Justificación: el código real (`ProgramRepositoryInterface`, `ClientRepositoryInterface`, `VetRepositoryInterface`, etc.) usa sufijo `RepositoryInterface`/`RepositoryEloquent`, nunca el prefijo `I{Nombre}Repository` que describe el skill. El código es la fuente de verdad — discrepancia documentada en Riesgos.

**DEC-03 — Naming real de permisos (no el que dice `backend-conventions.md`)**
Decisión: permisos `establishment-health-plans.read|create|update|confirm` (verbos en inglés).
Justificación: todo el código real (`programs.read/create/update`, `health-plan-templates.read/create/update/delete`) usa verbos CRUD en inglés, nunca el patrón `lectura/alta/modificacion/baja` que describe el skill. Discrepancia documentada en Riesgos.

**DEC-04 — Duplicados: bloqueo a nivel aplicación, no constraint de DB**
Decisión: no permitir más de un `EstablishmentHealthPlan` **activo** (`cancelled_at IS NULL`) para la misma tupla (`establishment_id`, `health_plan_template_id`, `year`). Se valida en `StoreEstablishmentHealthPlanRequest::withValidator` + recheck en el Service dentro de la transacción (defensa contra condición de carrera).
Justificación: MySQL no soporta índices únicos parciales; un unique index tradicional sobre esas 3 columnas bloquearía también re-instanciar tras cancelar (cosa que sí debe permitirse), y un unique que incluya `cancelled_at` no sirve porque MySQL permite múltiples `NULL` en una columna única (no bloquearía dos planes activos). No hay precedente de este truco en el codebase — se sigue el patrón real ya usado (`StoreProgramRequest::withValidator`).

**DEC-05 — Snapshot automático, no requiere mecanismo extra**
Decisión: `EstablishmentHealthPlanActivity` se crea una vez al instanciar, con `health_activity_id` + `month` + `due_date` copiados del template en ese momento. `health_plan_template_id` en `EstablishmentHealthPlan` queda solo como referencia de trazabilidad ("instanciado desde"), nunca se vuelve a leer para recalcular el calendario.
Justificación: RF-02 ya exige materializar filas físicas (no una proyección en memoria como hace `ProgramService::projectTargetTasks` con `Protocol`). Por diseño, esto ya es un snapshot — ediciones futuras del template no tocan planes ya instanciados. Satisface la auditoría de integridad histórica (regla dura #7) sin trabajo adicional.

**DEC-06 — Permiso de confirmación separado de create/update, y excluye a `vet-administrative`**
Decisión: `establishment-health-plans.confirm` es un permiso propio, distinto de `.create`/`.update`. Se otorga a `vet`, `vet-assistant`, `client-owner`, `client-manager` (los 4 confirmados en DU-07). `vet-administrative` recibe `.read/.create/.update` (por DEC del DU-08) pero NO `.confirm`.
Justificación: si compartiera permiso con `.update`, `vet-administrative` heredaría la capacidad de confirmar, violando la decisión de negocio explícita del DU-07 (que no lo incluyó).
Alternativa descartada: un único permiso `establishment-health-plans.manage` — no permite separar quién administra el plan de quién confirma actividades en campo.

**DEC-07 — Catálogo tenant reutiliza el permiso `establishment-health-plans.read`, no crea uno nuevo**
Decisión: el endpoint de lectura del catálogo (RF-01) para el panel tenant usa el mismo permiso `establishment-health-plans.read`, y reutiliza `HealthPlanTemplateService`/`HealthPlanTemplateRepositoryInterface`/`HealthPlanTemplateResource`/`HealthPlanTemplateListResource` sin ningún cambio — solo un controller y rutas tenant nuevos.
Justificación: evita permission sprawl para una capacidad que conceptualmente es la misma ("ver información de planificación sanitaria"); el catálogo admin ya es de solo lectura desde la perspectiva tenant y no requiere lógica nueva.

**DEC-08 — Año ganadero resuelto por un helper nuevo, sin columna nueva en `countries`**
Decisión: `App\Support\HealthPlanYear` (mismo estilo que `App\Support\DateOffset`), con un mapa estático `iso_code => mes de inicio` (`AR => 7`, default `1` para cualquier otro país), acorde a `regulations-by-country.md`.
Justificación: hoy `Country` no tiene ningún campo de este tipo y solo hay 1 país con regla especial (AR). Agregar una columna a `countries` para un solo caso especial es sobre-ingeniería para Fase 1; el helper es trivial de extender el día que haya una segunda excepción real. Cumple regla dura #3 y #5 (parametrizado por país, no hardcodeado a nivel de código de negocio).
Alternativa descartada: columna `health_plan_year_start_month` en `countries` — se deja como mejora futura si el mapa estático crece.

**DEC-09 — Confirmación idempotente, no error 422**
Decisión: confirmar una actividad ya `confirmed` es un no-op: el Service detecta `confirmed_at !== null` y devuelve la actividad sin modificar `confirmed_at`/`confirmed_by_profile_id` (no pisa la confirmación original).
Justificación: es una acción de usuario naturalmente idempotente (doble click, reintento de red); forzar un error 422 en ese caso es peor UX sin beneficio de integridad — el valor auditado (quién confirmó primero) se preserva igual.

**DEC-10 — Sin Repository propio para `EstablishmentHealthPlanActivity`**
Decisión: el Service opera sobre la relación `$plan->activities()` directamente (bulk insert + update puntual), sin `EstablishmentHealthPlanActivityRepositoryInterface`.
Justificación: mismo patrón exacto que `ProgramTarget` respecto a `Program` — no existe `ProgramTargetRepositoryInterface` en el codebase; `ProgramService::syncTargets` opera directo sobre la relación.

## Cambios en BACKEND

### Migrations

#### `back/database/migrations/{fecha}_create_establishment_health_plans_table.php`
```php
Schema::create('establishment_health_plans', function (Blueprint $table) {
    $table->id();
    $table->char('guid', 36)->unique();
    $table->foreignId('vet_id')->constrained('vets')->cascadeOnDelete()
          ->comment('tenant owner — regla dura #4');
    $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
    $table->foreignId('establishment_id')->constrained('establishments')->cascadeOnDelete();
    $table->foreignId('health_plan_template_id')->constrained('health_plan_templates')->restrictOnDelete()
          ->comment('solo trazabilidad — el calendario ya está materializado, no se re-lee (DEC-05)');
    $table->unsignedSmallInteger('year')->comment('año de inicio del ciclo ganadero (ej. 2026 = jul/2026-jun/2027 en AR)');
    $table->date('starts_on');
    $table->date('ends_on');
    $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete()
          ->comment('auditoría — quién instanció el plan (regla dura #7 / NFR auditoría)');
    $table->timestamp('cancelled_at')->nullable();
    $table->timestamps();

    $table->index('vet_id');
    $table->index(['vet_id', 'cancelled_at']);
    $table->index(['establishment_id', 'health_plan_template_id', 'year'], 'ehp_dedup_lookup_idx');
});
```

#### `back/database/migrations/{fecha}_create_establishment_health_plan_activities_table.php`
```php
Schema::create('establishment_health_plan_activities', function (Blueprint $table) {
    $table->id();
    $table->char('guid', 36)->unique();
    $table->foreignId('establishment_health_plan_id')->constrained('establishment_health_plans')->cascadeOnDelete();
    $table->foreignId('health_activity_id')->constrained('health_activities')->restrictOnDelete();
    $table->unsignedTinyInteger('month')->comment('1-12, mes calendario — copiado del pivot months del template al instanciar (DEC-05)');
    $table->date('due_date')->comment('fecha concreta calculada al instanciar via App\\Support\\HealthPlanYear');
    $table->unsignedInteger('sort_order')->default(0);
    $table->boolean('require_confirmation')->default(true)->comment('regla dura #7 — siempre true en Fase 1, columna explícita para no hardcodear en código');
    $table->timestamp('confirmed_at')->nullable();
    $table->foreignId('confirmed_by_profile_id')->nullable()->constrained('user_profiles')->nullOnDelete()
          ->comment('regla dura #7 — quién confirmó');
    $table->timestamps();

    $table->index(['establishment_health_plan_id', 'month']);
});
```

### Modelos

#### `back/app/Models/EstablishmentHealthPlan.php`
```php
class EstablishmentHealthPlan extends Model
{
    use HasGuid;

    protected $fillable = [
        'vet_id', 'client_id', 'establishment_id', 'health_plan_template_id',
        'year', 'starts_on', 'ends_on', 'created_by_user_id', 'cancelled_at',
    ];
    protected $hidden = ['id'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'cancelled_at' => 'datetime', 'year' => 'integer'];
    }

    public function vet(): BelongsTo { return $this->belongsTo(Vet::class); }
    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function establishment(): BelongsTo { return $this->belongsTo(Establishment::class); }
    public function template(): BelongsTo { return $this->belongsTo(HealthPlanTemplate::class, 'health_plan_template_id'); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by_user_id'); }
    public function activities(): HasMany { return $this->hasMany(EstablishmentHealthPlanActivity::class)->orderBy('sort_order')->orderBy('month'); }

    protected function editable(): Attribute { return Attribute::get(fn () => $this->cancelled_at === null); }
}
```

#### `back/app/Models/EstablishmentHealthPlanActivity.php`
```php
class EstablishmentHealthPlanActivity extends Model
{
    use HasGuid;

    /** DU-07 confirmado (Opción A) — vocabulario propio, no depende de ProtocolAlert.roles (que en código real permite 6 valores, ver Riesgos). */
    public const CONFIRM_ROLES = ['vet', 'vet-assistant', 'client-owner', 'client-manager'];

    protected $fillable = [
        'establishment_health_plan_id', 'health_activity_id', 'month', 'due_date',
        'sort_order', 'require_confirmation', 'confirmed_at', 'confirmed_by_profile_id',
    ];
    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'due_date' => 'date', 'require_confirmation' => 'boolean',
            'confirmed_at' => 'datetime', 'month' => 'integer', 'sort_order' => 'integer',
        ];
    }

    public function plan(): BelongsTo { return $this->belongsTo(EstablishmentHealthPlan::class, 'establishment_health_plan_id'); }
    public function activity(): BelongsTo { return $this->belongsTo(HealthActivity::class, 'health_activity_id'); }
    public function confirmedBy(): BelongsTo { return $this->belongsTo(UserProfile::class, 'confirmed_by_profile_id'); }

    protected function status(): Attribute { return Attribute::get(fn () => $this->confirmed_at === null ? 'pending' : 'confirmed'); }
}
```

### Support

#### `back/app/Support/HealthPlanYear.php`
**Propósito:** resolver el rango de fechas del año ganadero y la fecha concreta de una actividad por mes, parametrizado por país (regla dura #3 y #5). Mismo estilo que `App\Support\DateOffset`.
```php
final class HealthPlanYear
{
    private const START_MONTH_BY_ISO = ['AR' => 7]; // default 1 (enero) — regulations-by-country.md

    public static function startMonth(Country $country): int
    {
        return self::START_MONTH_BY_ISO[$country->iso_code] ?? 1;
    }

    /** @return array{0: Carbon, 1: Carbon} [starts_on, ends_on] */
    public static function range(Country $country, int $year): array
    {
        $start = Carbon::create($year, self::startMonth($country), 1)->startOfDay();
        return [$start, $start->copy()->addYear()->subDay()];
    }

    public static function dateForMonth(Country $country, int $year, int $month): Carbon
    {
        $startMonth = self::startMonth($country);
        $calendarYear = $month >= $startMonth ? $year : $year + 1;
        return Carbon::create($calendarYear, $month, 1)->startOfDay();
    }
}
```

### Contracts / Repositories

#### `back/app/Contracts/Repositories/EstablishmentHealthPlanRepositoryInterface.php`
```php
interface EstablishmentHealthPlanRepositoryInterface
{
    public function findByGuid(string $guid): ?EstablishmentHealthPlan;
    public function findByGuidForVet(string $guid, int $vetId): ?EstablishmentHealthPlan;
    public function paginateForVet(int $vetId, array $filters, int $perPage): LengthAwarePaginator;
    public function existsActiveFor(int $establishmentId, int $templateId, int $year, ?int $excludeId = null): bool;
    public function create(array $data): EstablishmentHealthPlan;
    public function update(EstablishmentHealthPlan $plan, array $data): EstablishmentHealthPlan;
}
```

#### `back/app/Repositories/EstablishmentHealthPlanRepositoryEloquent.php`
Extiende `BaseRepositoryEloquent`. `paginateForVet` espeja `ProgramRepositoryEloquent::paginateForVet` (where vet_id, with client/establishment/template, filtros `client_guid`/`establishment_guid`/`cancelled`/`search` sobre `establishment.name`, `withCount('activities')`). `findByGuidForVet` con `with(['client','establishment','template.category','activities.activity','activities.confirmedBy.user'])`. `existsActiveFor` hace `where establishment_id/health_plan_template_id/year/whereNull(cancelled_at)` y opcional `where('id','!=',$excludeId)`.

Binding nuevo en `back/app/Providers/AppServiceProvider.php::register()`:
```php
$this->app->bind(EstablishmentHealthPlanRepositoryInterface::class, EstablishmentHealthPlanRepositoryEloquent::class);
```

### Exceptions

- `back/app/Exceptions/EstablishmentHealthPlanAlreadyExistsException.php` — 422, `reason: 'duplicate_plan'`.
- `back/app/Exceptions/EstablishmentHealthPlanNotEditableException.php` — 422, `reason: 'not_editable'` (cancelar un plan ya cancelado). Mismo patrón que `ProgramNotEditableException`.
- `back/app/Exceptions/EstablishmentHealthPlanActivityConfirmationNotAllowedException.php` — 403, rol del profile actual no está en `EstablishmentHealthPlanActivity::CONFIRM_ROLES`.

### Service

#### `back/app/Services/EstablishmentHealthPlanService.php`
```php
class EstablishmentHealthPlanService
{
    public function __construct(private EstablishmentHealthPlanRepositoryInterface $repository) {}

    public function paginateForVet(int $vetId, array $filters, int $perPage): LengthAwarePaginator;
    public function findByGuidForVet(string $guid, int $vetId): ?EstablishmentHealthPlan;

    /**
     * @param array $data {establishment_id, client_id, health_plan_template_id (ints resueltos), year}
     * @throws EstablishmentHealthPlanAlreadyExistsException
     */
    public function create(array $data, int $vetId, ?int $createdByUserId): EstablishmentHealthPlan
    {
        // DB::transaction:
        //  1. existsActiveFor(...) -> throw si true (DEC-04, recheck de condición de carrera)
        //  2. $establishment->client->country -> HealthPlanYear::range() -> starts_on/ends_on
        //  3. crear fila EstablishmentHealthPlan (vet_id, client_id, establishment_id,
        //     health_plan_template_id, year, starts_on, ends_on, created_by_user_id)
        //  4. cargar $template->activities (pivot months + sort_order)
        //  5. construir $rows[]: foreach activity, foreach month in pivot.months ->
        //     { guid: Str::uuid(), establishment_health_plan_id, health_activity_id, month,
        //       due_date: HealthPlanYear::dateForMonth(...), sort_order: pivot.sort_order,
        //       require_confirmation: true, created_at: now(), updated_at: now() }
        //  6. EstablishmentHealthPlanActivity::insert($rows) -- bulk, NFR de performance
        //     (guid/timestamps seteados a mano: insert() bypassa boot()/HasGuid, igual que en seeders)
        //  7. return $plan->fresh()->load(['client','establishment','template.category','activities.activity'])
    }

    /** @throws EstablishmentHealthPlanNotEditableException */
    public function cancel(EstablishmentHealthPlan $plan): EstablishmentHealthPlan; // mismo patrón que ProgramService::cancel

    /** @throws EstablishmentHealthPlanActivityConfirmationNotAllowedException */
    public function confirmActivity(EstablishmentHealthPlanActivity $activity, UserProfile $profile): EstablishmentHealthPlanActivity
    {
        if (!in_array($profile->role->name, EstablishmentHealthPlanActivity::CONFIRM_ROLES, true)) {
            throw new EstablishmentHealthPlanActivityConfirmationNotAllowedException();
        }
        if ($activity->confirmed_at !== null) {
            return $activity; // DEC-09 — idempotente
        }
        $activity->update(['confirmed_at' => now(), 'confirmed_by_profile_id' => $profile->id]);
        return $activity->fresh()->load('confirmedBy.user');
    }
}
```

### Form Requests

#### `back/app/Http/Requests/EstablishmentHealthPlans/IndexEstablishmentHealthPlanRequest.php`
`authorize()` → true. Rules: `client_guid`/`establishment_guid` nullable uuid, `cancelled` nullable boolean, `search` nullable string, `per_page` nullable integer.

#### `back/app/Http/Requests/EstablishmentHealthPlans/StoreEstablishmentHealthPlanRequest.php`
Rules:
```php
'establishment_id'         => ['required', 'string', 'uuid', 'exists:establishments,guid'],
'health_plan_template_id'  => ['required', 'string', 'uuid', 'exists:health_plan_templates,guid'],
'year'                      => ['required', 'integer', 'min:2020', 'max:' . (now()->year + 1)],
```
`withValidator` (inyecta `ClientRepositoryInterface` + `EstablishmentHealthPlanRepositoryInterface`, mismo patrón que `StoreProgramRequest`):
1. Resolver `Establishment` por guid; si no existe, error en `establishment_id`.
2. Verificar que `$vet->clients()->whereKey($establishment->client_id)->exists()` — si no, error "El establecimiento no pertenece a esta veterinaria." en `establishment_id` (satisface el 403/422 de RF-02 AC #2, regla dura #4).
3. Verificar `$establishment->client->country` no sea null — si null, error "El cliente no tiene país configurado." (defensivo, evita crash en `HealthPlanYear`).
4. `existsActiveFor($establishment->id, $template->id, $year)` — si true, error "Ya existe un plan activo para este establecimiento, plantilla y año." en `health_plan_template_id` (DEC-04 / DU-05).

#### `back/app/Http/Requests/EstablishmentHealthPlans/ConfirmEstablishmentHealthPlanActivityRequest.php`
Sin body — solo `authorize()` → true (el check de rol vive en el Service, no acá, siguiendo la convención "`authorize()` siempre true").

### Controllers

#### `back/app/Http/Controllers/V1/EstablishmentHealthPlanController.php`
Namespace `V1`, `ApiResponseTrait`, delgado — mismo esqueleto que `ProgramController`:
- `index(IndexEstablishmentHealthPlanRequest)` → `paginateForVet` → `EstablishmentHealthPlanListResource`.
- `store(StoreEstablishmentHealthPlanRequest)` → resuelve guids a ids (`establishment_id`, `health_plan_template_id`, agrega `client_id` = `$establishment->client_id`) → `service->create($data, $vet->id, $request->user()->id)` → 201 `EstablishmentHealthPlanResource`. Catch `EstablishmentHealthPlanAlreadyExistsException` → 422 `reason: duplicate_plan` (defensa de condición de carrera; el 99% de los casos ya lo atrapa el FormRequest).
- `show(Request)` → `findByGuidForVet` → 404 si null → `EstablishmentHealthPlanResource`.
- `cancel(Request)` → `findByGuidForVet` → 404 si null → `service->cancel()` catch `NotEditableException` → 422 `reason: not_editable`.
- `confirmActivity(ConfirmEstablishmentHealthPlanActivityRequest)`:
  ```php
  $plan = $this->service->findByGuidForVet($request->route('guid'), $vet->id); // 404 si null (scope tenant a nivel plan)
  $activity = $plan->activities->firstWhere('guid', $request->route('activityGuid')); // 404 si null (scope a nivel actividad DENTRO del plan)
  $profile = $request->attributes->get('current_profile');
  $activity = $this->service->confirmActivity($activity, $profile); // catch ConfirmationNotAllowedException -> 403 reason: role_not_allowed
  return $this->makeSuccess(new EstablishmentHealthPlanActivityResource($activity), 'Actividad confirmada correctamente.');
  ```

#### `back/app/Http/Controllers/V1/VetHealthPlanTemplateController.php`
Controller nuevo, solo lectura, reutiliza `HealthPlanTemplateService` sin cambios (RF-01):
- `index(App\Http\Requests\Health\IndexHealthPlanTemplateRequest)` → `HealthPlanTemplateListResource` paginado (misma request que usa el admin — validación idéntica, sin lógica tenant-específica que agregar).
- `show(string $guid)` → `findByGuid` → 404 si null → `HealthPlanTemplateResource`.

### Resources

#### `back/app/Http/Resources/V1/EstablishmentHealthPlanListResource.php`
```php
[
    'guid' => $this->guid,
    'client' => ['guid' => $this->client->guid, 'name' => $this->client->name],
    'establishment' => ['guid' => $this->establishment->guid, 'name' => $this->establishment->name],
    'template' => ['guid' => $this->template->guid, 'name' => $this->template->name],
    'year' => $this->year,
    'starts_on' => $this->starts_on->toDateString(),
    'ends_on' => $this->ends_on->toDateString(),
    'cancelled_at' => $this->cancelled_at?->toISOString(),
    'editable' => $this->editable,
    'activities_count' => $this->activities_count ?? $this->activities->count(),
    'pending_count' => $this->activities->where('confirmed_at', null)->count(), // o withCount condicional en repo
    'created_at' => $this->created_at?->toISOString(),
]
```

#### `back/app/Http/Resources/V1/EstablishmentHealthPlanResource.php`
Extiende lo anterior + `'activities' => EstablishmentHealthPlanActivityResource::collection($this->whenLoaded('activities'))`.

#### `back/app/Http/Resources/V1/EstablishmentHealthPlanActivityResource.php`
```php
[
    'guid' => $this->guid,
    'health_activity' => ['guid' => $this->activity->guid, 'name' => $this->activity->name],
    'month' => $this->month,
    'due_date' => $this->due_date->toDateString(),
    'status' => $this->status, // 'pending' | 'confirmed'
    'require_confirmation' => $this->require_confirmation,
    'confirmed_at' => $this->confirmed_at?->toISOString(),
    'confirmed_by' => $this->whenLoaded('confirmedBy', fn () => $this->confirmedBy
        ? ['guid' => $this->confirmedBy->guid, 'name' => $this->confirmedBy->user->name]
        : null),
    'sort_order' => $this->sort_order,
]
```

### Rutas API

#### `back/routes/api/establishment-health-plans.php` (nuevo)
```php
Route::prefix('v1/vets/{vet}/establishment-health-plans')->middleware(['auth:sanctum', 'vet.tenant'])->group(function () {
    Route::get('/', [EstablishmentHealthPlanController::class, 'index'])->middleware('can:establishment-health-plans.read');
    Route::post('/', [EstablishmentHealthPlanController::class, 'store'])->middleware('can:establishment-health-plans.create');
    Route::get('/{guid}', [EstablishmentHealthPlanController::class, 'show'])->middleware('can:establishment-health-plans.read');
    Route::post('/{guid}/cancel', [EstablishmentHealthPlanController::class, 'cancel'])->middleware('can:establishment-health-plans.update');
    Route::post('/{guid}/activities/{activityGuid}/confirm', [EstablishmentHealthPlanController::class, 'confirmActivity'])->middleware('can:establishment-health-plans.confirm');
});

// RF-01 — catálogo de solo lectura para el panel tenant (DEC-07: reutiliza el permiso .read)
Route::prefix('v1/vets/{vet}/health-plan-templates')->middleware(['auth:sanctum', 'vet.tenant'])->group(function () {
    Route::get('/', [VetHealthPlanTemplateController::class, 'index'])->middleware('can:establishment-health-plans.read');
    Route::get('/{guid}', [VetHealthPlanTemplateController::class, 'show'])->middleware('can:establishment-health-plans.read');
});
```
Registrar en `back/routes/api.php` junto al resto de los `require`.

### Permisos Spatie

#### `back/database/seeders/EstablishmentHealthPlanPermissionsSeeder.php` (nuevo, mismo esqueleto que `ProgramPermissionsSeeder`)
```php
$permissions = [
    'establishment-health-plans.read',
    'establishment-health-plans.create',
    'establishment-health-plans.update',
    'establishment-health-plans.confirm',
];
// crear Permission::firstOrCreate(...) para cada uno

$superAdmin->syncPermissions(Permission::all()); // consistente con el resto de módulos

// vet y vet-assistant: los 4 permisos (DU-08 + DU-07)
Role::where('name', 'vet')->first()?->givePermissionTo(Permission::whereIn('name', $permissions)->get());
Role::where('name', 'vet-assistant')->first()?->givePermissionTo(Permission::whereIn('name', $permissions)->get());

// vet-administrative: solo read/create/update — NUNCA confirm (DEC-06)
Role::where('name', 'vet-administrative')->first()?->givePermissionTo(
    Permission::whereIn('name', ['establishment-health-plans.read', 'establishment-health-plans.create', 'establishment-health-plans.update'])->get()
);

// client-owner / client-manager: SOLO confirm (DU-07) — ver Riesgos sobre el gap de portal
Role::where('name', 'client-owner')->first()?->givePermissionTo(Permission::whereIn('name', ['establishment-health-plans.confirm'])->get());
Role::where('name', 'client-manager')->first()?->givePermissionTo(Permission::whereIn('name', ['establishment-health-plans.confirm'])->get());
```
Registrar en `back/database/seeders/DatabaseSeeder.php`, en el primer `$this->call([...])`, inmediatamente después de `ProgramPermissionsSeeder::class` (mismo bloque, después de `RoleSeeder::class`).

### Contrato de los endpoints (resumen)

- `POST /v1/vets/{vet}/establishment-health-plans` → body `{ establishment_id, health_plan_template_id, year }` → 201 `EstablishmentHealthPlanResource`. Errores: 422 validación estándar, 422 `{reason: 'duplicate_plan'}`.
- `GET /v1/vets/{vet}/establishment-health-plans?client_id=&establishment_id=&cancelled=&search=&per_page=` → paginado `EstablishmentHealthPlanListResource[]`.
- `GET /v1/vets/{vet}/establishment-health-plans/{guid}` → `EstablishmentHealthPlanResource` con `activities`. 404 si no pertenece al vet.
- `POST /v1/vets/{vet}/establishment-health-plans/{guid}/cancel` → `EstablishmentHealthPlanResource`. 422 `{reason: 'not_editable'}` si ya cancelado.
- `POST /v1/vets/{vet}/establishment-health-plans/{guid}/activities/{activityGuid}/confirm` → `EstablishmentHealthPlanActivityResource`. 403 `{reason: 'role_not_allowed'}` si el rol del profile actual no está en `CONFIRM_ROLES`. Idempotente si ya estaba confirmada (200, sin cambios).
- `GET /v1/vets/{vet}/health-plan-templates` / `/{guid}` → mismos shapes que el catálogo admin (`HealthPlanTemplateListResource`/`HealthPlanTemplateResource`), solo lectura.

### Tests a generar

- `EstablishmentHealthPlanServiceTest`: materialización correcta (N actividades × M meses = filas esperadas), snapshot no cambia si se edita el template después, bloqueo de duplicado activo, permite re-instanciar tras cancelar, cálculo correcto de `starts_on`/`ends_on`/`due_date` para país AR (julio) vs. país no-AR (enero) — cubre regla dura #3 explícitamente.
- `EstablishmentHealthPlanControllerTest` (feature): scope multi-tenant (vet A no ve/edita planes de vet B — regla dura #4), 404 al acceder a plan de otro vet, 422 en duplicado, 422 en cancelar dos veces, `confirmActivity` 403 para rol no habilitado, 200 idempotente en doble confirmación, confirmación exitosa setea `confirmed_by`/`confirmed_at`.
- `VetHealthPlanTemplateControllerTest`: catálogo visible en modo lectura, sin rutas de escritura expuestas (RF-01 AC #2).
- `HealthPlanYearTest` (unit): `range()`/`dateForMonth()` para AR y para un país sin regla especial (ej. MX), casos límite mes < mes de inicio (cruza año calendario).

## Cambios en FRONTEND

### Módulo nuevo: `front/src/modules/establishment-health-plans/`

#### `api/establishment-health-plans.api.ts`
`listEstablishmentHealthPlansApi`, `getEstablishmentHealthPlanApi`, `createEstablishmentHealthPlanApi`, `cancelEstablishmentHealthPlanApi`, `confirmEstablishmentHealthPlanActivityApi` — mismo patrón que `program.api.ts` (funciones que reciben `vetGuid` + params/payload, devuelven `res.data`).

#### `api/health-plan-templates-catalog.api.ts`
`listHealthPlanTemplatesCatalogApi(vetGuid, params)`, `getHealthPlanTemplateCatalogApi(vetGuid, guid)` → pegan a `/v1/vets/{vet}/health-plan-templates`.

#### `types/establishment-health-plan.types.ts`
```ts
export interface EstablishmentHealthPlanRef { guid: string; name: string }

export interface EstablishmentHealthPlanActivity {
  guid: string
  health_activity: { guid: string; name: string }
  month: number
  due_date: string
  status: 'pending' | 'confirmed'
  require_confirmation: boolean
  confirmed_at: string | null
  confirmed_by: { guid: string; name: string } | null
  sort_order: number
}

export interface EstablishmentHealthPlanListItem {
  guid: string
  client: EstablishmentHealthPlanRef
  establishment: EstablishmentHealthPlanRef
  template: EstablishmentHealthPlanRef
  year: number
  starts_on: string
  ends_on: string
  cancelled_at: string | null
  editable: boolean
  activities_count: number
  pending_count: number
  created_at: string
}

export interface EstablishmentHealthPlanDetail extends EstablishmentHealthPlanListItem {
  activities: EstablishmentHealthPlanActivity[]
}

export interface EstablishmentHealthPlanListParams {
  client_id?: string
  establishment_id?: string
  cancelled?: boolean
  search?: string
  page?: number
  per_page?: number
}

export interface CreateEstablishmentHealthPlanPayload {
  establishment_id: string
  health_plan_template_id: string
  year: number
}

export interface EstablishmentHealthPlanNotEditableError { reason: 'not_editable' }
export interface EstablishmentHealthPlanDuplicateError { reason: 'duplicate_plan' }
export interface EstablishmentHealthPlanRoleNotAllowedError { reason: 'role_not_allowed' }
```
Reutiliza `HealthPlanTemplateListItem`/`HealthPlanTemplate` ya definidos en `front/src/modules/health/types/health.types.ts` para el catálogo (import cruzado, sin duplicar tipos).

#### `validators/establishment-health-plan.validator.ts`
```ts
export const establishmentHealthPlanSchema = z.object({
  establishment_id: z.string().uuid('Seleccioná un establecimiento'),
  health_plan_template_id: z.string().uuid('Seleccioná una plantilla'),
  year: z.number().int().min(2020).max(new Date().getFullYear() + 1),
})
export type EstablishmentHealthPlanFormValues = z.infer<typeof establishmentHealthPlanSchema>
```

#### `composables/`
- `useEstablishmentHealthPlanList.ts` — `useQuery` con `queryKey: ['establishment-health-plans', vetGuid, filters]`.
- `useEstablishmentHealthPlanDetail.ts` — `useQuery` con `queryKey: ['establishment-health-plan', vetGuid, guid]`.
- `useHealthPlanTemplateCatalog.ts` — `useQuery` de solo lectura para el picker de templates.
- `useEstablishmentHealthPlanMutations.ts` — `useCreateEstablishmentHealthPlan`, `useCancelEstablishmentHealthPlan` (+ `useCancelWithModal`, mismo wrapper que `useCancelProgramWithModal`), `useConfirmEstablishmentHealthPlanActivity` (invalida `['establishment-health-plan', vetGuid, guid]` al confirmar, para refrescar el estado de la fila sin recargar toda la lista).

#### `components/tenant/`
- `EstablishmentHealthPlanForm.vue` (o `form-sections/EstablishmentHealthPlanClientSection.vue` + `.../EstablishmentHealthPlanTemplateSection.vue`) — selector cliente→establecimiento (mismo patrón que `ProgramClientSection.vue`), selector de template del catálogo (con preview de actividades/meses reutilizando la data de `ActivityMonthMatrix` pero en modo solo-lectura), input de año.
- `EstablishmentHealthPlanCalendar.vue` — **componente nuevo**, matriz mes×actividad de solo lectura con estado por celda (pendiente/confirmada) + botón "Confirmar" por fila, envuelto en `<PermissionGuard permission="establishment-health-plans.confirm">`. No reutiliza `ActivityMonthMatrix.vue` porque ese componente es editable/drag-n-drop para definir el template (audiencia admin); acá se necesita una vista de estado, no de edición.
- `EstablishmentHealthPlanCancelModal.vue` — mismo patrón que `ProgramCancelModal.vue`.
- `EstablishmentHealthPlansTable.vue` — `BaseDataTable` con columnas cliente/establecimiento/template/año/estado, acciones vía `BaseTableActions` (ver detalle, cancelar).

#### `pages/tenant/`
- `VetEstablishmentHealthPlansListPage.vue`
- `VetEstablishmentHealthPlanNewPage.vue`
- `VetEstablishmentHealthPlanDetailPage.vue` (muestra `EstablishmentHealthPlanCalendar`)

#### `router/vet-establishment-health-plans.routes.ts`
```ts
export const vetEstablishmentHealthPlansRoutes: RouteRecordRaw[] = [
  { path: 'health-plans', name: 'vet-health-plans-list', component: () => import('@/modules/establishment-health-plans/pages/tenant/VetEstablishmentHealthPlansListPage.vue'), meta: { requiresAuth: true, title: 'Planes Sanitarios' } },
  { path: 'health-plans/new', name: 'vet-health-plans-new', component: () => import('@/modules/establishment-health-plans/pages/tenant/VetEstablishmentHealthPlanNewPage.vue'), meta: { requiresAuth: true, title: 'Nuevo plan sanitario' } },
  { path: 'health-plans/:guid', name: 'vet-health-plans-detail', component: () => import('@/modules/establishment-health-plans/pages/tenant/VetEstablishmentHealthPlanDetailPage.vue'), meta: { requiresAuth: true, title: 'Detalle de plan sanitario' } },
]
```
Registrar el spread `...vetEstablishmentHealthPlansRoutes` en `front/src/modules/vets/router/vets-tenant.routes.ts`, junto a `...vetProgramsRoutes`.

#### `stores/establishment-health-plan-ui.store.ts`
UI state del listado: `client_guid`/`establishment_guid`/`cancelled` filtros activos (persisten entre navegaciones dentro de la sesión, mismo rol que otros `-ui.store.ts` del proyecto — no server state).

### Permisos en UI

- Botón "Instanciar plan" / ruta `new` → `<PermissionGuard permission="establishment-health-plans.create">`.
- Botón "Cancelar" → `<PermissionGuard permission="establishment-health-plans.update">`.
- Botón "Confirmar" por actividad → `<PermissionGuard permission="establishment-health-plans.confirm">`.

### Tests a generar

- `useEstablishmentHealthPlanMutations` — invalidación de queries correcta tras crear/cancelar/confirmar.
- `EstablishmentHealthPlanCalendar.vue` — render de estado pending/confirmed, botón deshabilitado si `!require_confirmation` o ya confirmada.
- Validación zod del formulario de instanciación (año fuera de rango, guids faltantes).

## Orden de implementación

1. Migrations (`establishment_health_plans`, `establishment_health_plan_activities`) + `php artisan migrate`.
2. Modelos `EstablishmentHealthPlan`, `EstablishmentHealthPlanActivity` + `App\Support\HealthPlanYear`.
3. `EstablishmentHealthPlanRepositoryInterface` + `EstablishmentHealthPlanRepositoryEloquent` + binding en `AppServiceProvider`.
4. Excepciones (`AlreadyExists`, `NotEditable`, `ConfirmationNotAllowed`).
5. `EstablishmentHealthPlanService` (create con materialización batch, cancel, confirmActivity).
6. FormRequests (`Index`, `Store`, `Confirm`).
7. Resources (`List`, detail, `Activity`).
8. `EstablishmentHealthPlanController` + `VetHealthPlanTemplateController`.
9. Rutas `back/routes/api/establishment-health-plans.php` + registrar en `api.php`.
10. `EstablishmentHealthPlanPermissionsSeeder` + registrar en `DatabaseSeeder` + correr seeders en local.
11. Tests backend (Service, Controller feature, `HealthPlanYear` unit) — correr `qa-backend` antes de pasar a frontend.
12. Módulo frontend: `types/` + `validators/` + `api/` (ambos: plans + catálogo).
13. Composables (`useEstablishmentHealthPlanList/Detail/Mutations`, `useHealthPlanTemplateCatalog`).
14. Componentes (`EstablishmentHealthPlanForm`, `Calendar`, `CancelModal`, `Table`).
15. Páginas + rutas, registrar en `vets-tenant.routes.ts`.
16. Store UI de filtros.
17. Tests frontend + `qa-frontend`.

## Riesgos y consideraciones

- **RIESGO CRÍTICO — no existe portal de autenticación tenant para `client-owner`/`client-manager` hoy.** Verificado en código: `EnsureUserBelongsToVet::handle()` resuelve `current_profile` únicamente vía `UserProfileRepositoryEloquent::findForUserAndVet()`, que filtra `authenticatable_type = 'vet'`. Los perfiles de `client-owner`/`client-manager` tienen `authenticatable_type = 'client'` y **nunca** son resueltos por ese middleware — hoy no hay ningún endpoint (`vet.tenant` ni ningún otro) al que esos roles puedan autenticarse y llegar. DU-07 (Opción A) aprobó esos 2 roles para confirmar actividades; el plan implementa el check de rol correctamente (`CONFIRM_ROLES` incluye ambos, permiso Spatie otorgado), de modo que funcione el día que exista un portal cliente — pero en la práctica, en esta Fase 1, **solo `vet` y `vet-assistant` podrán ejecutar la confirmación** por la vía de este endpoint. Esto no es un bug de este plan: es un gap preexistente de la plataforma (nunca se construyó un portal cliente) que excede el alcance de esta feature. Si el negocio necesita que `client-owner`/`client-manager` confirmen de verdad en Fase 1, hace falta un ticket aparte para un portal/autenticación cliente — decisión que debe escalarse antes de prometer esa capacidad a un usuario final.
- **Discrepancia de documentación — vocabulario de roles de `ProtocolAlert.roles`.** `sav-domain-rules.md` y `veterinary-domain.md` afirman que los valores válidos son 4 (`vet`, `vet-assistant`, `client-owner`, `client-manager`). El código real (`StoreProtocolRequest::$tenantRoles`) permite 6, sumando `vet-administrative` y `client-administrative`. DU-07 se contestó explícitamente sobre el set de 4 nombrado en la pregunta original — se implementó tal cual eso (`CONFIRM_ROLES` con 4 valores), no los 6 del código real de `Protocol`. Si el negocio en algún momento quiere alinear ambos vocabularios, es una decisión aparte.
- **Discrepancia de documentación — naming de Repository/Service/Permisos.** `backend-conventions.md` describe `I{Nombre}Repository` y permisos `lectura/alta/modificacion/baja`; el código real usa `{Nombre}RepositoryInterface`/`RepositoryEloquent` y permisos `read/create/update/delete`. Este plan sigue el código real (DEC-02, DEC-03). Vale la pena actualizar el skill doc en un ticket aparte para que deje de inducir a error a la próxima feature.
- **`HealthPlanTemplate::destroy()` no tiene guard aplicativo de "en uso".** A diferencia de lo que sugiere la spec funcional ("análogo a DEC-08 del catálogo admin"), no encontré ese guard en `HealthPlanTemplateService::destroy()` — solo hace `$this->templateRepo->destroy($template)` sin chequeo previo. Con la FK `restrictOnDelete()` que agrega este plan, borrar un template referenciado por un `EstablishmentHealthPlan` fallará con una excepción de integridad genérica (capturada igual por el `makeFromException` estándar, pero sin mensaje de negocio claro tipo "no se puede borrar, está en uso"). Aceptable para Fase 1 (el panel admin de templates no es parte de este alcance, DU-03), pero es candidato a mejora si en el futuro se vuelve un caso de uso real.
- **Multi-país**: si `Client.country_id` queda `null` (¿es nullable hoy? no confirmado con certeza en el tiempo de esta exploración), `HealthPlanYear` no podría resolver el mes de inicio. El `StoreEstablishmentHealthPlanRequest` valida explícitamente este caso y devuelve un 422 claro en vez de un 500 — pero vale la pena que el dev confirme si `country_id` es realmente NOT NULL a nivel de negocio (creación de `Client`) antes de asumir que este edge case es solo defensivo y nunca ocurre en la práctica.
- **Catálogo global sin `country_id`** (ya señalado en la spec, riesgo no resuelto por este plan — explícitamente fuera de alcance de Fase 1 por DU-03/DEC-04 del catálogo admin). Un template instanciado en un establecimiento de un cliente no-AR aplicará los mismos meses pensados para AR. Aceptable porque el mercado inicial es 100% AR; bloqueante antes de habilitar clientes de otro país.
- **Performance**: la materialización usa `insert()` bulk (no `Model::create()` en loop) para cumplir la NFR explícita de la spec — recordar que esto bypassa `HasGuid::bootHasGuid()` y el auto-timestamp de Eloquent; el Service debe setear `guid`/`created_at`/`updated_at` a mano en cada fila del array, igual que ya hacen los seeders del proyecto.

## Pendientes / fuera de alcance

- Fase 2: `HealthPlanMonthMessageBuilder`, listener sobre `EstablishmentHealthPlan`/`EstablishmentHealthPlanActivity`, conexión con `AlertType::HealthPlanMonth` y el pipeline `DispatchDueAlerts`/`DeliverAlertJob` — no se toca en este plan.
- Portal de autenticación para `client-owner`/`client-manager` (ver Riesgo crítico) — condición previa real para que DU-07 tenga efecto completo.
- `country_id` en el catálogo (`HealthPlanTemplate`/`HealthActivity`) para soportar meses distintos por país en la misma plantilla — necesario antes de expandir fuera de AR.
- Guard de "en uso" en `HealthPlanTemplateService::destroy()` — mejora de UX admin, no bloqueante para Fase 1.
- Edición de un `EstablishmentHealthPlan` ya instanciado (cambiar template/año) — no está en las RF, no se implementa.
