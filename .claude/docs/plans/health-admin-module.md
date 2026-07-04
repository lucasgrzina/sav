# Plan técnico: Módulo Sanidad — Catálogos SuperAdmin

## Input procesado

Brief informal del usuario en el chat con contexto funcional completo, decisiones previas tomadas y shape de payload definido.

---

## Resumen ejecutivo

Se implementa el módulo `health` (Sanidad Animal) desde cero en backend Laravel y frontend Vue 3. Cubre tres entidades de catálogo de plataforma accesibles únicamente por SuperAdmin: `HealthActivity` (actividades sanitarias), `HealthPlanCategory` (categorías de planes) y `HealthPlanTemplate` (plantillas de planes con actividades N:M). La relación N:M entre `HealthPlanTemplate` y `HealthActivity` usa una tabla pivot `health_plan_template_activity` con columna `months` de tipo JSON (array de enteros). No hay datos de tenant en este módulo: son catálogos globales de la plataforma. El frontend usa el patrón de página única con `BaseDrawer` para create/edit (igual que tutoriales, no rutas separadas), más un componente matricial `ActivityMonthMatrix.vue` para la asignación de actividades por mes.

---

## Decisiones tomadas

**DEC-01 — Sin SoftDeletes**
Decisión: Las tres entidades NO usan `SoftDeletes`. Hard delete en todos los modelos.
Justificación: El proyecto no usa soft deletes en ningún modelo existente (confirmado por grep: cero ocurrencias de `SoftDeletes` en `back/app/Models/`). El brief menciona "soft delete" pero el código real es la fuente de verdad. Marcado como discrepancia.
Alternativa descartada: `SoftDeletes` — inconsistente con todo el codebase.

**DEC-02 — `months` como columna `json` en el pivot, cast a array**
Decisión: `$table->json('months')` en la migración del pivot. Cast `'months' => 'array'` en el modelo pivot `HealthPlanTemplateActivity`. En la API recibe y devuelve `[1, 3, 6, 9]` (array de enteros).
Justificación: Decisión ya tomada en el brief. Confirmada por ausencia de queries por mes individual y por el patrón de `sync()` que carga el pivot completo.
Alternativa descartada: Columna varchar con string `"1,3,6,9"` (legacy), bitmask, filas separadas.

**DEC-03 — Pivot model `HealthPlanTemplateActivity` como clase separada**
Decisión: Se crea `back/app/Models/HealthPlanTemplateActivity.php` extendiendo `Illuminate\Database\Eloquent\Relations\Pivot`. Permite definir el cast de `months` y acceder al pivot desde la relación con `->withPivot('months')->using(HealthPlanTemplateActivity::class)`.
Justificación: El cast de JSON en el pivot requiere un modelo Pivot explícito. Sin él, `months` llegaría como string JSON sin deserializar.
Alternativa descartada: Usar `->withPivot('months')` sin modelo pivot y castear manualmente en el Resource — más frágil.

**DEC-04 — No hay `guid` en la tabla pivot**
Decisión: La tabla `health_plan_template_activity` usa clave primaria compuesta `(health_plan_template_id, health_activity_id)`. No tiene `guid` propio. La actividad se identifica por `health_activity_guid` en el payload y se resuelve internamente por `id`.
Justificación: La pivot es un join table puro. No se expone como recurso independiente. El guid de la actividad ya identifica la relación sin ambigüedad.
Alternativa descartada: `guid` en pivot — sobreingeniería innecesaria.

**DEC-05 — Patrón Drawer para create/edit (no rutas separadas)**
Decisión: Una sola página por entidad (`HealthActivityListPage.vue`, `HealthPlanCategoryListPage.vue`, `HealthPlanTemplateListPage.vue`), con `BaseDrawer` para los formularios de create y edit. Mismo patrón que tutoriales (una ruta, modales/drawers).
Justificación: Las tres entidades son catálogos simples sin detalle propio que justifique rutas adicionales. El componente `BaseDrawer` existe en el proyecto. El brief dice "drawer de create/edit" explícitamente.
Alternativa descartada: Rutas separadas Create/Edit/Detail (patrón técnicas) — sobreingeniería para catálogos simples.

**DEC-06 — Un archivo de rutas único `health-admin.php`**
Decisión: Todas las rutas de las tres entidades van en `back/routes/api/health-admin.php`.
Justificación: Las tres entidades son del mismo dominio (Sanidad), solo SuperAdmin, sin rutas de lectura para panel vet todavía. Agruparlas reduce la cantidad de archivos y el glob las carga automáticamente.
Alternativa descartada: Un archivo por entidad — fragmentación innecesaria para este módulo.

**DEC-07 — Sync de activities en template via `belongsToMany()->sync()`**
Decisión: El servicio resuelve los GUIDs de actividades a IDs internos y llama `$template->activities()->sync($activityIdsWithMonths)` donde `$activityIdsWithMonths` es `[id => ['months' => json_encode([...])]]`.
Justificación: `sync()` de Laravel maneja el diff (inserta nuevos, elimina los que no están, actualiza los que cambiaron) en una sola operación. El pivot no tiene guid propio, así que `sync()` es la operación correcta.
Alternativa descartada: Eliminar y reinsertar manualmente — más código, sin ventaja.

**DEC-08 — Validación de delete: bloquear si hay templates en una categoría o actividad usada**
Decisión: Antes de eliminar una `HealthPlanCategory`, verificar si tiene templates. Antes de eliminar una `HealthActivity`, verificar si está en algún template. Si hay vínculos, retornar 422 con mensaje explicativo.
Justificación: Eliminar una categoría con templates dejaría templates huérfanos. Eliminar una actividad usada en templates rompe la integridad del catálogo.
Alternativa descartada: Cascade delete — destruiría datos de catálogo sin advertir al usuario.

**DEC-09 — Permisos con prefijo `health-activities`, `health-plan-categories`, `health-plan-templates`**
Decisión: Los permisos siguen el patrón `{slug}.read|create|update|delete`:
- `health-activities.read`, `health-activities.create`, `health-activities.update`, `health-activities.delete`
- `health-plan-categories.read`, `health-plan-categories.create`, `health-plan-categories.update`, `health-plan-categories.delete`
- `health-plan-templates.read`, `health-plan-templates.create`, `health-plan-templates.update`, `health-plan-templates.delete`
Justificación: Consistente con el patrón existente (`support-messages.read`, `clients.owners.read`). Inglés, kebab-case para slugs multi-palabra.
Alternativa descartada: `sanidad.*` o `health.*` genérico — no granular por entidad.

**DEC-10 — No hay endpoint de lectura para el panel Vet en esta iteración**
Decisión: Solo se crean endpoints bajo `/v1/admin/`. Los endpoints de lectura para el panel vet (para que los vets usen los planes sanitarios) se implementan cuando exista el módulo de Planes Sanitarios por establecimiento.
Justificación: No hay consumidor de esa API todavía. El brief no lo pide.
Alternativa descartada: Crear endpoints `/v1/health-*` de lectura preventivos — YAGNI.

**DEC-11 — `ActivityMonthMatrix` recibe activities del catálogo + modelValue con asignaciones**
Decisión: El componente recibe `availableActivities: HealthActivity[]` (lista completa del catálogo) y usa `defineModel<ActivityAssignment[]>()` donde `ActivityAssignment = { health_activity_guid: string, months: number[] }`. Renderiza una tabla donde filas = actividades, columnas = meses 1-12, checkboxes en intersecciones.
Justificación: Desacopla la carga del catálogo (responsabilidad del padre) de la lógica de la matriz. El modelo de datos coincide exactamente con el shape del payload de template.
Alternativa descartada: Manejar estado interno de la matriz sin v-model — rompe la integración con vee-validate.

---

## Cambios en BACKEND

### Archivos a crear

#### `back/database/migrations/2026_06_23_100001_create_health_activities_table.php`
**Propósito:** Crear tabla de catálogo de actividades sanitarias.
```php
Schema::create('health_activities', function (Blueprint $table) {
    $table->id();
    $table->string('guid', 36)->unique();
    $table->string('name', 255);
    $table->string('description', 500)->nullable();
    $table->timestamps();
});
```
**Reversible:** `Schema::dropIfExists('health_activities')`.

---

#### `back/database/migrations/2026_06_23_100002_create_health_plan_categories_table.php`
**Propósito:** Crear tabla de categorías de planes sanitarios.
```php
Schema::create('health_plan_categories', function (Blueprint $table) {
    $table->id();
    $table->string('guid', 36)->unique();
    $table->string('name', 255);
    $table->string('description', 500)->nullable();
    $table->timestamps();
});
```
**Reversible:** `Schema::dropIfExists('health_plan_categories')`.

---

#### `back/database/migrations/2026_06_23_100003_create_health_plan_templates_table.php`
**Propósito:** Crear tabla de plantillas de planes sanitarios.
```php
Schema::create('health_plan_templates', function (Blueprint $table) {
    $table->id();
    $table->string('guid', 36)->unique();
    $table->string('name', 255);
    $table->unsignedBigInteger('health_plan_category_id');
    $table->timestamps();

    $table->foreign('health_plan_category_id')
          ->references('id')
          ->on('health_plan_categories')
          ->cascadeOnDelete();

    $table->index('health_plan_category_id');
});
```
**Nota:** `cascadeOnDelete()` porque si se elimina la categoría (ya bloqueado desde el servicio) a nivel DB debe ser consistente. El servicio bloquea la eliminación de categorías con templates.
**Reversible:** `Schema::dropIfExists('health_plan_templates')`.

---

#### `back/database/migrations/2026_06_23_100004_create_health_plan_template_activity_table.php`
**Propósito:** Tabla pivot N:M entre templates y actividades, con columna `months` JSON.
```php
Schema::create('health_plan_template_activity', function (Blueprint $table) {
    $table->foreignId('health_plan_template_id')
          ->constrained('health_plan_templates')
          ->cascadeOnDelete();
    $table->foreignId('health_activity_id')
          ->constrained('health_activities')
          ->cascadeOnDelete();
    $table->json('months');  // array de enteros [1,3,6,9]

    $table->primary(['health_plan_template_id', 'health_activity_id']);
});
```
**Nota:** Sin timestamps en el pivot. `cascadeOnDelete()` en ambas FKs: si se borra un template o una actividad, se eliminan las asignaciones del pivot.
**Reversible:** `Schema::dropIfExists('health_plan_template_activity')`.

---

#### `back/app/Models/HealthActivity.php`
**Propósito:** Modelo de actividad sanitaria con HasGuid.
```php
namespace App\Models;

use App\Traits\HasGuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class HealthActivity extends Model
{
    use HasGuid;

    protected $fillable = ['name', 'description'];
    protected $hidden   = ['id'];

    public function templates(): BelongsToMany
    {
        return $this->belongsToMany(
            HealthPlanTemplate::class,
            'health_plan_template_activity',
            'health_activity_id',
            'health_plan_template_id',
        )->withPivot('months')->using(HealthPlanTemplateActivity::class);
    }
}
```

---

#### `back/app/Models/HealthPlanCategory.php`
**Propósito:** Modelo de categoría de plan sanitario con HasGuid.
```php
namespace App\Models;

use App\Traits\HasGuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HealthPlanCategory extends Model
{
    use HasGuid;

    protected $fillable = ['name', 'description'];
    protected $hidden   = ['id'];

    public function templates(): HasMany
    {
        return $this->hasMany(HealthPlanTemplate::class);
    }
}
```

---

#### `back/app/Models/HealthPlanTemplate.php`
**Propósito:** Modelo de plantilla de plan sanitario con relación N:M a actividades.
```php
namespace App\Models;

use App\Traits\HasGuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class HealthPlanTemplate extends Model
{
    use HasGuid;

    protected $fillable = ['name', 'health_plan_category_id'];
    protected $hidden   = ['id'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(HealthPlanCategory::class, 'health_plan_category_id');
    }

    public function activities(): BelongsToMany
    {
        return $this->belongsToMany(
            HealthActivity::class,
            'health_plan_template_activity',
            'health_plan_template_id',
            'health_activity_id',
        )->withPivot('months')->using(HealthPlanTemplateActivity::class);
    }
}
```

---

#### `back/app/Models/HealthPlanTemplateActivity.php`
**Propósito:** Modelo Pivot explícito para castear `months` de JSON a array PHP.
```php
namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class HealthPlanTemplateActivity extends Pivot
{
    public $incrementing = false;
    public $timestamps   = false;

    protected $casts = [
        'months' => 'array',  // JSON [1,3,6,9] → array PHP
    ];
}
```
**Nota:** `$timestamps = false` porque la tabla pivot no tiene `created_at`/`updated_at`.

---

#### `back/app/Contracts/Repositories/HealthActivityRepositoryInterface.php`
**Propósito:** Contrato del repositorio de actividades sanitarias.
```php
namespace App\Contracts\Repositories;

use App\Models\HealthActivity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

interface HealthActivityRepositoryInterface
{
    public function paginate(array $filters, int $perPage): LengthAwarePaginator;
    public function findByGuid(string $guid): ?HealthActivity;
    public function findByGuidOrFail(string $guid): HealthActivity;
    public function resolveGuidsToIds(array $guids): array;  // ['guid' => id, ...]
    public function listAll(): Collection;
    public function create(array $data): HealthActivity;
    public function update(Model $model, array $data): Model;
    public function destroy(Model $model): bool|null;
    public function isUsedInTemplates(HealthActivity $activity): bool;
}
```

---

#### `back/app/Contracts/Repositories/HealthPlanCategoryRepositoryInterface.php`
**Propósito:** Contrato del repositorio de categorías de planes.
```php
namespace App\Contracts\Repositories;

use App\Models\HealthPlanCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

interface HealthPlanCategoryRepositoryInterface
{
    public function paginate(array $filters, int $perPage): LengthAwarePaginator;
    public function findByGuid(string $guid): ?HealthPlanCategory;
    public function listAll(): Collection;
    public function create(array $data): HealthPlanCategory;
    public function update(Model $model, array $data): Model;
    public function destroy(Model $model): bool|null;
    public function hasTemplates(HealthPlanCategory $category): bool;
}
```

---

#### `back/app/Contracts/Repositories/HealthPlanTemplateRepositoryInterface.php`
**Propósito:** Contrato del repositorio de plantillas de planes.
```php
namespace App\Contracts\Repositories;

use App\Models\HealthPlanTemplate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

interface HealthPlanTemplateRepositoryInterface
{
    public function paginate(array $filters, int $perPage): LengthAwarePaginator;
    public function findByGuid(string $guid): ?HealthPlanTemplate;
    public function create(array $data): HealthPlanTemplate;
    public function update(Model $model, array $data): Model;
    public function destroy(Model $model): bool|null;
    // Sincroniza el pivot. $activityData = [activity_id => ['months' => '[1,3]']]
    public function syncActivities(HealthPlanTemplate $template, array $activityData): void;
}
```

---

#### `back/app/Repositories/HealthActivityRepositoryEloquent.php`
**Propósito:** Implementación Eloquent del repositorio de actividades.
```php
namespace App\Repositories;

use App\Contracts\Repositories\HealthActivityRepositoryInterface;
use App\Models\HealthActivity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class HealthActivityRepositoryEloquent extends BaseRepositoryEloquent
    implements HealthActivityRepositoryInterface
{
    protected function model(): string { return HealthActivity::class; }

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = $this->newQuery();
        if (!empty($filters['search'])) {
            $query->where('name', 'like', '%' . $filters['search'] . '%');
        }
        return $query->orderBy('name')->paginate($perPage);
    }

    public function findByGuid(string $guid): ?HealthActivity
    {
        /** @var HealthActivity|null */
        return $this->newQuery()->where('guid', $guid)->first();
    }

    public function findByGuidOrFail(string $guid): HealthActivity
    {
        /** @var HealthActivity */
        return $this->newQuery()->where('guid', $guid)->firstOrFail();
    }

    /**
     * Recibe array de guids, devuelve map ['guid' => id].
     * Los guids no encontrados no aparecen en el resultado.
     */
    public function resolveGuidsToIds(array $guids): array
    {
        return $this->newQuery()
            ->whereIn('guid', $guids)
            ->pluck('id', 'guid')
            ->all();
    }

    public function listAll(): Collection
    {
        return $this->newQuery()->orderBy('name')->get();
    }

    public function create(array $data): HealthActivity
    {
        /** @var HealthActivity */
        return parent::create($data);
    }

    public function isUsedInTemplates(HealthActivity $activity): bool
    {
        return $activity->templates()->exists();
    }
}
```

---

#### `back/app/Repositories/HealthPlanCategoryRepositoryEloquent.php`
**Propósito:** Implementación Eloquent del repositorio de categorías.
```php
namespace App\Repositories;

use App\Contracts\Repositories\HealthPlanCategoryRepositoryInterface;
use App\Models\HealthPlanCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class HealthPlanCategoryRepositoryEloquent extends BaseRepositoryEloquent
    implements HealthPlanCategoryRepositoryInterface
{
    protected function model(): string { return HealthPlanCategory::class; }

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = $this->newQuery()->withCount('templates');
        if (!empty($filters['search'])) {
            $query->where('name', 'like', '%' . $filters['search'] . '%');
        }
        return $query->orderBy('name')->paginate($perPage);
    }

    public function findByGuid(string $guid): ?HealthPlanCategory
    {
        /** @var HealthPlanCategory|null */
        return $this->newQuery()->where('guid', $guid)->first();
    }

    public function listAll(): Collection
    {
        return $this->newQuery()->orderBy('name')->get();
    }

    public function create(array $data): HealthPlanCategory
    {
        /** @var HealthPlanCategory */
        return parent::create($data);
    }

    public function hasTemplates(HealthPlanCategory $category): bool
    {
        return $category->templates()->exists();
    }
}
```

---

#### `back/app/Repositories/HealthPlanTemplateRepositoryEloquent.php`
**Propósito:** Implementación Eloquent del repositorio de plantillas.
```php
namespace App\Repositories;

use App\Contracts\Repositories\HealthPlanTemplateRepositoryInterface;
use App\Models\HealthPlanTemplate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

class HealthPlanTemplateRepositoryEloquent extends BaseRepositoryEloquent
    implements HealthPlanTemplateRepositoryInterface
{
    protected function model(): string { return HealthPlanTemplate::class; }

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = $this->newQuery()
            ->with('category')
            ->withCount('activities');

        if (!empty($filters['search'])) {
            $query->where('name', 'like', '%' . $filters['search'] . '%');
        }
        if (!empty($filters['health_plan_category_guid'])) {
            $query->whereHas('category', function ($q) use ($filters) {
                $q->where('guid', $filters['health_plan_category_guid']);
            });
        }
        return $query->orderBy('name')->paginate($perPage);
    }

    public function findByGuid(string $guid): ?HealthPlanTemplate
    {
        /** @var HealthPlanTemplate|null */
        return $this->newQuery()
            ->with(['category', 'activities'])
            ->where('guid', $guid)
            ->first();
    }

    public function create(array $data): HealthPlanTemplate
    {
        /** @var HealthPlanTemplate */
        return parent::create($data);
    }

    /**
     * Sincroniza el pivot health_plan_template_activity.
     *
     * @param  array  $activityData  Formato: [activity_id => ['months' => '[1,3,6]']]
     *                               months ya viene como JSON string para guardar en BD.
     */
    public function syncActivities(HealthPlanTemplate $template, array $activityData): void
    {
        $template->activities()->sync($activityData);
    }
}
```

---

#### `back/app/Services/HealthActivityService.php`
**Propósito:** Lógica de negocio para actividades sanitarias.
```php
namespace App\Services;

use App\Contracts\Repositories\HealthActivityRepositoryInterface;
use App\Models\HealthActivity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class HealthActivityService
{
    public function __construct(
        private HealthActivityRepositoryInterface $repo,
    ) {}

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->repo->paginate($filters, $perPage);
    }

    public function listAll(): Collection
    {
        return $this->repo->listAll();
    }

    public function findByGuid(string $guid): ?HealthActivity
    {
        return $this->repo->findByGuid($guid);
    }

    public function create(array $data): HealthActivity
    {
        return $this->repo->create([
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
        ]);
    }

    public function update(HealthActivity $activity, array $data): HealthActivity
    {
        /** @var HealthActivity */
        return $this->repo->update($activity, [
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
        ]);
    }

    /**
     * @throws \RuntimeException si la actividad está en uso en algún template
     */
    public function destroy(HealthActivity $activity): void
    {
        if ($this->repo->isUsedInTemplates($activity)) {
            throw new \RuntimeException(
                'La actividad sanitaria está en uso en una o más plantillas y no puede eliminarse.'
            );
        }
        $this->repo->destroy($activity);
    }
}
```

---

#### `back/app/Services/HealthPlanCategoryService.php`
**Propósito:** Lógica de negocio para categorías de planes sanitarios.
```php
namespace App\Services;

use App\Contracts\Repositories\HealthPlanCategoryRepositoryInterface;
use App\Models\HealthPlanCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class HealthPlanCategoryService
{
    public function __construct(
        private HealthPlanCategoryRepositoryInterface $repo,
    ) {}

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->repo->paginate($filters, $perPage);
    }

    public function listAll(): Collection
    {
        return $this->repo->listAll();
    }

    public function findByGuid(string $guid): ?HealthPlanCategory
    {
        return $this->repo->findByGuid($guid);
    }

    public function create(array $data): HealthPlanCategory
    {
        return $this->repo->create([
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
        ]);
    }

    public function update(HealthPlanCategory $category, array $data): HealthPlanCategory
    {
        /** @var HealthPlanCategory */
        return $this->repo->update($category, [
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
        ]);
    }

    /**
     * @throws \RuntimeException si la categoría tiene plantillas asociadas
     */
    public function destroy(HealthPlanCategory $category): void
    {
        if ($this->repo->hasTemplates($category)) {
            throw new \RuntimeException(
                'La categoría tiene plantillas asociadas y no puede eliminarse.'
            );
        }
        $this->repo->destroy($category);
    }
}
```

---

#### `back/app/Services/HealthPlanTemplateService.php`
**Propósito:** Lógica de negocio para plantillas. Orquesta resolución de GUIDs y sync del pivot.
```php
namespace App\Services;

use App\Contracts\Repositories\HealthActivityRepositoryInterface;
use App\Contracts\Repositories\HealthPlanCategoryRepositoryInterface;
use App\Contracts\Repositories\HealthPlanTemplateRepositoryInterface;
use App\Models\HealthPlanTemplate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class HealthPlanTemplateService
{
    public function __construct(
        private HealthPlanTemplateRepositoryInterface  $templateRepo,
        private HealthPlanCategoryRepositoryInterface  $categoryRepo,
        private HealthActivityRepositoryInterface      $activityRepo,
    ) {}

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->templateRepo->paginate($filters, $perPage);
    }

    public function findByGuid(string $guid): ?HealthPlanTemplate
    {
        return $this->templateRepo->findByGuid($guid);
    }

    /**
     * Crea un template con sus actividades asignadas.
     *
     * @param  array  $data  Validated. Incluye 'health_plan_category_guid' y 'activities'.
     * @throws \RuntimeException si la categoría no existe
     */
    public function create(array $data): HealthPlanTemplate
    {
        return DB::transaction(function () use ($data) {
            $category = $this->categoryRepo->findByGuid($data['health_plan_category_guid']);
            if (!$category) {
                throw new \RuntimeException('Categoría no encontrada.');
            }

            $template = $this->templateRepo->create([
                'name'                    => $data['name'],
                'health_plan_category_id' => $category->id,
            ]);

            $syncData = $this->buildSyncData($data['activities'] ?? []);
            $this->templateRepo->syncActivities($template, $syncData);

            return $template->load(['category', 'activities']);
        });
    }

    /**
     * Actualiza un template y resincroniza sus actividades.
     *
     * @throws \RuntimeException si la categoría no existe
     */
    public function update(HealthPlanTemplate $template, array $data): HealthPlanTemplate
    {
        return DB::transaction(function () use ($template, $data) {
            $category = $this->categoryRepo->findByGuid($data['health_plan_category_guid']);
            if (!$category) {
                throw new \RuntimeException('Categoría no encontrada.');
            }

            $this->templateRepo->update($template, [
                'name'                    => $data['name'],
                'health_plan_category_id' => $category->id,
            ]);

            $syncData = $this->buildSyncData($data['activities'] ?? []);
            $this->templateRepo->syncActivities($template, $syncData);

            return $template->fresh()->load(['category', 'activities']);
        });
    }

    public function destroy(HealthPlanTemplate $template): void
    {
        // El cascade en la FK elimina automáticamente los registros del pivot
        $this->templateRepo->destroy($template);
    }

    /**
     * Convierte el array de actividades del payload al formato que espera sync().
     *
     * Input:  [{ health_activity_guid: 'uuid', months: [1,3,6,9] }, ...]
     * Output: [activity_id => ['months' => '[1,3,6,9]'], ...]
     *          ^ months como JSON string para guardar en la columna json de MySQL
     *
     * Los GUIDs inválidos (no encontrados) se ignoran silenciosamente.
     * Si se quiere validar que todos los GUIDs existan, hacerlo en el FormRequest
     * con Rule::exists('health_activities', 'guid').
     */
    private function buildSyncData(array $activities): array
    {
        if (empty($activities)) {
            return [];
        }

        $guids       = array_column($activities, 'health_activity_guid');
        $guidToId    = $this->activityRepo->resolveGuidsToIds($guids);

        $syncData = [];
        foreach ($activities as $assignment) {
            $guid = $assignment['health_activity_guid'];
            if (!isset($guidToId[$guid])) {
                continue;  // GUID no encontrado: ignorar
            }
            $id = $guidToId[$guid];
            $syncData[$id] = [
                'months' => json_encode($assignment['months']),
            ];
        }
        return $syncData;
    }
}
```

---

#### `back/app/Http/Requests/Health/IndexHealthActivityRequest.php`
**Propósito:** Filtros para listado de actividades.
```php
namespace App\Http\Requests\Health;

use Illuminate\Foundation\Http\FormRequest;

class IndexHealthActivityRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'search'   => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page'     => ['nullable', 'integer', 'min:1'],
        ];
    }
}
```

---

#### `back/app/Http/Requests/Health/StoreHealthActivityRequest.php`
**Propósito:** Validación de creación de actividad sanitaria.
```php
namespace App\Http\Requests\Health;

use Illuminate\Foundation\Http\FormRequest;

class StoreHealthActivityRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'        => ['required', 'string', 'max:255', 'unique:health_activities,name'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre es requerido.',
            'name.max'      => 'El nombre no puede superar 255 caracteres.',
            'name.unique'   => 'Ya existe una actividad con ese nombre.',
        ];
    }
}
```

---

#### `back/app/Http/Requests/Health/UpdateHealthActivityRequest.php`
**Propósito:** Validación de actualización de actividad sanitaria. Ignora el registro propio en unique.
```php
namespace App\Http\Requests\Health;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateHealthActivityRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        // El guid llega como segmento de ruta, no en el body.
        // Resolverlo desde el repositorio en el controller para obtener el id.
        // Aquí usamos 'ignore' por guid directamente (requiere que el modelo use guid como route key).
        $guid = $this->route('guid');

        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('health_activities', 'name')->where(function ($query) use ($guid) {
                    // Excluir el registro actual por guid
                    return $query->whereNot('guid', $guid);
                }),
            ],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre es requerido.',
            'name.max'      => 'El nombre no puede superar 255 caracteres.',
            'name.unique'   => 'Ya existe una actividad con ese nombre.',
        ];
    }
}
```

---

#### `back/app/Http/Requests/Health/IndexHealthPlanCategoryRequest.php`
**Propósito:** Filtros para listado de categorías.
```php
namespace App\Http\Requests\Health;

use Illuminate\Foundation\Http\FormRequest;

class IndexHealthPlanCategoryRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'search'   => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page'     => ['nullable', 'integer', 'min:1'],
        ];
    }
}
```

---

#### `back/app/Http/Requests/Health/StoreHealthPlanCategoryRequest.php`
```php
namespace App\Http\Requests\Health;

use Illuminate\Foundation\Http\FormRequest;

class StoreHealthPlanCategoryRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'        => ['required', 'string', 'max:255', 'unique:health_plan_categories,name'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre es requerido.',
            'name.unique'   => 'Ya existe una categoría con ese nombre.',
        ];
    }
}
```

---

#### `back/app/Http/Requests/Health/UpdateHealthPlanCategoryRequest.php`
```php
namespace App\Http\Requests\Health;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateHealthPlanCategoryRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $guid = $this->route('guid');
        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('health_plan_categories', 'name')->where(
                    fn($q) => $q->whereNot('guid', $guid)
                ),
            ],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre es requerido.',
            'name.unique'   => 'Ya existe una categoría con ese nombre.',
        ];
    }
}
```

---

#### `back/app/Http/Requests/Health/IndexHealthPlanTemplateRequest.php`
```php
namespace App\Http\Requests\Health;

use Illuminate\Foundation\Http\FormRequest;

class IndexHealthPlanTemplateRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'search'                     => ['nullable', 'string', 'max:100'],
            'health_plan_category_guid'  => ['nullable', 'string', 'uuid'],
            'per_page'                   => ['nullable', 'integer', 'min:1', 'max:100'],
            'page'                       => ['nullable', 'integer', 'min:1'],
        ];
    }
}
```

---

#### `back/app/Http/Requests/Health/StoreHealthPlanTemplateRequest.php`
**Propósito:** Validación de creación de plantilla con actividades.
```php
namespace App\Http\Requests\Health;

use Illuminate\Foundation\Http\FormRequest;

class StoreHealthPlanTemplateRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'                       => ['required', 'string', 'max:255'],
            'health_plan_category_guid'  => ['required', 'string', 'uuid', 'exists:health_plan_categories,guid'],
            'activities'                 => ['nullable', 'array'],
            'activities.*.health_activity_guid' => ['required', 'string', 'uuid', 'exists:health_activities,guid'],
            'activities.*.months'        => ['required', 'array', 'min:1'],
            'activities.*.months.*'      => ['required', 'integer', 'min:1', 'max:12'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'                          => 'El nombre es requerido.',
            'health_plan_category_guid.required'     => 'La categoría es requerida.',
            'health_plan_category_guid.exists'       => 'La categoría seleccionada no existe.',
            'activities.*.health_activity_guid.exists' => 'Una de las actividades seleccionadas no existe.',
            'activities.*.months.required'           => 'Cada actividad debe tener al menos un mes asignado.',
            'activities.*.months.min'                => 'Cada actividad debe tener al menos un mes asignado.',
            'activities.*.months.*.min'              => 'Los meses deben ser entre 1 y 12.',
            'activities.*.months.*.max'              => 'Los meses deben ser entre 1 y 12.',
        ];
    }
}
```

---

#### `back/app/Http/Requests/Health/UpdateHealthPlanTemplateRequest.php`
**Propósito:** Validación de actualización de plantilla. Idéntica a Store (sin unique en name para templates).
```php
namespace App\Http\Requests\Health;

use Illuminate\Foundation\Http\FormRequest;

class UpdateHealthPlanTemplateRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'                       => ['required', 'string', 'max:255'],
            'health_plan_category_guid'  => ['required', 'string', 'uuid', 'exists:health_plan_categories,guid'],
            'activities'                 => ['nullable', 'array'],
            'activities.*.health_activity_guid' => ['required', 'string', 'uuid', 'exists:health_activities,guid'],
            'activities.*.months'        => ['required', 'array', 'min:1'],
            'activities.*.months.*'      => ['required', 'integer', 'min:1', 'max:12'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'                            => 'El nombre es requerido.',
            'health_plan_category_guid.required'       => 'La categoría es requerida.',
            'health_plan_category_guid.exists'         => 'La categoría seleccionada no existe.',
            'activities.*.health_activity_guid.exists' => 'Una de las actividades seleccionadas no existe.',
            'activities.*.months.required'             => 'Cada actividad debe tener al menos un mes asignado.',
            'activities.*.months.min'                  => 'Cada actividad debe tener al menos un mes asignado.',
            'activities.*.months.*.min'                => 'Los meses deben ser entre 1 y 12.',
            'activities.*.months.*.max'                => 'Los meses deben ser entre 1 y 12.',
        ];
    }
}
```

---

#### `back/app/Http/Resources/V1/HealthActivityResource.php`
**Propósito:** Resource para actividades sanitarias.
```php
namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HealthActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'guid'        => $this->guid,
            'name'        => $this->name,
            'description' => $this->description,
            'created_at'  => $this->created_at?->toISOString(),
            'updated_at'  => $this->updated_at?->toISOString(),
        ];
    }
}
```

---

#### `back/app/Http/Resources/V1/HealthPlanCategoryResource.php`
**Propósito:** Resource para categorías de planes.
```php
namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HealthPlanCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'guid'            => $this->guid,
            'name'            => $this->name,
            'description'     => $this->description,
            'templates_count' => $this->templates_count ?? 0,
            'created_at'      => $this->created_at?->toISOString(),
            'updated_at'      => $this->updated_at?->toISOString(),
        ];
    }
}
```

---

#### `back/app/Http/Resources/V1/HealthPlanTemplateActivityResource.php`
**Propósito:** Resource para las actividades dentro de una plantilla (incluye months del pivot).
```php
namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HealthPlanTemplateActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'guid'   => $this->guid,
            'name'   => $this->name,
            'months' => $this->pivot->months ?? [],  // array de enteros gracias al cast
        ];
    }
}
```

---

#### `back/app/Http/Resources/V1/HealthPlanTemplateResource.php`
**Propósito:** Resource completo de plantilla con categoría y actividades con months.
```php
namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HealthPlanTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'guid'             => $this->guid,
            'name'             => $this->name,
            'category'         => $this->whenLoaded('category', fn() => [
                'guid' => $this->category->guid,
                'name' => $this->category->name,
            ]),
            'activities'       => HealthPlanTemplateActivityResource::collection(
                $this->whenLoaded('activities', $this->activities, collect())
            ),
            'activities_count' => $this->activities_count ?? ($this->relationLoaded('activities') ? $this->activities->count() : 0),
            'created_at'       => $this->created_at?->toISOString(),
            'updated_at'       => $this->updated_at?->toISOString(),
        ];
    }
}
```

---

#### `back/app/Http/Resources/V1/HealthPlanTemplateListResource.php`
**Propósito:** Resource liviano para la lista paginada de plantillas (sin activities array).
```php
namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HealthPlanTemplateListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'guid'             => $this->guid,
            'name'             => $this->name,
            'category'         => $this->whenLoaded('category', fn() => [
                'guid' => $this->category->guid,
                'name' => $this->category->name,
            ]),
            'activities_count' => $this->activities_count ?? 0,
            'created_at'       => $this->created_at?->toISOString(),
            'updated_at'       => $this->updated_at?->toISOString(),
        ];
    }
}
```

---

#### `back/app/Http/Controllers/V1/AdminHealthActivityController.php`
**Propósito:** CRUD de actividades sanitarias para SuperAdmin.
```php
namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Health\IndexHealthActivityRequest;
use App\Http\Requests\Health\StoreHealthActivityRequest;
use App\Http\Requests\Health\UpdateHealthActivityRequest;
use App\Http\Resources\V1\HealthActivityResource;
use App\Services\HealthActivityService;
use Illuminate\Http\JsonResponse;

class AdminHealthActivityController extends Controller
{
    public function __construct(private HealthActivityService $service) {}

    public function index(IndexHealthActivityRequest $request): JsonResponse
    {
        try {
            if (!$request->user()->can('health-activities.read')) {
                return $this->makeError(null, 'Sin permiso.', 403);
            }
            $perPage = $request->integer('per_page', 15);
            $paginator = $this->service->paginate($request->validated(), $perPage);
            return $this->makeSuccessPagination($paginator, HealthActivityResource::class);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function store(StoreHealthActivityRequest $request): JsonResponse
    {
        try {
            if (!$request->user()->can('health-activities.create')) {
                return $this->makeError(null, 'Sin permiso.', 403);
            }
            $activity = $this->service->create($request->validated());
            return $this->makeSuccess(new HealthActivityResource($activity), 'Actividad creada correctamente.', 201);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function update(UpdateHealthActivityRequest $request, string $guid): JsonResponse
    {
        try {
            if (!$request->user()->can('health-activities.update')) {
                return $this->makeError(null, 'Sin permiso.', 403);
            }
            $activity = $this->service->findByGuid($guid);
            if (!$activity) {
                return $this->makeNotFound('Actividad no encontrada.');
            }
            $activity = $this->service->update($activity, $request->validated());
            return $this->makeSuccess(new HealthActivityResource($activity), 'Actividad actualizada correctamente.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function destroy(string $guid, \Illuminate\Http\Request $request): JsonResponse
    {
        try {
            if (!$request->user()->can('health-activities.delete')) {
                return $this->makeError(null, 'Sin permiso.', 403);
            }
            $activity = $this->service->findByGuid($guid);
            if (!$activity) {
                return $this->makeNotFound('Actividad no encontrada.');
            }
            $this->service->destroy($activity);
            return $this->makeSuccess(null, 'Actividad eliminada correctamente.');
        } catch (\RuntimeException $e) {
            return $this->makeError(null, $e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
```

---

#### `back/app/Http/Controllers/V1/AdminHealthPlanCategoryController.php`
**Propósito:** CRUD de categorías de planes para SuperAdmin.
```php
namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Health\IndexHealthPlanCategoryRequest;
use App\Http\Requests\Health\StoreHealthPlanCategoryRequest;
use App\Http\Requests\Health\UpdateHealthPlanCategoryRequest;
use App\Http\Resources\V1\HealthPlanCategoryResource;
use App\Services\HealthPlanCategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminHealthPlanCategoryController extends Controller
{
    public function __construct(private HealthPlanCategoryService $service) {}

    public function index(IndexHealthPlanCategoryRequest $request): JsonResponse
    {
        try {
            if (!$request->user()->can('health-plan-categories.read')) {
                return $this->makeError(null, 'Sin permiso.', 403);
            }
            $perPage   = $request->integer('per_page', 15);
            $paginator = $this->service->paginate($request->validated(), $perPage);
            return $this->makeSuccessPagination($paginator, HealthPlanCategoryResource::class);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function store(StoreHealthPlanCategoryRequest $request): JsonResponse
    {
        try {
            if (!$request->user()->can('health-plan-categories.create')) {
                return $this->makeError(null, 'Sin permiso.', 403);
            }
            $category = $this->service->create($request->validated());
            return $this->makeSuccess(new HealthPlanCategoryResource($category), 'Categoría creada correctamente.', 201);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function update(UpdateHealthPlanCategoryRequest $request, string $guid): JsonResponse
    {
        try {
            if (!$request->user()->can('health-plan-categories.update')) {
                return $this->makeError(null, 'Sin permiso.', 403);
            }
            $category = $this->service->findByGuid($guid);
            if (!$category) {
                return $this->makeNotFound('Categoría no encontrada.');
            }
            $category = $this->service->update($category, $request->validated());
            return $this->makeSuccess(new HealthPlanCategoryResource($category), 'Categoría actualizada correctamente.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function destroy(string $guid, Request $request): JsonResponse
    {
        try {
            if (!$request->user()->can('health-plan-categories.delete')) {
                return $this->makeError(null, 'Sin permiso.', 403);
            }
            $category = $this->service->findByGuid($guid);
            if (!$category) {
                return $this->makeNotFound('Categoría no encontrada.');
            }
            $this->service->destroy($category);
            return $this->makeSuccess(null, 'Categoría eliminada correctamente.');
        } catch (\RuntimeException $e) {
            return $this->makeError(null, $e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
```

---

#### `back/app/Http/Controllers/V1/AdminHealthPlanTemplateController.php`
**Propósito:** CRUD de plantillas para SuperAdmin.
```php
namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Health\IndexHealthPlanTemplateRequest;
use App\Http\Requests\Health\StoreHealthPlanTemplateRequest;
use App\Http\Requests\Health\UpdateHealthPlanTemplateRequest;
use App\Http\Resources\V1\HealthPlanTemplateListResource;
use App\Http\Resources\V1\HealthPlanTemplateResource;
use App\Services\HealthPlanTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminHealthPlanTemplateController extends Controller
{
    public function __construct(private HealthPlanTemplateService $service) {}

    public function index(IndexHealthPlanTemplateRequest $request): JsonResponse
    {
        try {
            if (!$request->user()->can('health-plan-templates.read')) {
                return $this->makeError(null, 'Sin permiso.', 403);
            }
            $perPage   = $request->integer('per_page', 15);
            $paginator = $this->service->paginate($request->validated(), $perPage);
            return $this->makeSuccessPagination($paginator, HealthPlanTemplateListResource::class);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function show(string $guid, Request $request): JsonResponse
    {
        try {
            if (!$request->user()->can('health-plan-templates.read')) {
                return $this->makeError(null, 'Sin permiso.', 403);
            }
            $template = $this->service->findByGuid($guid);
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
            if (!$request->user()->can('health-plan-templates.create')) {
                return $this->makeError(null, 'Sin permiso.', 403);
            }
            $template = $this->service->create($request->validated());
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
            if (!$request->user()->can('health-plan-templates.update')) {
                return $this->makeError(null, 'Sin permiso.', 403);
            }
            $template = $this->service->findByGuid($guid);
            if (!$template) {
                return $this->makeNotFound('Plantilla no encontrada.');
            }
            $template = $this->service->update($template, $request->validated());
            return $this->makeSuccess(new HealthPlanTemplateResource($template), 'Plantilla actualizada correctamente.');
        } catch (\RuntimeException $e) {
            return $this->makeError(null, $e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function destroy(string $guid, Request $request): JsonResponse
    {
        try {
            if (!$request->user()->can('health-plan-templates.delete')) {
                return $this->makeError(null, 'Sin permiso.', 403);
            }
            $template = $this->service->findByGuid($guid);
            if (!$template) {
                return $this->makeNotFound('Plantilla no encontrada.');
            }
            $this->service->destroy($template);
            return $this->makeSuccess(null, 'Plantilla eliminada correctamente.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
```

---

### Rutas API

#### `back/routes/api/health-admin.php` (archivo nuevo)
```php
<?php

use App\Http\Controllers\V1\AdminHealthActivityController;
use App\Http\Controllers\V1\AdminHealthPlanCategoryController;
use App\Http\Controllers\V1\AdminHealthPlanTemplateController;
use Illuminate\Support\Facades\Route;

// Health Activities
Route::prefix('v1/admin/health-activities')->middleware('auth:sanctum')->group(function () {
    Route::get('/',           [AdminHealthActivityController::class, 'index'])->middleware('can:health-activities.read');
    Route::post('/',          [AdminHealthActivityController::class, 'store'])->middleware('can:health-activities.create');
    Route::put('/{guid}',     [AdminHealthActivityController::class, 'update'])->middleware('can:health-activities.update');
    Route::delete('/{guid}',  [AdminHealthActivityController::class, 'destroy'])->middleware('can:health-activities.delete');
});

// Health Plan Categories
Route::prefix('v1/admin/health-plan-categories')->middleware('auth:sanctum')->group(function () {
    Route::get('/',           [AdminHealthPlanCategoryController::class, 'index'])->middleware('can:health-plan-categories.read');
    Route::post('/',          [AdminHealthPlanCategoryController::class, 'store'])->middleware('can:health-plan-categories.create');
    Route::put('/{guid}',     [AdminHealthPlanCategoryController::class, 'update'])->middleware('can:health-plan-categories.update');
    Route::delete('/{guid}',  [AdminHealthPlanCategoryController::class, 'destroy'])->middleware('can:health-plan-categories.delete');
});

// Health Plan Templates
Route::prefix('v1/admin/health-plan-templates')->middleware('auth:sanctum')->group(function () {
    Route::get('/',           [AdminHealthPlanTemplateController::class, 'index'])->middleware('can:health-plan-templates.read');
    Route::post('/',          [AdminHealthPlanTemplateController::class, 'store'])->middleware('can:health-plan-templates.create');
    Route::get('/{guid}',     [AdminHealthPlanTemplateController::class, 'show'])->middleware('can:health-plan-templates.read');
    Route::put('/{guid}',     [AdminHealthPlanTemplateController::class, 'update'])->middleware('can:health-plan-templates.update');
    Route::delete('/{guid}',  [AdminHealthPlanTemplateController::class, 'destroy'])->middleware('can:health-plan-templates.delete');
});
```

**Nota sobre el middleware doble:** El grupo lleva `auth:sanctum` (autenticación) y cada ruta individual lleva `can:{permiso}` (autorización). Este es el mismo patrón que `techniques.php`. La verificación manual en el controller (`$request->user()->can(...)`) es redundante pero mantiene consistencia con el patrón de `TutorialController`. Se puede elegir uno u otro — el `can:` en la ruta ya protege; el check en el controller es un segundo nivel explícito.

---

### Permisos Spatie

**Seeder a modificar:** `back/database/seeders/PermissionSeeder.php`

Agregar al final del array `$permissions`:
```php
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
```

En `RoleSeeder.php`: El `super-admin` ya tiene `$superAdmin->syncPermissions(Permission::all())` — al agregar estos permisos al `PermissionSeeder` y correr el seed, el super-admin los recibe automáticamente. No requiere cambio en `RoleSeeder`.

---

### Archivos a modificar en BACKEND

#### `back/app/Providers/AppServiceProvider.php`
**Cambio:** Agregar tres bindings de repositorio en `register()`.
```php
// use statements a agregar:
use App\Contracts\Repositories\HealthActivityRepositoryInterface;
use App\Contracts\Repositories\HealthPlanCategoryRepositoryInterface;
use App\Contracts\Repositories\HealthPlanTemplateRepositoryInterface;
use App\Repositories\HealthActivityRepositoryEloquent;
use App\Repositories\HealthPlanCategoryRepositoryEloquent;
use App\Repositories\HealthPlanTemplateRepositoryEloquent;

// En register(), al final de la lista de bindings:
$this->app->bind(HealthActivityRepositoryInterface::class, HealthActivityRepositoryEloquent::class);
$this->app->bind(HealthPlanCategoryRepositoryInterface::class, HealthPlanCategoryRepositoryEloquent::class);
$this->app->bind(HealthPlanTemplateRepositoryInterface::class, HealthPlanTemplateRepositoryEloquent::class);
```

---

### Contrato de endpoints

#### GET `/v1/admin/health-activities?search=&page=1&per_page=15`
Response 200:
```json
{
  "success": true,
  "data": {
    "data": [
      { "guid": "uuid", "name": "Vacuna Aftosa", "description": null, "created_at": "...", "updated_at": "..." }
    ],
    "current_page": 1, "last_page": 1, "per_page": 15, "total": 1
  }
}
```

#### POST `/v1/admin/health-activities`
Request: `{ "name": "Vacuna Aftosa", "description": "Vacunación obligatoria" }`
Response 201: `{ "success": true, "data": { "guid": "...", "name": "Vacuna Aftosa", ... }, "message": "Actividad creada correctamente." }`

#### PUT `/v1/admin/health-activities/{guid}`
Request: `{ "name": "Vacuna Aftosa (actualizada)", "description": null }`

#### DELETE `/v1/admin/health-activities/{guid}`
Response 200: `{ "success": true, "data": null, "message": "Actividad eliminada correctamente." }`
Response 422 (en uso): `{ "success": false, "message": "La actividad sanitaria está en uso en una o más plantillas y no puede eliminarse.", "errors": null }`

#### GET `/v1/admin/health-plan-categories`
Response: mismo shape paginado. Incluye `templates_count` en cada item.

#### POST `/v1/admin/health-plan-templates`
Request:
```json
{
  "name": "Plan Bovinos Estándar",
  "health_plan_category_guid": "uuid-categoria",
  "activities": [
    { "health_activity_guid": "uuid-actividad-1", "months": [1, 3, 6, 9] },
    { "health_activity_guid": "uuid-actividad-2", "months": [2, 5, 8, 11] }
  ]
}
```
Response 201:
```json
{
  "success": true,
  "data": {
    "guid": "uuid",
    "name": "Plan Bovinos Estándar",
    "category": { "guid": "uuid-cat", "name": "Bovinos" },
    "activities": [
      { "guid": "uuid-act-1", "name": "Vacuna Aftosa", "months": [1, 3, 6, 9] },
      { "guid": "uuid-act-2", "name": "Antiparasitario", "months": [2, 5, 8, 11] }
    ],
    "activities_count": 2,
    "created_at": "...", "updated_at": "..."
  },
  "message": "Plantilla creada correctamente."
}
```

#### GET `/v1/admin/health-plan-templates/{guid}`
Response: mismo shape que la creación (HealthPlanTemplateResource completo).

#### Errores posibles
| HTTP | Cuándo |
|------|--------|
| 401  | No autenticado |
| 403  | Sin permiso |
| 404  | Recurso no encontrado por guid |
| 409  | Nombre duplicado (unique constraint DB) |
| 422  | Validación fallida / delete bloqueado por vínculos |
| 500  | Error interno |

---

### Tests a generar

**Feature tests — AdminHealthActivityController:**
- `index`: lista paginada, filtro por `search`, retorna 403 sin permiso.
- `store`: crea actividad, falla con nombre duplicado (422), falla sin nombre (422).
- `update`: actualiza nombre, unique ignora el propio registro, 404 si guid inexistente.
- `destroy`: elimina actividad libre, retorna 422 si actividad usada en template.

**Feature tests — AdminHealthPlanCategoryController:**
- `index`: lista con `templates_count`, filtro por `search`.
- `store`: crea categoría, falla con nombre duplicado.
- `update`: actualiza, unique ignora el propio.
- `destroy`: elimina categoría sin templates, retorna 422 si tiene templates.

**Feature tests — AdminHealthPlanTemplateController:**
- `index`: lista con categoría y `activities_count`, filtro por `search` y `health_plan_category_guid`.
- `store`: crea template con actividades, verifica pivot `months` en BD, falla con `health_plan_category_guid` inválido, falla con `health_activity_guid` inválido, crea template sin actividades.
- `show`: retorna template completo con activities y months, 404 para guid inexistente.
- `update`: actualiza nombre, agrega actividad, quita actividad (verifica que el sync elimina del pivot), cambia months.
- `destroy`: elimina template, verifica que el pivot se elimina en cascade.

**Unit tests — HealthPlanTemplateService:**
- `buildSyncData`: GUIDs válidos generan el formato correcto para sync, GUIDs inválidos se ignoran.
- `create`: lanza RuntimeException si la categoría no existe.

---

## Cambios en FRONTEND

### Estructura de archivos a crear

```
front/src/modules/health/
├── types/
│   └── health.types.ts
├── api/
│   ├── health-activities.api.ts
│   ├── health-plan-categories.api.ts
│   └── health-plan-templates.api.ts
├── validators/
│   ├── health-activity.validator.ts
│   ├── health-plan-category.validator.ts
│   └── health-plan-template.validator.ts
├── composables/
│   ├── useHealthActivities.ts
│   ├── useHealthActivityMutations.ts
│   ├── useHealthPlanCategories.ts
│   ├── useHealthPlanCategoryMutations.ts
│   ├── useHealthPlanTemplates.ts
│   └── useHealthPlanTemplateMutations.ts
├── components/
│   ├── ActivityMonthMatrix.vue
│   ├── HealthActivityDrawer.vue
│   ├── HealthPlanCategoryDrawer.vue
│   └── HealthPlanTemplateDrawer.vue
├── pages/
│   ├── HealthActivitiesPage.vue
│   ├── HealthPlanCategoriesPage.vue
│   └── HealthPlanTemplatesPage.vue
└── router/
    └── health.routes.ts
```

---

### `front/src/modules/health/types/health.types.ts`
```typescript
// ─── Health Activity ───────────────────────────────────────────────────────

export interface HealthActivity {
  guid: string
  name: string
  description: string | null
  created_at: string
  updated_at: string
}

export interface HealthActivityListParams {
  search?: string
  page?: number
  per_page?: number
}

export interface CreateHealthActivityPayload {
  name: string
  description: string | null
}

export type UpdateHealthActivityPayload = CreateHealthActivityPayload

// ─── Health Plan Category ──────────────────────────────────────────────────

export interface HealthPlanCategory {
  guid: string
  name: string
  description: string | null
  templates_count: number
  created_at: string
  updated_at: string
}

export interface HealthPlanCategoryListParams {
  search?: string
  page?: number
  per_page?: number
}

export interface CreateHealthPlanCategoryPayload {
  name: string
  description: string | null
}

export type UpdateHealthPlanCategoryPayload = CreateHealthPlanCategoryPayload

// ─── Health Plan Template ──────────────────────────────────────────────────

export interface ActivityAssignment {
  health_activity_guid: string
  months: number[]  // [1, 3, 6, 9] — enteros 1-12
}

export interface TemplateActivity {
  guid: string
  name: string
  months: number[]
}

export interface HealthPlanTemplateCategory {
  guid: string
  name: string
}

export interface HealthPlanTemplate {
  guid: string
  name: string
  category: HealthPlanTemplateCategory
  activities: TemplateActivity[]
  activities_count: number
  created_at: string
  updated_at: string
}

export interface HealthPlanTemplateListItem {
  guid: string
  name: string
  category: HealthPlanTemplateCategory
  activities_count: number
  created_at: string
  updated_at: string
}

export interface HealthPlanTemplateListParams {
  search?: string
  health_plan_category_guid?: string
  page?: number
  per_page?: number
}

export interface CreateHealthPlanTemplatePayload {
  name: string
  health_plan_category_guid: string
  activities: ActivityAssignment[]
}

export type UpdateHealthPlanTemplatePayload = CreateHealthPlanTemplatePayload
```

---

### `front/src/modules/health/api/health-activities.api.ts`
```typescript
import { http } from '@/core/api/http'
import type { PaginatedResponse } from '@/core/types/pagination.types'
import type {
  HealthActivity,
  HealthActivityListParams,
  CreateHealthActivityPayload,
  UpdateHealthActivityPayload,
} from '../types/health.types'

export async function listHealthActivitiesApi(
  params: HealthActivityListParams,
  signal?: AbortSignal,
): Promise<PaginatedResponse<HealthActivity>> {
  const res = await http.get<PaginatedResponse<HealthActivity>>('/v1/admin/health-activities', { params, signal })
  return res.data
}

export async function listAllHealthActivitiesApi(): Promise<HealthActivity[]> {
  // Para el selector en el formulario de templates. Sin paginación.
  const res = await http.get<PaginatedResponse<HealthActivity>>('/v1/admin/health-activities', {
    params: { per_page: 200 },
  })
  return res.data.data
}

export async function createHealthActivityApi(payload: CreateHealthActivityPayload): Promise<HealthActivity> {
  const res = await http.post<HealthActivity>('/v1/admin/health-activities', payload)
  return res.data
}

export async function updateHealthActivityApi(
  guid: string,
  payload: UpdateHealthActivityPayload,
): Promise<HealthActivity> {
  const res = await http.put<HealthActivity>(`/v1/admin/health-activities/${guid}`, payload)
  return res.data
}

export async function deleteHealthActivityApi(guid: string): Promise<void> {
  await http.delete(`/v1/admin/health-activities/${guid}`)
}
```

---

### `front/src/modules/health/api/health-plan-categories.api.ts`
```typescript
import { http } from '@/core/api/http'
import type { PaginatedResponse } from '@/core/types/pagination.types'
import type {
  HealthPlanCategory,
  HealthPlanCategoryListParams,
  CreateHealthPlanCategoryPayload,
  UpdateHealthPlanCategoryPayload,
} from '../types/health.types'

export async function listHealthPlanCategoriesApi(
  params: HealthPlanCategoryListParams,
  signal?: AbortSignal,
): Promise<PaginatedResponse<HealthPlanCategory>> {
  const res = await http.get<PaginatedResponse<HealthPlanCategory>>('/v1/admin/health-plan-categories', { params, signal })
  return res.data
}

export async function listAllHealthPlanCategoriesApi(): Promise<HealthPlanCategory[]> {
  const res = await http.get<PaginatedResponse<HealthPlanCategory>>('/v1/admin/health-plan-categories', {
    params: { per_page: 200 },
  })
  return res.data.data
}

export async function createHealthPlanCategoryApi(payload: CreateHealthPlanCategoryPayload): Promise<HealthPlanCategory> {
  const res = await http.post<HealthPlanCategory>('/v1/admin/health-plan-categories', payload)
  return res.data
}

export async function updateHealthPlanCategoryApi(
  guid: string,
  payload: UpdateHealthPlanCategoryPayload,
): Promise<HealthPlanCategory> {
  const res = await http.put<HealthPlanCategory>(`/v1/admin/health-plan-categories/${guid}`, payload)
  return res.data
}

export async function deleteHealthPlanCategoryApi(guid: string): Promise<void> {
  await http.delete(`/v1/admin/health-plan-categories/${guid}`)
}
```

---

### `front/src/modules/health/api/health-plan-templates.api.ts`
```typescript
import { http } from '@/core/api/http'
import type { PaginatedResponse } from '@/core/types/pagination.types'
import type {
  HealthPlanTemplate,
  HealthPlanTemplateListItem,
  HealthPlanTemplateListParams,
  CreateHealthPlanTemplatePayload,
  UpdateHealthPlanTemplatePayload,
} from '../types/health.types'

export async function listHealthPlanTemplatesApi(
  params: HealthPlanTemplateListParams,
  signal?: AbortSignal,
): Promise<PaginatedResponse<HealthPlanTemplateListItem>> {
  const res = await http.get<PaginatedResponse<HealthPlanTemplateListItem>>(
    '/v1/admin/health-plan-templates', { params, signal }
  )
  return res.data
}

export async function getHealthPlanTemplateApi(guid: string): Promise<HealthPlanTemplate> {
  const res = await http.get<HealthPlanTemplate>(`/v1/admin/health-plan-templates/${guid}`)
  return res.data
}

export async function createHealthPlanTemplateApi(
  payload: CreateHealthPlanTemplatePayload,
): Promise<HealthPlanTemplate> {
  const res = await http.post<HealthPlanTemplate>('/v1/admin/health-plan-templates', payload)
  return res.data
}

export async function updateHealthPlanTemplateApi(
  guid: string,
  payload: UpdateHealthPlanTemplatePayload,
): Promise<HealthPlanTemplate> {
  const res = await http.put<HealthPlanTemplate>(`/v1/admin/health-plan-templates/${guid}`, payload)
  return res.data
}

export async function deleteHealthPlanTemplateApi(guid: string): Promise<void> {
  await http.delete(`/v1/admin/health-plan-templates/${guid}`)
}
```

---

### `front/src/modules/health/validators/health-activity.validator.ts`
```typescript
import { z } from 'zod'

export const healthActivitySchema = z.object({
  name: z.string().min(1, 'El nombre es requerido').max(255, 'Máximo 255 caracteres'),
  description: z.string().max(500, 'Máximo 500 caracteres').nullable().optional()
    .transform((v) => v ?? null),
})

export type HealthActivityFormValues = z.infer<typeof healthActivitySchema>
```

---

### `front/src/modules/health/validators/health-plan-category.validator.ts`
```typescript
import { z } from 'zod'

export const healthPlanCategorySchema = z.object({
  name: z.string().min(1, 'El nombre es requerido').max(255, 'Máximo 255 caracteres'),
  description: z.string().max(500, 'Máximo 500 caracteres').nullable().optional()
    .transform((v) => v ?? null),
})

export type HealthPlanCategoryFormValues = z.infer<typeof healthPlanCategorySchema>
```

---

### `front/src/modules/health/validators/health-plan-template.validator.ts`
```typescript
import { z } from 'zod'

const activityAssignmentSchema = z.object({
  health_activity_guid: z.string().uuid(),
  months: z.array(z.number().int().min(1).max(12)).min(1, 'Seleccioná al menos un mes'),
})

export const healthPlanTemplateSchema = z.object({
  name: z.string().min(1, 'El nombre es requerido').max(255, 'Máximo 255 caracteres'),
  health_plan_category_guid: z.string().uuid('Seleccioná una categoría válida'),
  activities: z.array(activityAssignmentSchema).default([]),
})

export type HealthPlanTemplateFormValues = z.infer<typeof healthPlanTemplateSchema>
```

---

### `front/src/modules/health/composables/useHealthActivities.ts`
```typescript
import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import type { Ref } from 'vue'
import { listHealthActivitiesApi } from '../api/health-activities.api'
import type { HealthActivityListParams } from '../types/health.types'

export function useHealthActivities(params: Ref<HealthActivityListParams> | HealthActivityListParams = {}) {
  const paramsRef = computed(() => toValue(params))
  return useQuery({
    queryKey: ['admin-health-activities', paramsRef],
    queryFn: ({ signal }) => listHealthActivitiesApi(paramsRef.value, signal),
    staleTime: 1000 * 30,
  })
}
```

---

### `front/src/modules/health/composables/useHealthActivityMutations.ts`
```typescript
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { ref } from 'vue'
import { useNotification } from '@/core/composables/useNotification'
import {
  createHealthActivityApi,
  updateHealthActivityApi,
  deleteHealthActivityApi,
} from '../api/health-activities.api'
import type { CreateHealthActivityPayload, UpdateHealthActivityPayload } from '../types/health.types'

export function useCreateHealthActivity() {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const fieldErrors = ref<Record<string, string> | null>(null)

  const mutation = useMutation({
    mutationFn: (payload: CreateHealthActivityPayload) => createHealthActivityApi(payload),
    onMutate: () => { fieldErrors.value = null },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-health-activities'] })
      success('Actividad creada correctamente')
    },
    onError: (err: any) => {
      fieldErrors.value = err?.errors ?? null
      error(err?.message ?? 'Error al crear la actividad')
    },
  })

  return { ...mutation, fieldErrors }
}

export function useUpdateHealthActivity() {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const fieldErrors = ref<Record<string, string> | null>(null)

  const mutation = useMutation({
    mutationFn: ({ guid, payload }: { guid: string; payload: UpdateHealthActivityPayload }) =>
      updateHealthActivityApi(guid, payload),
    onMutate: () => { fieldErrors.value = null },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-health-activities'] })
      success('Actividad actualizada correctamente')
    },
    onError: (err: any) => {
      fieldErrors.value = err?.errors ?? null
      error(err?.message ?? 'Error al actualizar la actividad')
    },
  })

  return { ...mutation, fieldErrors }
}

export function useDeleteHealthActivity() {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const blockedMessage = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: (guid: string) => deleteHealthActivityApi(guid),
    onMutate: () => { blockedMessage.value = null },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-health-activities'] })
      success('Actividad eliminada correctamente')
    },
    onError: (err: any) => {
      if (err?.status === 422) {
        blockedMessage.value = err?.message ?? 'No se puede eliminar la actividad.'
      } else {
        error(err?.message ?? 'Error al eliminar la actividad')
      }
    },
  })

  return { ...mutation, blockedMessage }
}
```

---

### `front/src/modules/health/composables/useHealthPlanCategories.ts`
```typescript
import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import type { Ref } from 'vue'
import { listHealthPlanCategoriesApi } from '../api/health-plan-categories.api'
import type { HealthPlanCategoryListParams } from '../types/health.types'

export function useHealthPlanCategories(
  params: Ref<HealthPlanCategoryListParams> | HealthPlanCategoryListParams = {}
) {
  const paramsRef = computed(() => toValue(params))
  return useQuery({
    queryKey: ['admin-health-plan-categories', paramsRef],
    queryFn: ({ signal }) => listHealthPlanCategoriesApi(paramsRef.value, signal),
    staleTime: 1000 * 30,
  })
}
```

---

### `front/src/modules/health/composables/useHealthPlanCategoryMutations.ts`
Mismo patrón que `useHealthActivityMutations.ts` adaptado a categorías. Devuelve:
- `useCreateHealthPlanCategory()` → invalida `['admin-health-plan-categories']`
- `useUpdateHealthPlanCategory()` → invalida `['admin-health-plan-categories']`
- `useDeleteHealthPlanCategory()` → invalida `['admin-health-plan-categories']` y `['admin-health-plan-templates']` (porque borrar categoría afecta la columna de filtro de templates). Expone `blockedMessage` para el caso 422.

---

### `front/src/modules/health/composables/useHealthPlanTemplates.ts`
```typescript
import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import type { Ref } from 'vue'
import { listHealthPlanTemplatesApi, getHealthPlanTemplateApi } from '../api/health-plan-templates.api'
import type { HealthPlanTemplateListParams } from '../types/health.types'

export function useHealthPlanTemplates(
  params: Ref<HealthPlanTemplateListParams> | HealthPlanTemplateListParams = {}
) {
  const paramsRef = computed(() => toValue(params))
  return useQuery({
    queryKey: ['admin-health-plan-templates', paramsRef],
    queryFn: ({ signal }) => listHealthPlanTemplatesApi(paramsRef.value, signal),
    staleTime: 1000 * 30,
  })
}

export function useHealthPlanTemplate(guid: Ref<string | null>) {
  const guidValue = computed(() => toValue(guid))
  return useQuery({
    queryKey: ['admin-health-plan-template', guidValue],
    queryFn: () => getHealthPlanTemplateApi(guidValue.value!),
    enabled: computed(() => Boolean(guidValue.value)),
    staleTime: 1000 * 30,
  })
}
```

---

### `front/src/modules/health/composables/useHealthPlanTemplateMutations.ts`
Devuelve:
- `useCreateHealthPlanTemplate()` → invalida `['admin-health-plan-templates']`. `fieldErrors` para errores de campo.
- `useUpdateHealthPlanTemplate()` → invalida `['admin-health-plan-templates']` y `['admin-health-plan-template', guid]`.
- `useDeleteHealthPlanTemplate()` → invalida `['admin-health-plan-templates']`.

Mismo patrón que las mutaciones de actividades. Sin errores de negocio 422 especiales en templates (el delete no está bloqueado).

---

### `front/src/modules/health/components/ActivityMonthMatrix.vue`
**Propósito:** Componente matricial actividades × meses. Filas = actividades disponibles, columnas = meses 1-12, checkboxes en cada intersección.

**Props:**
```typescript
defineProps<{
  availableActivities: HealthActivity[]  // catálogo completo
}>()

// v-model: array de asignaciones activas
const assignments = defineModel<ActivityAssignment[]>({ required: true })
```

**Lógica interna:**
```typescript
// Map reactivo: { [activity_guid]: Set<number> }
// Derivado del modelValue para lookup O(1)
const monthsByActivity = computed(() => {
  const map = new Map<string, Set<number>>()
  for (const a of assignments.value) {
    map.set(a.health_activity_guid, new Set(a.months))
  }
  return map
})

function isChecked(activityGuid: string, month: number): boolean {
  return monthsByActivity.value.get(activityGuid)?.has(month) ?? false
}

function toggle(activityGuid: string, month: number): void {
  const current = [...assignments.value]
  const idx = current.findIndex(a => a.health_activity_guid === activityGuid)

  if (idx === -1) {
    // Actividad nueva: agregar con este mes
    current.push({ health_activity_guid: activityGuid, months: [month] })
  } else {
    const months = [...current[idx].months]
    const mIdx = months.indexOf(month)
    if (mIdx === -1) {
      months.push(month)
      months.sort((a, b) => a - b)
    } else {
      months.splice(mIdx, 1)
    }

    if (months.length === 0) {
      // Sin meses → eliminar la actividad de la asignación
      current.splice(idx, 1)
    } else {
      current[idx] = { ...current[idx], months }
    }
  }

  assignments.value = current
}
```

**Template:**
```vue
<template>
  <div class="amm-wrapper">
    <table class="amm-table">
      <thead>
        <tr>
          <th class="amm-activity-col">Actividad</th>
          <th v-for="m in 12" :key="m" class="amm-month-col">{{ monthLabel(m) }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="activity in availableActivities" :key="activity.guid">
          <td class="amm-activity-name">{{ activity.name }}</td>
          <td v-for="m in 12" :key="m" class="amm-cell">
            <input
              type="checkbox"
              :checked="isChecked(activity.guid, m)"
              @change="toggle(activity.guid, m)"
              class="amm-checkbox"
            />
          </td>
        </tr>
        <tr v-if="availableActivities.length === 0">
          <td :colspan="13" class="amm-empty">Sin actividades disponibles.</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
```

**Función `monthLabel`:** Devuelve el nombre corto del mes (`['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'][m-1]`).

**Estilos sugeridos:**
- `amm-wrapper`: overflow-x auto para scroll horizontal en mobile.
- `amm-month-col`: ancho fijo ~52px, texto centrado.
- `amm-cell`: text-align center.
- `amm-checkbox`: cursor pointer, accentColor con la variable del design system.

---

### `front/src/modules/health/components/HealthActivityDrawer.vue`
**Propósito:** Drawer de create/edit para actividades sanitarias.

**Props:**
```typescript
defineProps<{
  mode: 'create' | 'edit'
  activity?: HealthActivity | null  // presente en modo edit
}>()
const isOpen = defineModel<boolean>({ required: true })
const emit = defineEmits<{ success: [] }>()
```

**Lógica:** Usa `useForm` con `toTypedSchema(healthActivitySchema)`. En `onSuccess` de la mutation cierra el drawer y emite `success`.

**Template:**
```vue
<BaseDrawer v-model="isOpen" :title="mode === 'create' ? 'Nueva Actividad' : 'Editar Actividad'" :width="480">
  <form @submit.prevent="onSubmit">
    <!-- campo name -->
    <!-- campo description (textarea) -->
  </form>
  <template #footer>
    <BaseButton variant="secondary" @click="isOpen = false">Cancelar</BaseButton>
    <BaseButton type="submit" :loading="isPending" @click="onSubmit">Guardar</BaseButton>
  </template>
</BaseDrawer>
```

**Nota:** Al abrir en modo `edit`, hacer `resetForm({ values: { name: activity.name, description: activity.description } })` en un `watch` sobre `activity`.

---

### `front/src/modules/health/components/HealthPlanCategoryDrawer.vue`
Mismo patrón que `HealthActivityDrawer.vue` para categorías. Mismos campos: `name` + `description`.

---

### `front/src/modules/health/components/HealthPlanTemplateDrawer.vue`
**Propósito:** Drawer de create/edit para plantillas. Incluye `ActivityMonthMatrix`.

**Props:**
```typescript
defineProps<{
  mode: 'create' | 'edit'
  template?: HealthPlanTemplate | null
}>()
const isOpen = defineModel<boolean>({ required: true })
const emit = defineEmits<{ success: [] }>()
```

**Lógica interna:**
1. Al montar, cargar lista completa de actividades via `listAllHealthActivitiesApi()` (o useQuery con `staleTime` largo).
2. Cargar lista de categorías via `listAllHealthPlanCategoriesApi()` para el select.
3. `useForm` con `toTypedSchema(healthPlanTemplateSchema)`.
4. El campo `activities` del form vincula con `ActivityMonthMatrix` via `defineField('activities')`.
5. Al abrir en modo `edit`, inicializar el form con los valores de `template` (incluido `activities` mapeado desde `TemplateActivity[]` a `ActivityAssignment[]`).

**Template:**
```vue
<BaseDrawer v-model="isOpen" :title="..." :width="720">
  <form @submit.prevent="onSubmit">
    <!-- campo name -->
    <!-- select categoría: BaseSelect con options de categorías -->
    <!-- ActivityMonthMatrix v-model="activities" :available-activities="allActivities" -->
  </form>
  <template #footer>
    <!-- botones cancelar / guardar -->
  </template>
</BaseDrawer>
```

**Nota:** Width 720 porque la matriz de 12 meses necesita más espacio que un drawer estándar de 480.

---

### `front/src/modules/health/pages/HealthActivitiesPage.vue`
**Propósito:** Página de gestión de actividades sanitarias. Una sola ruta con tabla + drawer.

**Estado local:**
```typescript
const filters = reactive<HealthActivityListParams>({ page: 1, per_page: 15 })
const drawerOpen = ref(false)
const drawerMode = ref<'create' | 'edit'>('create')
const editTarget = ref<HealthActivity | null>(null)

function openCreate() { drawerMode.value = 'create'; editTarget.value = null; drawerOpen.value = true }
function openEdit(a: HealthActivity) { drawerMode.value = 'edit'; editTarget.value = a; drawerOpen.value = true }
function onDrawerSuccess() { drawerOpen.value = false }
```

**Columnas de la tabla:**
- Nombre
- Descripción
- Acciones (editar / eliminar)

---

### `front/src/modules/health/pages/HealthPlanCategoriesPage.vue`
Mismo patrón que `HealthActivitiesPage`. Columnas: Nombre, Descripción, Plantillas (templates_count), Acciones.

---

### `front/src/modules/health/pages/HealthPlanTemplatesPage.vue`
Mismo patrón con filtro adicional de categoría (select de categorías). Columnas: Nombre, Categoría, Actividades (activities_count), Acciones. Drawer usa `HealthPlanTemplateDrawer`.

---

### `front/src/modules/health/router/health.routes.ts`
```typescript
import type { RouteRecordRaw } from 'vue-router'

export const healthRoutes: RouteRecordRaw[] = [
  {
    path: '/health/activities',
    name: 'admin-health-activities',
    component: () => import('@/modules/health/pages/HealthActivitiesPage.vue'),
    meta: { requiresAuth: true, title: 'Actividades Sanitarias' },
  },
  {
    path: '/health/categories',
    name: 'admin-health-plan-categories',
    component: () => import('@/modules/health/pages/HealthPlanCategoriesPage.vue'),
    meta: { requiresAuth: true, title: 'Categorías de Planes Sanitarios' },
  },
  {
    path: '/health/templates',
    name: 'admin-health-plan-templates',
    component: () => import('@/modules/health/pages/HealthPlanTemplatesPage.vue'),
    meta: { requiresAuth: true, title: 'Plantillas de Planes Sanitarios' },
  },
]
```

---

### Archivos a modificar en FRONTEND

#### `front/src/router/index.ts`
**Cambio:** Importar y registrar `healthRoutes`.
```typescript
// Agregar import:
import { healthRoutes } from '@/modules/health/router/health.routes'

// En el array children del layout AppLayout:
...healthRoutes,
```

#### `front/src/components/layouts/partials/AppMenu.vue` (o `AppSidebar.vue`)
**Cambio:** Agregar items de menú para las tres rutas de Sanidad bajo una sección "Sanidad". Verificar cuál de los dos archivos contiene la definición del menú lateral (el dev debe inspeccionarlo) y agregar:
```typescript
{
  label: 'Sanidad',
  children: [
    { label: 'Actividades', route: '/health/activities', permission: 'health-activities.read' },
    { label: 'Categorías de Planes', route: '/health/categories', permission: 'health-plan-categories.read' },
    { label: 'Plantillas de Planes', route: '/health/templates', permission: 'health-plan-templates.read' },
  ]
}
```
El formato exacto depende de la estructura del menú existente — el dev debe adaptarlo.

---

## Orden de implementación

1. Crear las 4 migraciones en orden de numeración (`100001` → `100004`). Correr `php artisan migrate`.
2. Agregar los 12 permisos al array en `PermissionSeeder.php`. Correr `php artisan db:seed --class=PermissionSeeder` y luego `--class=RoleSeeder` para que super-admin los reciba.
3. Crear los 4 modelos: `HealthActivity`, `HealthPlanCategory`, `HealthPlanTemplate`, `HealthPlanTemplateActivity` (pivot).
4. Crear las 3 interfaces de repositorio en `back/app/Contracts/Repositories/`.
5. Crear las 3 implementaciones Eloquent en `back/app/Repositories/`.
6. Registrar los 3 bindings en `AppServiceProvider::register()`.
7. Crear los 3 servicios en `back/app/Services/`.
8. Crear los 8 FormRequests en `back/app/Http/Requests/Health/`.
9. Crear los 5 Resources en `back/app/Http/Resources/V1/` (`HealthActivityResource`, `HealthPlanCategoryResource`, `HealthPlanTemplateResource`, `HealthPlanTemplateListResource`, `HealthPlanTemplateActivityResource`).
10. Crear los 3 controllers en `back/app/Http/Controllers/V1/`.
11. Crear el archivo de rutas `back/routes/api/health-admin.php`. Verificar que el glob lo cargue.
12. Correr los feature tests de backend para las 3 entidades.
13. Crear `front/src/modules/health/types/health.types.ts`.
14. Crear los 3 archivos de API layer.
15. Crear los 3 validators Zod.
16. Crear los 6 composables (queries + mutations para cada entidad).
17. Crear `ActivityMonthMatrix.vue`.
18. Crear los 3 drawers: `HealthActivityDrawer.vue`, `HealthPlanCategoryDrawer.vue`, `HealthPlanTemplateDrawer.vue`.
19. Crear las 3 páginas: `HealthActivitiesPage.vue`, `HealthPlanCategoriesPage.vue`, `HealthPlanTemplatesPage.vue`.
20. Crear `health.routes.ts` y registrar en `front/src/router/index.ts`.
21. Agregar items al menú lateral.
22. Smoke test manual: crear una actividad, una categoría, una plantilla con la matriz, verificar que months se persiste y se devuelve correctamente.

---

## Riesgos y consideraciones

**Discrepancia 1 — SoftDeletes:**
El brief menciona "soft delete" en las tres entidades. El código real no usa soft deletes en ningún modelo del proyecto (grep confirmado). Se aplicó hard delete para mantener consistencia. Si se requiere soft delete, habría que definir una convención de migración (agregar `$table->softDeletes()`) y usar el trait en todos los modelos afectados, más actualizar todos los repositorios. Esto cambia la convención del proyecto y es una decisión de arquitectura que debe confirmarse explícitamente.

**Riesgo 1 — `months` como JSON en MySQL: deserialiización doble posible.**
El servicio llama `json_encode($assignment['months'])` antes de pasar a `sync()` porque el campo en BD es `json`. Laravel, al leer el pivot, aplica el cast `'array'` que hace `json_decode`. Esto es correcto. El riesgo es si alguien llama `sync()` directamente sin pasar por `buildSyncData()` y pasa el array PHP directamente: Laravel lo guardará como `[1,3,6,9]` en el campo json sin problema porque el cast de escritura también hace `json_encode`. En realidad, con el cast `'array'` en el pivot, el servicio puede pasar el array PHP directamente (sin el `json_encode` manual) y Laravel lo serializa solo. El dev debe verificar este comportamiento con un test: si `sync([id => ['months' => [1,3]]])` con el cast activo guarda correctamente. Si hay dudas, el `json_encode` manual en `buildSyncData` es más explícito.

**Riesgo 2 — `listAllHealthActivitiesApi` con `per_page=200`.**
El selector en `HealthPlanTemplateDrawer` carga todas las actividades con `per_page=200` para no tener paginación en el selector de la matriz. Si en el futuro el catálogo de actividades supera 200 registros, esta estrategia falla silenciosamente (trunca). Alternativa futura: endpoint `/v1/admin/health-activities/all` sin paginación, o virtualizar el selector.

**Riesgo 3 — Rutas de menú bajo `/health/`.**
Las rutas de técnicas están en `/techniques/` sin prefijo de área. Las nuevas rutas usan `/health/` como prefijo. Verificar que no haya colisión con otras rutas en el router. El router usa `/:pathMatch(.*)* → /dashboard` como fallback, así que no hay riesgo de 404, pero sí de navegación incorrecta si el menú referencia paths mal escritos.

**Riesgo 4 — `UpdateHealthActivityRequest` y unique con `whereNot('guid', $guid)`.**
La validación de unique en Update usa `whereNot('guid', $guid)` en lugar del tradicional `Rule::unique()->ignore($id)` porque se trabaja con GUID. Esto es correcto pero menos idiomático. El dev debe asegurar que el GUID llega correctamente desde `$this->route('guid')` en el contexto del FormRequest.

**Consideración — Multi-país:**
Los catálogos de `health_activities` son globales de la plataforma. Actividades como "Vacuna Aftosa" son específicas de Argentina/Sudamérica. La tabla no tiene campo `country_id` ni `species`. Si en el futuro se necesita filtrar por país o especie, habrá que agregar esas columnas. Por ahora el catálogo es genérico — marcado como deuda de arquitectura futura.

**Consideración — Multi-tenant:**
Este módulo es catálogo de plataforma (solo SuperAdmin). No hay scope de tenant. Cuando el módulo de Planes Sanitarios por establecimiento se implemente, los endpoints de lectura para el panel vet (`GET /v1/health-activities`, etc.) no necesitan filtrar por tenant porque son catálogos globales. Solo los planes instanciados en establecimientos tendrán scope de tenant.

---

## Pendientes / fuera de alcance

- **Endpoints de lectura para panel vet** (`GET /v1/health-activities`, `/v1/health-plan-templates`): se implementan con el módulo de Planes Sanitarios por establecimiento.
- **Instanciación de planes sanitarios en establecimientos** (`HealthPlan` por establecimiento, vinculado a un template): módulo separado con scope de tenant.
- **Filtro por especie o país en el catálogo de actividades**: deuda de arquitectura futura si los catálogos crecen con actividades específicas por mercado.
- **Seeder de datos iniciales** (actividades predefinidas como "Vacuna Aftosa", "Brucelosis", etc.): útil para el demo, pero no es parte de la implementación del módulo de catálogo en sí.
- **Export del catálogo** (CSV/Excel): usar el módulo de exports existente cuando sea requerido.

---

## Supuestos hechos

1. No existe ninguna tabla `health_*` en la base de datos (confirmado por grep y glob).
2. El proyecto corre MySQL con soporte de tipo `json` nativo (confirmado por la decisión del brief y el patrón de migraciones existentes).
3. `listAllHealthActivitiesApi` con `per_page=200` es suficiente para el volumen inicial del catálogo.
4. El componente `BaseDrawer` acepta slot `footer` y funciona con Ant Design Vue (confirmado por el código del componente existente).
5. `useNotification` existe en `@/core/composables/useNotification` y expone `success` y `error` (inferido del patrón de `useTechniqueMutations.ts`).
