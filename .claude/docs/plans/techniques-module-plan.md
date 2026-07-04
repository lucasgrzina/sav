# Plan técnico: Módulo Técnicas de Reproducción (`techniques`)

## Input procesado

Brief informal del usuario (descripción directa en el chat con plan funcional completo incluido).

---

## Resumen ejecutivo

Se implementa el módulo `techniques` (Técnicas de Reproducción / Vacunas) desde cero en backend Laravel y frontend Vue 3. El módulo expone una tabla jerárquica de 2 niveles (raíz → hijos), con panel SuperAdmin completo (CRUD + detalle con tabs) y endpoints de solo lectura para la API del panel Vet. No hay modelos de Programa ni Protocolo creados todavía: las validaciones de delete que comprueban vínculos con programas/protocolos se implementan como stubs seguros que retornan `false` hasta que esos módulos existan.

---

## Decisiones tomadas

**DEC-01 — Naming de permisos**
Decisión: `techniques.read`, `techniques.create`, `techniques.update`, `techniques.delete` (inglés, consistente con el resto del proyecto).
Justificación: Todo el código existente usa inglés (ver `PermissionSeeder.php`: `tutorials.read`, `clients.create`, etc.). La memoria dice "lectura/alta/etc." pero el código real gana — es la fuente de verdad.
Alternativa descartada: `techniques.lectura` / `techniques.alta` — descartado por inconsistencia con el código real.

**DEC-02 — Controladores Admin: en `V1/` con prefijo de ruta `/v1/admin/`**
Decisión: `App\Http\Controllers\V1\AdminTechniqueController` y `App\Http\Controllers\V1\TechniqueController`, ambos en el namespace `V1`, rutas con prefijo `/v1/admin/techniques` y `/v1/techniques`.
Justificación: El código real usa `V1\AdminClientController` con rutas `/v1/admin/clients`. No hay un namespace `Admin\` separado para controladores. La spec propone `Controllers/Admin/` pero el código existente no sigue ese patrón.
Alternativa descartada: `Controllers/Admin/TechniqueController` — no existe ese subdirectorio en el proyecto real.

**DEC-03 — Validación de programas vinculados al delete**
Decisión: El servicio implementará el método de validación pero retornará `false` (sin programas) hasta que el módulo de programas exista. El contrato de excepción sí se crea ya.
Justificación: Los modelos `Program` y `Protocol` no existen aún en el repo. Crear dependencias hacia tablas inexistentes rompería las migraciones. El stub permite que el delete funcione correctamente hoy y que el dev del módulo de programas solo tenga que cambiar la lógica en un único lugar.
Alternativa descartada: Lanzar excepción siempre en delete — bloquearía la funcionalidad completa sin justificación real.

**DEC-04 — Estructura de Request: un directorio `Techniques/` bajo `Requests/`**
Decisión: `App\Http\Requests\Techniques\CreateTechniqueRequest` y `UpdateTechniqueRequest`.
Justificación: El patrón real del proyecto usa subdirectorios por dominio (ver `Requests/Tutorials/`, `Requests/Clients/`, etc.).
Alternativa descartada: Requests planos en `Requests/` sin subdirectorio — inconsistente con el código real.

**DEC-05 — `parent_id` en FK: `ON DELETE SET NULL`**
Decisión: La FK `parent_id → techniques.id` usa `nullOnDelete()` en la migración.
Justificación: El plan funcional especifica `ON DELETE SET NULL` para preservar los hijos como raíces huérfanas si el padre es eliminado. Sin embargo, la lógica de negocio bloquea el delete de raíces con hijos desde el servicio, por lo que este caso no debería ocurrir en producción. Se mantiene el `SET NULL` como safety net a nivel DB.
Alternativa descartada: `cascadeOnDelete()` — borraría hijos automáticamente, lo que contradice la regla de negocio de bloquear el delete si hay vínculos.

**DEC-06 — Recurso de lista vs detalle: dos Resources distintos**
Decisión: `TechniqueListResource` (lista paginada, sin `children[]`) y `TechniqueResource` (detalle completo con `children[]`).
Justificación: La lista solo necesita `children_count` para la tabla. El detalle necesita el array completo de hijos. Separar los resources evita over-fetching en la lista.
Alternativa descartada: Un único Resource con lógica condicional — más complejo de mantener.

**DEC-07 — Endpoint `/v1/techniques/protocols` — retorna protocolos del vet**
Decisión: Se crea el endpoint como stub que retorna array vacío hasta que el módulo de protocolos exista. El Controller y la ruta se crean completos; solo la lógica de servicio retorna `[]`.
Justificación: El frontend puede necesitar la ruta para no romper llamadas futuras. No hay datos de protocolos en el repo todavía.

**DEC-08 — `type` como `varchar` en migración, no `enum`**
Decisión: Usar `$table->string('type', 50)->default('technique')` en lugar de `enum`.
Justificación: Los enums de MySQL son difíciles de modificar. El proyecto usa `string` con `Rule::in()` en el Request para la validación (mismo patrón que `vets` con sus campos de estado). Más flexible para futuros tipos.
Alternativa descartada: `$table->enum('type', ['technique', 'vaccine'])` — inflexible ante cambios futuros.

**DEC-09 — Sin store Pinia para técnicas: solo Vue Query**
Decisión: No se crea un store Pinia dedicado. El estado de UI (modal de delete, tab activo) se maneja con `shallowRef` local en las páginas.
Justificación: Técnicas es un módulo de lectura/escritura simple. El estado de UI no necesita persistirse ni compartirse entre páginas porque la navegación es por rutas separadas (List / Create / Edit / Detail). Los tutoriales usan store para modales pero ese módulo es una sola página con múltiples modales simultáneos — técnicas usa rutas separadas.
Alternativa descartada: `techniques-ui.store.ts` — sobreingeniería para este caso de uso.

**DEC-10 — Rutas frontend bajo `/superadmin/techniques`**
Decisión: Las rutas del panel admin siguen el patrón `/admin/techniques` (sin el prefijo `superadmin`) para consistir con `/admin/clients`, `/admin/vets`, etc.
Justificación: El router real usa `/admin/clients` y `/admin/vets`. El brief dice `/superadmin/techniques` pero el código gana.
Alternativa descartada: `/superadmin/techniques` — no existe ese prefijo en el router real.

---

## Cambios en BACKEND

### Archivos a crear

#### `back/database/migrations/2026_06_23_000001_create_techniques_table.php`
**Propósito:** Crear la tabla `techniques` con jerarquía self-referencial.
```php
public function up(): void
{
    Schema::create('techniques', function (Blueprint $table) {
        $table->id();
        $table->string('guid', 36)->unique();
        $table->string('name', 255);
        $table->string('target_date_name', 255)->nullable();
        $table->string('type', 50)->default('technique');  // 'technique' | 'vaccine'
        $table->unsignedBigInteger('parent_id')->nullable();
        $table->string('protocols_name', 255)->nullable();
        $table->timestamps();

        $table->foreign('parent_id')
              ->references('id')
              ->on('techniques')
              ->nullOnDelete();

        $table->index('type');
        $table->index('parent_id');
    });
}

public function down(): void
{
    Schema::dropIfExists('techniques');
}
```
**Nota:** Se usa `nullOnDelete()` porque la FK es nullable. El servicio bloquea el delete de raíces con hijos activos, pero la FK actúa como safety net.

---

#### `back/app/Models/Technique.php`
**Propósito:** Modelo Eloquent con relaciones de auto-referencia y trait HasGuid.
```php
namespace App\Models;

use App\Traits\HasGuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Technique extends Model
{
    use HasGuid;

    protected $fillable = [
        'name',
        'target_date_name',
        'type',
        'parent_id',
        'protocols_name',
    ];

    protected $hidden = ['id'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Technique::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Technique::class, 'parent_id');
    }

    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    public function isChild(): bool
    {
        return $this->parent_id !== null;
    }
}
```
**Notas:**
- `$hidden = ['id']` — nunca exponer el ID interno en la API.
- No se define `$casts` porque `parent_id` es entero por defecto en Eloquent.

---

#### `back/app/Contracts/Repositories/TechniqueRepositoryInterface.php`
**Propósito:** Contrato del repositorio de técnicas.
```php
namespace App\Contracts\Repositories;

use App\Models\Technique;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

interface TechniqueRepositoryInterface
{
    public function paginateRoots(array $filters, int $perPage): LengthAwarePaginator;
    public function findByGuid(string $guid): ?Technique;
    public function findRootByGuid(string $guid): ?Technique;
    public function listAll(?string $type = null): Collection;
    public function create(array $data): Technique;
    public function update(Model $model, array $data): Model;
    public function destroy(Model $model): bool|null;
    public function createChild(Technique $parent, array $data): Technique;
    public function deleteChild(Technique $child): bool|null;
    public function syncChildren(Technique $parent, array $childrenData): array;
    // Retorna ['deleted' => Technique[], 'created' => Technique[], 'updated' => Technique[]]
}
```

---

#### `back/app/Repositories/TechniqueRepositoryEloquent.php`
**Propósito:** Implementación Eloquent del repositorio de técnicas.

```php
namespace App\Repositories;

use App\Contracts\Repositories\TechniqueRepositoryInterface;
use App\Models\Technique;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class TechniqueRepositoryEloquent extends BaseRepositoryEloquent implements TechniqueRepositoryInterface
{
    protected function model(): string
    {
        return Technique::class;
    }

    /**
     * Lista paginada de técnicas RAÍZ (parent_id IS NULL) con children_count.
     * Filtros aceptados: 'search' (nombre), 'type' ('technique'|'vaccine').
     */
    public function paginateRoots(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = $this->newQuery()
            ->whereNull('parent_id')
            ->withCount('children');

        if (!empty($filters['search'])) {
            $query->where('name', 'like', '%' . $filters['search'] . '%');
        }

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        return $query->orderBy('name')->paginate($perPage);
    }

    /**
     * Busca por guid sin restricción de raíz/hijo.
     */
    public function findByGuid(string $guid): ?Technique
    {
        /** @var Technique|null */
        return $this->newQuery()->where('guid', $guid)->first();
    }

    /**
     * Busca solo raíces por guid.
     */
    public function findRootByGuid(string $guid): ?Technique
    {
        /** @var Technique|null */
        return $this->newQuery()
            ->whereNull('parent_id')
            ->where('guid', $guid)
            ->first();
    }

    /**
     * Lista todas las técnicas para la API (filtro por type opcional).
     * Retorna solo raíces con sus hijos eager-loaded.
     */
    public function listAll(?string $type = null): Collection
    {
        $query = $this->newQuery()
            ->whereNull('parent_id')
            ->with(['children' => function ($q) use ($type) {
                if ($type) {
                    // El type está en la raíz, no en los hijos. Los hijos heredan el type.
                    // No filtrar children por type.
                }
                $q->orderBy('name');
            }])
            ->orderBy('name');

        if ($type) {
            $query->where('type', $type);
        }

        return $query->get();
    }

    public function create(array $data): Technique
    {
        /** @var Technique */
        return parent::create($data);
    }

    public function createChild(Technique $parent, array $data): Technique
    {
        /** @var Technique */
        return $this->newQuery()->create([
            'name'           => $data['name'],
            'protocols_name' => $data['protocols_name'] ?? null,
            'parent_id'      => $parent->id,
            'type'           => $parent->type, // Los hijos heredan el type del padre
        ]);
    }

    public function deleteChild(Technique $child): bool|null
    {
        return $child->delete();
    }

    /**
     * Sincroniza los hijos de una técnica raíz.
     * - Hijos con 'guid' existente y en la lista: se actualizan.
     * - Hijos con 'guid' existente que NO están en la lista: se eliminan (si no tienen programas).
     * - Elementos sin 'guid': se crean como nuevos hijos.
     *
     * Retorna arrays clasificados para que el servicio valide conflictos antes de ejecutar.
     *
     * @param  array  $childrenData  Array de ['guid?' => string, 'name' => string, 'protocols_name' => string|null]
     * @return array{toDelete: Collection, toUpdate: array, toCreate: array}
     */
    public function prepareSync(Technique $parent, array $childrenData): array
    {
        $existingChildren = $parent->children()->get()->keyBy('guid');
        $incomingGuids    = collect($childrenData)->pluck('guid')->filter()->values();

        $toDelete = $existingChildren->whereNotIn('guid', $incomingGuids->all());

        $toUpdate = collect($childrenData)
            ->filter(fn($c) => isset($c['guid']))
            ->map(fn($c) => ['model' => $existingChildren->get($c['guid']), 'data' => $c])
            ->filter(fn($c) => $c['model'] !== null);

        $toCreate = collect($childrenData)->filter(fn($c) => !isset($c['guid']));

        return compact('toDelete', 'toUpdate', 'toCreate');
    }
}
```

**Nota sobre `createChild`:** Los hijos heredan el `type` del padre porque `type` se define en la raíz y determina si toda la jerarquía es Técnica o Vacuna.

---

#### `back/app/Exceptions/TechniqueCannotBeDeletedException.php`
**Propósito:** Excepción lanzada cuando se intenta eliminar una técnica con programas o hijos vinculados.
```php
namespace App\Exceptions;

class TechniqueCannotBeDeletedException extends \RuntimeException
{
    public function __construct(
        private readonly string $reason,
        private readonly int    $count,
        string                  $message = 'La técnica no puede eliminarse.'
    ) {
        parent::__construct($message);
    }

    public function getReason(): string { return $this->reason; }
    public function getCount(): int     { return $this->count; }
}
```

---

#### `back/app/Exceptions/TechniqueChildHasProgramsException.php`
**Propósito:** Excepción lanzada cuando un hijo a eliminar durante el sync tiene programas vinculados.
```php
namespace App\Exceptions;

class TechniqueChildHasProgramsException extends \RuntimeException
{
    /**
     * @param  array  $conflicts  Array de ['guid' => string, 'name' => string, 'programs_count' => int]
     */
    public function __construct(
        private readonly array $conflicts,
        string $message = 'Algunos sub-técnicas tienen programas vinculados y no pueden eliminarse.'
    ) {
        parent::__construct($message);
    }

    public function getConflicts(): array { return $this->conflicts; }
}
```

---

#### `back/app/Services/TechniqueService.php`
**Propósito:** Lógica de negocio del módulo de técnicas. Orquesta repositorio, valida jerarquía y maneja sync de hijos.

```php
namespace App\Services;

use App\Contracts\Repositories\TechniqueRepositoryInterface;
use App\Exceptions\TechniqueCannotBeDeletedException;
use App\Exceptions\TechniqueChildHasProgramsException;
use App\Models\Technique;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class TechniqueService
{
    public function __construct(
        private TechniqueRepositoryInterface $techniqueRepository,
    ) {}

    /**
     * Lista paginada de raíces para el panel admin.
     */
    public function paginateRoots(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->techniqueRepository->paginateRoots($filters, $perPage);
    }

    /**
     * Crea una técnica raíz y sus hijos dentro de una transacción.
     *
     * @param  array  $data  {name, type, target_date_name?, protocols_name?, children: [{name, protocols_name?}]}
     */
    public function create(array $data): Technique
    {
        return DB::transaction(function () use ($data) {
            $children = $data['children'] ?? [];
            unset($data['children']);

            $technique = $this->techniqueRepository->create($data);

            foreach ($children as $childData) {
                $this->techniqueRepository->createChild($technique, $childData);
            }

            return $technique->load('children');
        });
    }

    /**
     * Busca una técnica raíz por guid.
     */
    public function findRootByGuid(string $guid): ?Technique
    {
        return $this->techniqueRepository->findRootByGuid($guid);
    }

    /**
     * Carga el detalle de una raíz: children + programs paginados del árbol.
     */
    public function getDetail(Technique $technique, int $programsPage = 1, int $programsPerPage = 15): array
    {
        $technique->load('children');

        // STUB: Programas del árbol (raíz + hijos). Retorna paginación vacía hasta que
        // el módulo de programas exista. El dev de programas reemplaza esta lógica.
        $programs = [
            'data'         => [],
            'current_page' => 1,
            'last_page'    => 1,
            'per_page'     => $programsPerPage,
            'total'        => 0,
        ];

        return compact('technique', 'programs');
    }

    /**
     * Actualiza una técnica raíz y sincroniza sus hijos.
     * Valida que ningún hijo a eliminar tenga programas vinculados antes de ejecutar.
     *
     * @throws TechniqueChildHasProgramsException si algún hijo tiene programas
     */
    public function update(Technique $technique, array $data): Technique
    {
        return DB::transaction(function () use ($technique, $data) {
            $childrenData = $data['children'] ?? [];
            unset($data['children']);

            // Actualizar campos raíz
            $this->techniqueRepository->update($technique, $data);

            // Preparar sync sin ejecutarlo todavía
            $sync = $this->techniqueRepository->prepareSync($technique, $childrenData);

            // Validar que los hijos a eliminar no tengan programas
            $conflicts = [];
            foreach ($sync['toDelete'] as $child) {
                $count = $this->countProgramsForTechnique($child);
                if ($count > 0) {
                    $conflicts[] = [
                        'guid'           => $child->guid,
                        'name'           => $child->name,
                        'programs_count' => $count,
                    ];
                }
            }

            if (!empty($conflicts)) {
                throw new TechniqueChildHasProgramsException($conflicts);
            }

            // Eliminar
            foreach ($sync['toDelete'] as $child) {
                $this->techniqueRepository->deleteChild($child);
            }

            // Actualizar existentes
            foreach ($sync['toUpdate'] as $item) {
                $this->techniqueRepository->update($item['model'], [
                    'name'           => $item['data']['name'],
                    'protocols_name' => $item['data']['protocols_name'] ?? null,
                ]);
            }

            // Crear nuevos
            foreach ($sync['toCreate'] as $childData) {
                $this->techniqueRepository->createChild($technique, $childData->toArray());
            }

            return $technique->fresh()->load('children');
        });
    }

    /**
     * Elimina una técnica raíz con validación completa.
     *
     * @throws TechniqueCannotBeDeletedException
     */
    public function destroy(Technique $technique): void
    {
        $technique->load('children');

        // 1. Verificar si tiene hijos con programas
        foreach ($technique->children as $child) {
            $count = $this->countProgramsForTechnique($child);
            if ($count > 0) {
                throw new TechniqueCannotBeDeletedException(
                    reason: 'children_have_programs',
                    count: $count,
                    message: 'La técnica tiene sub-técnicas con programas vinculados.',
                );
            }
        }

        // 2. Verificar si la raíz tiene protocolos directos
        $protocolCount = $this->countProtocolsForTechnique($technique);
        if ($protocolCount > 0) {
            throw new TechniqueCannotBeDeletedException(
                reason: 'has_protocols',
                count: $protocolCount,
                message: 'La técnica tiene protocolos vinculados.',
            );
        }

        // 3. Verificar si tiene programas directos
        $programCount = $this->countProgramsForTechnique($technique);
        if ($programCount > 0) {
            throw new TechniqueCannotBeDeletedException(
                reason: 'has_programs',
                count: $programCount,
                message: 'La técnica tiene programas vinculados.',
            );
        }

        DB::transaction(function () use ($technique) {
            // Eliminar hijos primero (la FK es nullable, no cascade)
            foreach ($technique->children as $child) {
                $this->techniqueRepository->deleteChild($child);
            }
            $this->techniqueRepository->destroy($technique);
        });
    }

    /**
     * Lista todas las técnicas para la API del panel vet (jerarquía completa).
     */
    public function listForApi(?string $type = null): Collection
    {
        return $this->techniqueRepository->listAll($type);
    }

    /**
     * STUB: Retorna cantidad de programas vinculados a una técnica.
     * Cuando el módulo de programas exista, reemplazar con:
     *   return Program::where('technique_id', $technique->id)->count();
     * (o la query correspondiente según el modelo de Program).
     */
    private function countProgramsForTechnique(Technique $technique): int
    {
        // TODO: implementar cuando exista el modelo Program
        return 0;
    }

    /**
     * STUB: Retorna cantidad de protocolos vinculados a una técnica.
     * Cuando el módulo de protocolos exista, reemplazar con la query real.
     */
    private function countProtocolsForTechnique(Technique $technique): int
    {
        // TODO: implementar cuando exista el modelo Protocol
        return 0;
    }
}
```

---

#### `back/app/Http/Requests/Techniques/CreateTechniqueRequest.php`
**Propósito:** Validación de creación de técnica raíz con hijos.

```php
namespace App\Http\Requests\Techniques;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateTechniqueRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'               => ['required', 'string', 'max:255'],
            'type'               => ['required', Rule::in(['technique', 'vaccine'])],
            'target_date_name'   => ['nullable', 'string', 'max:255'],
            'protocols_name'     => ['nullable', 'string', 'max:255'],
            'children'           => ['nullable', 'array', 'max:50'],
            'children.*.name'    => ['required', 'string', 'max:255'],
            'children.*.protocols_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'            => 'El nombre es requerido.',
            'name.max'                 => 'El nombre no puede superar 255 caracteres.',
            'type.required'            => 'El tipo es requerido.',
            'type.in'                  => 'El tipo debe ser "technique" o "vaccine".',
            'children.array'           => 'Las sub-técnicas deben ser un array.',
            'children.max'             => 'No se pueden agregar más de 50 sub-técnicas.',
            'children.*.name.required' => 'El nombre de la sub-técnica es requerido.',
            'children.*.name.max'      => 'El nombre de la sub-técnica no puede superar 255 caracteres.',
        ];
    }
}
```

---

#### `back/app/Http/Requests/Techniques/UpdateTechniqueRequest.php`
**Propósito:** Validación de actualización de técnica raíz. Permite sync de hijos con guid.

```php
namespace App\Http\Requests\Techniques;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTechniqueRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'               => ['required', 'string', 'max:255'],
            'type'               => ['required', Rule::in(['technique', 'vaccine'])],
            'target_date_name'   => ['nullable', 'string', 'max:255'],
            'protocols_name'     => ['nullable', 'string', 'max:255'],
            'children'           => ['nullable', 'array', 'max:50'],
            'children.*.guid'    => ['nullable', 'string', 'uuid'],  // presente si es hijo existente
            'children.*.name'    => ['required', 'string', 'max:255'],
            'children.*.protocols_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'            => 'El nombre es requerido.',
            'name.max'                 => 'El nombre no puede superar 255 caracteres.',
            'type.required'            => 'El tipo es requerido.',
            'type.in'                  => 'El tipo debe ser "technique" o "vaccine".',
            'children.array'           => 'Las sub-técnicas deben ser un array.',
            'children.max'             => 'No se pueden agregar más de 50 sub-técnicas.',
            'children.*.name.required' => 'El nombre de la sub-técnica es requerido.',
            'children.*.name.max'      => 'El nombre de la sub-técnica no puede superar 255 caracteres.',
            'children.*.guid.uuid'     => 'El identificador de la sub-técnica no es válido.',
        ];
    }
}
```

---

#### `back/app/Http/Requests/Techniques/IndexTechniqueRequest.php`
**Propósito:** Validación de filtros para la lista paginada.

```php
namespace App\Http\Requests\Techniques;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexTechniqueRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'search'   => ['nullable', 'string', 'max:100'],
            'type'     => ['nullable', Rule::in(['technique', 'vaccine'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page'     => ['nullable', 'integer', 'min:1'],
        ];
    }
}
```

---

#### `back/app/Http/Resources/V1/TechniqueListResource.php`
**Propósito:** Resource para la lista paginada (raíces con `children_count`, sin array de hijos).

```php
namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TechniqueListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'guid'             => $this->guid,
            'name'             => $this->name,
            'type'             => $this->type,
            'target_date_name' => $this->target_date_name,
            'protocols_name'   => $this->protocols_name,
            'children_count'   => $this->children_count ?? 0,
            'created_at'       => $this->created_at?->toISOString(),
            'updated_at'       => $this->updated_at?->toISOString(),
        ];
    }
}
```

---

#### `back/app/Http/Resources/V1/TechniqueChildResource.php`
**Propósito:** Resource para sub-técnicas (hijos), usado en el detalle.

```php
namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TechniqueChildResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'guid'           => $this->guid,
            'name'           => $this->name,
            'protocols_name' => $this->protocols_name,
        ];
    }
}
```

---

#### `back/app/Http/Resources/V1/TechniqueResource.php`
**Propósito:** Resource completo para detalle y respuestas de creación/actualización.

```php
namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TechniqueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'guid'             => $this->guid,
            'name'             => $this->name,
            'type'             => $this->type,
            'target_date_name' => $this->target_date_name,
            'protocols_name'   => $this->protocols_name,
            'parent_id'        => null,  // Siempre null: este resource es solo para raíces
            'is_root'          => true,
            'children'         => TechniqueChildResource::collection(
                $this->whenLoaded('children', $this->children, collect())
            ),
            'children_count'   => $this->children_count ?? $this->children->count(),
            'created_at'       => $this->created_at?->toISOString(),
            'updated_at'       => $this->updated_at?->toISOString(),
        ];
    }
}
```

---

#### `back/app/Http/Controllers/V1/AdminTechniqueController.php`
**Propósito:** Controller del panel SuperAdmin para CRUD completo de técnicas.

```php
namespace App\Http\Controllers\V1;

use App\Exceptions\TechniqueCannotBeDeletedException;
use App\Exceptions\TechniqueChildHasProgramsException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Techniques\CreateTechniqueRequest;
use App\Http\Requests\Techniques\IndexTechniqueRequest;
use App\Http\Requests\Techniques\UpdateTechniqueRequest;
use App\Http\Resources\V1\TechniqueListResource;
use App\Http\Resources\V1\TechniqueResource;
use App\Services\TechniqueService;
use Illuminate\Http\JsonResponse;

class AdminTechniqueController extends Controller
{
    public function __construct(
        private TechniqueService $techniqueService,
    ) {}

    public function index(IndexTechniqueRequest $request): JsonResponse
    {
        try {
            $perPage  = $request->integer('per_page', 15);
            $paginator = $this->techniqueService->paginateRoots($request->validated(), $perPage);
            return $this->makeSuccessPagination($paginator, TechniqueListResource::class);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function store(CreateTechniqueRequest $request): JsonResponse
    {
        try {
            $technique = $this->techniqueService->create($request->validated());
            return $this->makeSuccess(new TechniqueResource($technique), 'Técnica creada correctamente.', 201);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function show(string $guid): JsonResponse
    {
        try {
            $technique = $this->techniqueService->findRootByGuid($guid);
            if (!$technique) {
                return $this->makeNotFound('Técnica no encontrada.');
            }
            $detail = $this->techniqueService->getDetail($technique);
            return $this->makeSuccess([
                'technique' => new TechniqueResource($detail['technique']),
                'programs'  => $detail['programs'],
            ]);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function update(UpdateTechniqueRequest $request, string $guid): JsonResponse
    {
        try {
            $technique = $this->techniqueService->findRootByGuid($guid);
            if (!$technique) {
                return $this->makeNotFound('Técnica no encontrada.');
            }
            $technique = $this->techniqueService->update($technique, $request->validated());
            return $this->makeSuccess(new TechniqueResource($technique), 'Técnica actualizada correctamente.');
        } catch (TechniqueChildHasProgramsException $e) {
            return $this->makeError(
                ['conflicts' => $e->getConflicts()],
                $e->getMessage(),
                422,
            );
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function destroy(string $guid): JsonResponse
    {
        try {
            $technique = $this->techniqueService->findRootByGuid($guid);
            if (!$technique) {
                return $this->makeNotFound('Técnica no encontrada.');
            }
            $this->techniqueService->destroy($technique);
            return $this->makeSuccess(null, 'Técnica eliminada correctamente.');
        } catch (TechniqueCannotBeDeletedException $e) {
            return $this->makeError(
                ['reason' => $e->getReason(), 'count' => $e->getCount()],
                $e->getMessage(),
                422,
            );
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
```

---

#### `back/app/Http/Controllers/V1/TechniqueController.php`
**Propósito:** Controller de la API del panel Vet — solo lectura.

```php
namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\TechniqueResource;
use App\Services\TechniqueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TechniqueController extends Controller
{
    public function __construct(
        private TechniqueService $techniqueService,
    ) {}

    /**
     * GET /v1/techniques?type=technique|vaccine
     * Retorna jerarquía completa (raíces + hijos) para el selector de técnicas del vet.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $type       = $request->query('type'); // nullable
            $techniques = $this->techniqueService->listForApi($type);
            return $this->makeSuccess(TechniqueResource::collection($techniques));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    /**
     * GET /v1/techniques/protocols
     * STUB: Retorna protocolos del vet agrupados por técnica.
     * Implementar cuando exista el módulo de protocolos.
     */
    public function protocols(Request $request): JsonResponse
    {
        try {
            // TODO: Implementar cuando exista el módulo de protocolos
            return $this->makeSuccess([]);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    /**
     * GET /v1/techniques/{guid}/protocols
     * STUB: Retorna protocolos de una técnica específica del vet.
     */
    public function techniqueProtocols(string $guid): JsonResponse
    {
        try {
            $technique = $this->techniqueService->findRootByGuid($guid);
            if (!$technique) {
                return $this->makeNotFound('Técnica no encontrada.');
            }
            // TODO: Implementar cuando exista el módulo de protocolos
            return $this->makeSuccess([]);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
```

---

#### `back/database/seeders/TechniquePermissionsSeeder.php`
**Propósito:** Agrega los 4 permisos de técnicas y los asigna al rol `super-admin`.

```php
namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TechniquePermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'techniques.read',
            'techniques.create',
            'techniques.update',
            'techniques.delete',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['guid' => Str::uuid()->toString()],
            );
        }

        $superAdmin = Role::where('name', 'super-admin')->first();
        if ($superAdmin) {
            // Re-sync con TODOS los permisos (el super-admin ya tiene syncPermissions(Permission::all()) en RoleSeeder)
            $superAdmin->syncPermissions(Permission::all());
        }
    }
}
```

---

### Archivos a modificar

#### `back/app/Providers/AppServiceProvider.php`
**Cambio:** Agregar binding del repositorio de técnicas.
**Antes (resumido):** Lista de bindings existentes termina con `TutorialRepositoryInterface`.
**Después:** Agregar al final de `register()`:
```php
// En la sección de use statements agregar:
use App\Contracts\Repositories\TechniqueRepositoryInterface;
use App\Repositories\TechniqueRepositoryEloquent;

// En register():
$this->app->bind(TechniqueRepositoryInterface::class, TechniqueRepositoryEloquent::class);
```

---

#### `back/app/Helpers/ResponseHelper.php`
**Cambio:** Agregar handling de las dos excepciones nuevas en `makeFromException()`.
**Antes:** El método maneja `RoleImmutableException` y luego `HttpException`.
**Después:** Agregar antes del bloque `HttpException`:
```php
use App\Exceptions\TechniqueCannotBeDeletedException;
use App\Exceptions\TechniqueChildHasProgramsException;

// En makeFromException():
if ($e instanceof TechniqueCannotBeDeletedException) {
    return self::errorResponse(
        ['reason' => $e->getReason(), 'count' => $e->getCount()],
        $e->getMessage(),
        422,
    );
}

if ($e instanceof TechniqueChildHasProgramsException) {
    return self::errorResponse(
        ['conflicts' => $e->getConflicts()],
        $e->getMessage(),
        422,
    );
}
```
**Nota:** Esto asegura que cualquier controller que use `makeFromException()` sin catch explícito también maneje estas excepciones correctamente.

---

#### `back/database/seeders/PermissionSeeder.php`
**Cambio:** Agregar los 4 permisos de técnicas al array `$permissions`.
**Antes (resumido):** Array termina con `'tutorials.delete'`.
**Después:** Agregar al final del array:
```php
'techniques.read',
'techniques.create',
'techniques.update',
'techniques.delete',
```

---

#### `back/database/seeders/DatabaseSeeder.php`
**Cambio:** Registrar el nuevo seeder.
**Después:** Agregar `$this->call(TechniquePermissionsSeeder::class);` luego del llamado al `RoleSeeder`.

---

### Rutas API

#### Nuevo archivo: `back/routes/api/techniques.php`

```php
<?php

use App\Http\Controllers\V1\AdminTechniqueController;
use App\Http\Controllers\V1\TechniqueController;
use Illuminate\Support\Facades\Route;

// Panel SuperAdmin — CRUD completo
Route::prefix('v1/admin/techniques')->middleware('auth:sanctum')->group(function () {
    Route::get('/',        [AdminTechniqueController::class, 'index'])  ->middleware('can:techniques.read');
    Route::post('/',       [AdminTechniqueController::class, 'store'])  ->middleware('can:techniques.create');
    Route::get('/{guid}',  [AdminTechniqueController::class, 'show'])   ->middleware('can:techniques.read');
    Route::put('/{guid}',  [AdminTechniqueController::class, 'update']) ->middleware('can:techniques.update');
    Route::delete('/{guid}', [AdminTechniqueController::class, 'destroy'])->middleware('can:techniques.delete');
});

// API panel Vet — solo lectura
// IMPORTANTE: /protocols debe ir ANTES de /{guid} para evitar colisión de rutas
Route::prefix('v1/techniques')->middleware('auth:sanctum')->group(function () {
    Route::get('/',            [TechniqueController::class, 'index']);
    Route::get('/protocols',   [TechniqueController::class, 'protocols']);
    Route::get('/{guid}/protocols', [TechniqueController::class, 'techniqueProtocols']);
});
```

**Nota sobre middleware:** Las rutas de la API del vet no usan `can:techniques.read` directamente porque las técnicas son datos globales de la plataforma (no son datos de un tenant). Cualquier usuario autenticado puede leer técnicas. Si en el futuro se quiere restringir, agregar el middleware.

---

### Contrato del endpoint

#### POST `/v1/admin/techniques`
Request:
```json
{
  "name": "Inseminación Artificial",
  "type": "technique",
  "target_date_name": "Fecha de servicio",
  "protocols_name": "Seleccionar protocolo de sincronización",
  "children": [
    { "name": "IA a tiempo fijo", "protocols_name": "Protocolo IATF" },
    { "name": "IA convencional", "protocols_name": null }
  ]
}
```
Response 201:
```json
{
  "success": true,
  "data": {
    "guid": "uuid-raiz",
    "name": "Inseminación Artificial",
    "type": "technique",
    "target_date_name": "Fecha de servicio",
    "protocols_name": "Seleccionar protocolo de sincronización",
    "parent_id": null,
    "is_root": true,
    "children": [
      { "guid": "uuid-hijo-1", "name": "IA a tiempo fijo", "protocols_name": "Protocolo IATF" },
      { "guid": "uuid-hijo-2", "name": "IA convencional", "protocols_name": null }
    ],
    "children_count": 2,
    "created_at": "2026-06-23T00:00:00.000Z",
    "updated_at": "2026-06-23T00:00:00.000Z"
  },
  "message": "Técnica creada correctamente."
}
```

#### GET `/v1/admin/techniques`
Response 200:
```json
{
  "success": true,
  "data": {
    "data": [
      {
        "guid": "uuid",
        "name": "Inseminación Artificial",
        "type": "technique",
        "target_date_name": "Fecha de servicio",
        "protocols_name": "Seleccionar protocolo",
        "children_count": 3,
        "created_at": "...",
        "updated_at": "..."
      }
    ],
    "current_page": 1,
    "last_page": 1,
    "per_page": 15,
    "total": 5
  }
}
```

#### PUT `/v1/admin/techniques/{guid}`
Request (sync de hijos: hijos con guid = existentes, sin guid = nuevos, ausentes en el array = se eliminan):
```json
{
  "name": "Inseminación Artificial",
  "type": "technique",
  "target_date_name": "Fecha de servicio",
  "protocols_name": "Seleccionar protocolo de sincronización",
  "children": [
    { "guid": "uuid-hijo-existente", "name": "IA a tiempo fijo", "protocols_name": "Protocolo IATF" },
    { "name": "IA con detección de celo", "protocols_name": null }
  ]
}
```

#### DELETE `/v1/admin/techniques/{guid}` — Error 422
```json
{
  "success": false,
  "message": "La técnica tiene sub-técnicas con programas vinculados.",
  "errors": {
    "reason": "children_have_programs",
    "count": 3
  }
}
```

#### PUT `/v1/admin/techniques/{guid}` — Error 422 (sync con conflictos)
```json
{
  "success": false,
  "message": "Algunos sub-técnicas tienen programas vinculados y no pueden eliminarse.",
  "errors": {
    "conflicts": [
      { "guid": "uuid-hijo", "name": "IA a tiempo fijo", "programs_count": 2 }
    ]
  }
}
```

#### GET `/v1/admin/techniques/{guid}`
Response 200:
```json
{
  "success": true,
  "data": {
    "technique": {
      "guid": "uuid",
      "name": "Inseminación Artificial",
      "type": "technique",
      "target_date_name": "Fecha de servicio",
      "protocols_name": "Seleccionar protocolo",
      "parent_id": null,
      "is_root": true,
      "children": [
        { "guid": "uuid-hijo-1", "name": "IA a tiempo fijo", "protocols_name": "Protocolo IATF" }
      ],
      "children_count": 1,
      "created_at": "...",
      "updated_at": "..."
    },
    "programs": {
      "data": [],
      "current_page": 1,
      "last_page": 1,
      "per_page": 15,
      "total": 0
    }
  }
}
```

#### GET `/v1/techniques`
Parámetros: `?type=technique` (opcional)
Response 200:
```json
{
  "success": true,
  "data": [
    {
      "guid": "uuid",
      "name": "Inseminación Artificial",
      "type": "technique",
      "target_date_name": "Fecha de servicio",
      "protocols_name": "Seleccionar protocolo",
      "parent_id": null,
      "is_root": true,
      "children": [
        { "guid": "uuid-hijo", "name": "IA a tiempo fijo", "protocols_name": "Protocolo IATF" }
      ],
      "children_count": 1,
      "created_at": "...",
      "updated_at": "..."
    }
  ]
}
```

#### Errores posibles
| HTTP | Cuándo |
|------|--------|
| 404  | Técnica no encontrada por guid |
| 422  | Validación del request fallida |
| 422  | Delete bloqueado por programas/protocolos vinculados |
| 422  | Update bloqueado: hijos a eliminar tienen programas |
| 500  | Error interno |

---

### Tests a generar

**Feature tests — AdminTechniqueController:**
- `index`: happy path con paginación, filtro por `search`, filtro por `type`.
- `store`: crear raíz sin hijos, crear raíz con hijos, validación falla (name vacío, type inválido).
- `show`: retorna detalle con children, retorna 404 para guid inexistente.
- `update`: actualizar nombre raíz, agregar hijo nuevo, eliminar hijo existente, actualizar hijo existente, retorna 422 cuando hijo a eliminar tiene programas (cuando el stub sea reemplazado).
- `destroy`: eliminar raíz sin hijos sin programas, retorna 422 cuando tiene programas.

**Feature tests — TechniqueController:**
- `index`: retorna jerarquía completa, filtra por `type`.
- `protocols`: retorna array vacío (stub).
- `techniqueProtocols`: retorna array vacío, retorna 404 para guid inexistente.

**Unit tests — TechniqueService:**
- `create`: crea raíz + hijos en transacción.
- `update prepareSync`: identifica correctamente hijos a crear, actualizar y eliminar.
- `destroy`: lanza `TechniqueCannotBeDeletedException` cuando los stubs de conteo retornan > 0.

---

## Cambios en FRONTEND

### Archivos a crear

#### `front/src/modules/techniques/types/technique.types.ts`
**Propósito:** Tipos TypeScript del módulo.

```typescript
export type TechniqueType = 'technique' | 'vaccine'

export interface TechniqueChild {
  guid?: string          // presente solo en sub-técnicas ya persistidas
  name: string
  protocols_name: string | null
}

export interface Technique {
  guid: string
  name: string
  type: TechniqueType
  target_date_name: string | null
  protocols_name: string | null
  parent_id: null
  is_root: true
  children: TechniqueChild[]
  children_count: number
  created_at: string
  updated_at: string
}

export interface TechniqueListItem {
  guid: string
  name: string
  type: TechniqueType
  target_date_name: string | null
  protocols_name: string | null
  children_count: number
  created_at: string
  updated_at: string
}

export interface TechniqueDetail {
  technique: Technique
  programs: ProgramsStub
}

export interface ProgramsStub {
  data: []
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface TechniqueFormData {
  name: string
  type: TechniqueType
  target_date_name: string
  protocols_name: string
  children: TechniqueChild[]
}

export interface CreateTechniquePayload {
  name: string
  type: TechniqueType
  target_date_name: string | null
  protocols_name: string | null
  children: Array<{ name: string; protocols_name: string | null }>
}

export interface UpdateTechniquePayload {
  name: string
  type: TechniqueType
  target_date_name: string | null
  protocols_name: string | null
  children: Array<{ guid?: string; name: string; protocols_name: string | null }>
}

export interface TechniqueListParams {
  search?: string
  type?: TechniqueType
  page?: number
  per_page?: number
}

export interface TechniqueDeleteError {
  reason: 'has_programs' | 'has_protocols' | 'children_have_programs'
  count: number
}

export interface TechniqueChildConflict {
  guid: string
  name: string
  programs_count: number
}
```

---

#### `front/src/modules/techniques/api/technique.api.ts`
**Propósito:** Funciones de llamada HTTP al backend. El interceptor de Axios desenvuelve `{ success, data }` automáticamente.

```typescript
import { http } from '@/core/api/http'
import type {
  TechniqueListItem,
  Technique,
  TechniqueDetail,
  TechniqueListParams,
  CreateTechniquePayload,
  UpdateTechniquePayload,
} from '../types/technique.types'
import type { PaginatedResponse } from '@/core/types/pagination.types'

// Admin endpoints

export async function adminListTechniquesApi(
  params: TechniqueListParams,
  signal?: AbortSignal,
): Promise<PaginatedResponse<TechniqueListItem>> {
  const res = await http.get<PaginatedResponse<TechniqueListItem>>('/v1/admin/techniques', { params, signal })
  return res.data
}

export async function adminGetTechniqueApi(guid: string): Promise<TechniqueDetail> {
  const res = await http.get<TechniqueDetail>(`/v1/admin/techniques/${guid}`)
  return res.data
}

export async function adminCreateTechniqueApi(payload: CreateTechniquePayload): Promise<Technique> {
  const res = await http.post<Technique>('/v1/admin/techniques', payload)
  return res.data
}

export async function adminUpdateTechniqueApi(
  guid: string,
  payload: UpdateTechniquePayload,
): Promise<Technique> {
  const res = await http.put<Technique>(`/v1/admin/techniques/${guid}`, payload)
  return res.data
}

export async function adminDeleteTechniqueApi(guid: string): Promise<void> {
  await http.delete(`/v1/admin/techniques/${guid}`)
}

// API endpoints (panel Vet)

export async function listTechniquesApi(
  type?: 'technique' | 'vaccine',
  signal?: AbortSignal,
): Promise<Technique[]> {
  const res = await http.get<Technique[]>('/v1/techniques', {
    params: type ? { type } : undefined,
    signal,
  })
  return res.data
}
```

---

#### `front/src/modules/techniques/validators/technique.validator.ts`
**Propósito:** Schema Zod para el formulario Create/Edit.

```typescript
import { z } from 'zod'

const techniqueTypeValues = ['technique', 'vaccine'] as const

export const techniqueChildSchema = z.object({
  guid: z.string().uuid().optional(),
  name: z
    .string()
    .min(1, 'El nombre de la sub-técnica es requerido')
    .max(255, 'El nombre no puede superar 255 caracteres'),
  protocols_name: z
    .string()
    .max(255, 'El label no puede superar 255 caracteres')
    .nullable()
    .optional()
    .transform((val) => val ?? null),
})

export const techniqueSchema = z.object({
  name: z
    .string()
    .min(1, 'El nombre es requerido')
    .max(255, 'El nombre no puede superar 255 caracteres'),
  type: z.enum(techniqueTypeValues, {
    errorMap: () => ({ message: 'Seleccioná un tipo' }),
  }),
  target_date_name: z
    .string()
    .max(255, 'El label no puede superar 255 caracteres')
    .nullable()
    .optional()
    .transform((val) => val ?? null),
  protocols_name: z
    .string()
    .max(255, 'El label no puede superar 255 caracteres')
    .nullable()
    .optional()
    .transform((val) => val ?? null),
  children: z.array(techniqueChildSchema).optional().default([]),
})

export type TechniqueFormValues = z.infer<typeof techniqueSchema>
```

---

#### `front/src/modules/techniques/composables/useTechniqueList.ts`
**Propósito:** Vue Query para la lista paginada de técnicas (panel admin).

```typescript
import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import type { Ref } from 'vue'
import { adminListTechniquesApi } from '../api/technique.api'
import type { TechniqueListParams } from '../types/technique.types'

export function useTechniqueList(
  params: Ref<TechniqueListParams> | TechniqueListParams = {},
) {
  const paramsRef = computed(() => toValue(params))

  return useQuery({
    queryKey: ['admin-techniques', paramsRef],
    queryFn: ({ signal }) => adminListTechniquesApi(paramsRef.value, signal),
    staleTime: 1000 * 30,
  })
}
```

---

#### `front/src/modules/techniques/composables/useTechniqueDetail.ts`
**Propósito:** Vue Query para el detalle de una técnica.

```typescript
import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import type { Ref } from 'vue'
import { adminGetTechniqueApi } from '../api/technique.api'

export function useTechniqueDetail(guid: Ref<string> | string) {
  const guidValue = computed(() => toValue(guid))

  return useQuery({
    queryKey: ['admin-technique', guidValue],
    queryFn: () => adminGetTechniqueApi(guidValue.value),
    enabled: computed(() => Boolean(guidValue.value)),
  })
}
```

---

#### `front/src/modules/techniques/composables/useTechniqueMutations.ts`
**Propósito:** Mutations de create, update y delete con invalidación de queries y manejo de errores 422.

```typescript
import { ref } from 'vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { useNotification } from '@/core/composables/useNotification'
import { useConfirm } from '@/core/composables/useConfirm'
import { parseApiError } from '@/core/composables/parseApiError'
import {
  adminCreateTechniqueApi,
  adminUpdateTechniqueApi,
  adminDeleteTechniqueApi,
} from '../api/technique.api'
import type {
  TechniqueListItem,
  CreateTechniquePayload,
  UpdateTechniquePayload,
  TechniqueDeleteError,
  TechniqueChildConflict,
} from '../types/technique.types'

export function useCreateTechnique() {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const fieldErrors = ref<Record<string, string> | null>(null)
  const generalError = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: (payload: CreateTechniquePayload) => adminCreateTechniqueApi(payload),
    onMutate: () => {
      fieldErrors.value = null
      generalError.value = null
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-techniques'] })
      success('Técnica creada correctamente')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      fieldErrors.value = apiError.fieldErrors
      generalError.value = apiError.message ?? 'Error al crear la técnica.'
      if (apiError.message) error('Error al crear la técnica')
    },
  })

  function resetErrors() {
    fieldErrors.value = null
    generalError.value = null
    mutation.reset()
  }

  return { ...mutation, fieldErrors, generalError, resetErrors }
}

export function useUpdateTechnique() {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const fieldErrors = ref<Record<string, string> | null>(null)
  const generalError = ref<string | null>(null)
  // Conflictos al hacer sync de hijos (hijos con programas que no pueden eliminarse)
  const childConflicts = ref<TechniqueChildConflict[]>([])

  const mutation = useMutation({
    mutationFn: ({ guid, payload }: { guid: string; payload: UpdateTechniquePayload }) =>
      adminUpdateTechniqueApi(guid, payload),
    onMutate: () => {
      fieldErrors.value = null
      generalError.value = null
      childConflicts.value = []
    },
    onSuccess: (_, { guid }) => {
      queryClient.invalidateQueries({ queryKey: ['admin-techniques'] })
      queryClient.invalidateQueries({ queryKey: ['admin-technique', guid] })
      success('Técnica actualizada correctamente')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      // Detectar conflictos de hijos (errores.conflicts)
      if (apiError.rawErrors?.conflicts) {
        childConflicts.value = apiError.rawErrors.conflicts as TechniqueChildConflict[]
        generalError.value = apiError.message ?? 'Hay sub-técnicas con programas vinculados.'
      } else {
        fieldErrors.value = apiError.fieldErrors
        generalError.value = apiError.message ?? 'Error al actualizar la técnica.'
      }
      if (apiError.message) error('Error al actualizar la técnica')
    },
  })

  function resetErrors() {
    fieldErrors.value = null
    generalError.value = null
    childConflicts.value = []
    mutation.reset()
  }

  return { ...mutation, fieldErrors, generalError, childConflicts, resetErrors }
}

export function useDeleteTechnique() {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const confirm = useConfirm()
  // Error 422 del backend cuando hay programas vinculados
  const deleteBlockedError = ref<TechniqueDeleteError | null>(null)

  const mutation = useMutation({
    mutationFn: (guid: string) => adminDeleteTechniqueApi(guid),
    onMutate: () => {
      deleteBlockedError.value = null
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-techniques'] })
      success('Técnica eliminada correctamente')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      if (apiError.status === 422 && apiError.rawErrors?.reason) {
        // Backend retornó 422 con reason/count — mostrar modal de bloqueo
        deleteBlockedError.value = {
          reason: apiError.rawErrors.reason as TechniqueDeleteError['reason'],
          count: apiError.rawErrors.count as number,
        }
      } else {
        error('Error al eliminar la técnica')
      }
    },
  })

  async function deleteTechnique(technique: TechniqueListItem) {
    await confirm.confirm({
      title: 'Eliminar técnica',
      message: `¿Estás seguro de que querés eliminar "${technique.name}"? Esta acción no se puede deshacer.`,
      confirmLabel: 'Eliminar',
      danger: true,
      onConfirm: () => mutation.mutateAsync(technique.guid),
    })
  }

  function clearDeleteError() {
    deleteBlockedError.value = null
    mutation.reset()
  }

  return { ...mutation, deleteBlockedError, deleteTechnique, clearDeleteError }
}
```

**Nota sobre `parseApiError`:** El composable accede a `apiError.rawErrors` para distinguir entre errores de campo y errores de negocio 422. Si `parseApiError` no expone `rawErrors`, el dev debe agregar ese campo o desestructurar el error directamente del objeto de error de Axios.

---

#### `front/src/modules/techniques/composables/useSubTechniqueRepeater.ts`
**Propósito:** Lógica reactiva para el editor de sub-técnicas (agregar, quitar, reordenar).

```typescript
import { ref } from 'vue'
import type { TechniqueChild } from '../types/technique.types'

export function useSubTechniqueRepeater(initial: TechniqueChild[] = []) {
  const children = ref<TechniqueChild[]>(initial.map((c) => ({ ...c })))

  function addChild() {
    children.value.push({ name: '', protocols_name: null })
  }

  function removeChild(index: number) {
    children.value.splice(index, 1)
  }

  function updateChild(index: number, field: keyof TechniqueChild, value: string | null) {
    children.value[index] = { ...children.value[index], [field]: value }
  }

  function setChildren(newChildren: TechniqueChild[]) {
    children.value = newChildren.map((c) => ({ ...c }))
  }

  function reset() {
    children.value = []
  }

  return { children, addChild, removeChild, updateChild, setChildren, reset }
}
```

---

#### `front/src/modules/techniques/components/TechniqueTypeBadge.vue`
**Propósito:** Badge visual para el tipo de técnica (Técnica / Vacuna).

```vue
<script setup lang="ts">
import type { TechniqueType } from '../types/technique.types'

defineProps<{ type: TechniqueType }>()

const labelMap: Record<TechniqueType, string> = {
  technique: 'Técnica',
  vaccine: 'Vacuna',
}

const colorMap: Record<TechniqueType, string> = {
  technique: 'blue',
  vaccine: 'green',
}
</script>

<template>
  <a-tag :color="colorMap[type]">{{ labelMap[type] }}</a-tag>
</template>
```

---

#### `front/src/modules/techniques/components/TechniqueFilters.vue`
**Propósito:** Barra de filtros (búsqueda por nombre + select de tipo).

```vue
<script setup lang="ts">
import { shallowRef } from 'vue'
import type { TechniqueType, TechniqueListParams } from '../types/technique.types'

const model = defineModel<TechniqueListParams>({ required: true })

const typeOptions = [
  { label: 'Todos', value: undefined },
  { label: 'Técnicas', value: 'technique' as TechniqueType },
  { label: 'Vacunas', value: 'vaccine' as TechniqueType },
]
</script>

<template>
  <div class="tf-filters">
    <BaseSearchInput
      :model-value="model.search ?? ''"
      placeholder="Buscar por nombre..."
      @update:model-value="model = { ...model, search: $event || undefined, page: 1 }"
    />
    <BaseSelect
      :model-value="model.type"
      :options="typeOptions"
      placeholder="Tipo"
      @update:model-value="model = { ...model, type: $event, page: 1 }"
    />
  </div>
</template>

<style scoped>
.tf-filters {
  display: flex;
  gap: 12px;
  margin-bottom: 16px;
  flex-wrap: wrap;
}
</style>
```

---

#### `front/src/modules/techniques/components/SubTechniqueRepeater.vue`
**Propósito:** Tabla editable de sub-técnicas con acciones agregar/eliminar.

Props: `modelValue: TechniqueChild[]` (via `defineModel`).
Emite cambios via `defineModel`.

```vue
<script setup lang="ts">
import { PlusOutlined, DeleteOutlined } from '@ant-design/icons-vue'
import type { TechniqueChild } from '../../types/technique.types'

const children = defineModel<TechniqueChild[]>({ required: true })

function addChild() {
  children.value = [...children.value, { name: '', protocols_name: null }]
}

function removeChild(index: number) {
  children.value = children.value.filter((_, i) => i !== index)
}

function updateChildField(index: number, field: keyof TechniqueChild, value: string | null) {
  const updated = [...children.value]
  updated[index] = { ...updated[index], [field]: value }
  children.value = updated
}
</script>

<template>
  <div class="str-root">
    <div v-if="children.length === 0" class="str-empty">
      Sin sub-técnicas. Podés agregar hasta 50.
    </div>

    <div v-for="(child, idx) in children" :key="child.guid ?? `new-${idx}`" class="str-row">
      <BaseInput
        :model-value="child.name"
        placeholder="Nombre de la sub-técnica"
        @update:model-value="updateChildField(idx, 'name', $event)"
      />
      <BaseInput
        :model-value="child.protocols_name ?? ''"
        placeholder="Label de selector de protocolos (opcional)"
        @update:model-value="updateChildField(idx, 'protocols_name', $event || null)"
      />
      <BaseButton
        variant="row-action"
        size="small"
        danger
        tooltip="Eliminar sub-técnica"
        @click="removeChild(idx)"
      >
        <template #icon><DeleteOutlined /></template>
      </BaseButton>
    </div>

    <BaseButton variant="secondary" size="small" @click="addChild">
      <template #icon><PlusOutlined /></template>
      Agregar sub-técnica
    </BaseButton>
  </div>
</template>
```

---

#### `front/src/modules/techniques/components/TechniqueForm.vue`
**Propósito:** Formulario compartido Create/Edit. Maneja raíz + sub-técnicas.

```vue
<script setup lang="ts">
import { useForm } from 'vee-validate'
import { toTypedSchema } from '@vee-validate/zod'
import { techniqueSchema } from '../../validators/technique.validator'
import SubTechniqueRepeater from '../SubTechniqueRepeater.vue'
import type { TechniqueFormData } from '../../types/technique.types'

const props = withDefaults(defineProps<{
  initialValues?: Partial<TechniqueFormData>
  loading?: boolean
  fieldErrors?: Record<string, string> | null
}>(), {
  loading: false,
  fieldErrors: null,
})

const emit = defineEmits<{
  submit: [values: TechniqueFormData]
  cancel: []
}>()

const { handleSubmit, defineField, errors, setFieldError, values } = useForm({
  validationSchema: toTypedSchema(techniqueSchema),
  initialValues: {
    name: props.initialValues?.name ?? '',
    type: props.initialValues?.type ?? 'technique',
    target_date_name: props.initialValues?.target_date_name ?? '',
    protocols_name: props.initialValues?.protocols_name ?? '',
    children: props.initialValues?.children ?? [],
  },
})

const [name, nameAttrs] = defineField('name')
const [type, typeAttrs] = defineField('type')
const [targetDateName, targetDateNameAttrs] = defineField('target_date_name')
const [protocolsName, protocolsNameAttrs] = defineField('protocols_name')
const [children, childrenAttrs] = defineField('children')

// Propagar errores de campo del server al formulario
watch(() => props.fieldErrors, (errs) => {
  if (!errs) return
  Object.entries(errs).forEach(([field, msg]) => setFieldError(field as any, msg))
}, { immediate: true })

const typeOptions = [
  { label: 'Técnica de reproducción', value: 'technique' },
  { label: 'Vacuna', value: 'vaccine' },
]

const onSubmit = handleSubmit((values) => emit('submit', values as TechniqueFormData))
</script>

<template>
  <form @submit.prevent="onSubmit">
    <div class="tf-field">
      <label>Nombre *</label>
      <BaseInput v-model="name" v-bind="nameAttrs" placeholder="Ej: Inseminación Artificial" />
      <span v-if="errors.name" class="tf-error">{{ errors.name }}</span>
    </div>

    <div class="tf-field">
      <label>Tipo *</label>
      <BaseSelect v-model="type" v-bind="typeAttrs" :options="typeOptions" />
      <span v-if="errors.type" class="tf-error">{{ errors.type }}</span>
    </div>

    <div class="tf-field">
      <label>Label fecha objetivo</label>
      <BaseInput v-model="targetDateName" v-bind="targetDateNameAttrs" placeholder="Ej: Fecha de servicio" />
      <span v-if="errors.target_date_name" class="tf-error">{{ errors.target_date_name }}</span>
    </div>

    <div class="tf-field">
      <label>Label selector de protocolos</label>
      <BaseInput v-model="protocolsName" v-bind="protocolsNameAttrs" placeholder="Ej: Seleccionar protocolo de sincronización" />
      <span v-if="errors.protocols_name" class="tf-error">{{ errors.protocols_name }}</span>
    </div>

    <div class="tf-field">
      <label>Sub-técnicas</label>
      <SubTechniqueRepeater v-model="children" />
    </div>

    <div class="tf-actions">
      <BaseButton variant="secondary" type="button" @click="emit('cancel')">Cancelar</BaseButton>
      <BaseButton type="submit" :loading="loading">Guardar</BaseButton>
    </div>
  </form>
</template>
```

---

#### `front/src/modules/techniques/components/TechniqueDeleteModal.vue`
**Propósito:** Modal de confirmación de delete con manejo del error 422 (técnica bloqueada).

```vue
<script setup lang="ts">
import { computed } from 'vue'
import type { TechniqueListItem, TechniqueDeleteError } from '../../types/technique.types'

const props = defineProps<{
  technique: TechniqueListItem | null
  isPending: boolean
  blockedError: TechniqueDeleteError | null
}>()

const emit = defineEmits<{
  confirm: []
  cancel: []
}>()

const visible = defineModel<boolean>({ required: true })

const blockedMessage = computed(() => {
  if (!props.blockedError) return null
  const { reason, count } = props.blockedError
  if (reason === 'has_programs') return `Esta técnica tiene ${count} programa(s) vinculado(s) y no puede eliminarse.`
  if (reason === 'has_protocols') return `Esta técnica tiene ${count} protocolo(s) vinculado(s) y no puede eliminarse.`
  if (reason === 'children_have_programs') return `Sub-técnicas de esta jerarquía tienen ${count} programa(s) vinculado(s).`
  return 'Esta técnica no puede eliminarse por tener elementos vinculados.'
})
</script>

<template>
  <BaseModal v-model="visible" title="Eliminar técnica">
    <template v-if="blockedError">
      <BaseAlert type="error" :message="blockedMessage" />
      <p>Para eliminar esta técnica, primero eliminá los programas y protocolos vinculados.</p>
    </template>
    <template v-else>
      <p>¿Estás seguro de que querés eliminar <strong>{{ technique?.name }}</strong>?</p>
      <p>Esta acción eliminará también todas sus sub-técnicas. No se puede deshacer.</p>
    </template>

    <template #footer>
      <BaseButton variant="secondary" @click="emit('cancel')">Cancelar</BaseButton>
      <BaseButton
        v-if="!blockedError"
        danger
        :loading="isPending"
        @click="emit('confirm')"
      >
        Eliminar
      </BaseButton>
    </template>
  </BaseModal>
</template>
```

---

#### `front/src/modules/techniques/components/TechniqueProgramsTab.vue`
**Propósito:** Tab "Programas" en el detalle — tabla paginada de programas del árbol (stub hasta que exista el módulo).

```vue
<script setup lang="ts">
import type { ProgramsStub } from '../../types/technique.types'

defineProps<{
  programs: ProgramsStub
}>()
</script>

<template>
  <div>
    <BaseEmptyState
      v-if="programs.total === 0"
      message="No hay programas vinculados a esta técnica todavía."
    />
    <!-- TODO: cuando exista el módulo de programas, reemplazar por BaseDataTable con columnas:
         cliente, establecimiento, protocolo, fecha objetivo, estado -->
    <template v-else>
      <p class="tpt-placeholder">
        Programas: {{ programs.total }} total. Implementar cuando exista el módulo de programas.
      </p>
    </template>
  </div>
</template>
```

---

#### `front/src/modules/techniques/pages/TechniqueListPage.vue`
**Propósito:** Página `/admin/techniques` — tabla paginada con filtros.

```vue
<script setup lang="ts">
import { ref, shallowRef, computed } from 'vue'
import { useRouter } from 'vue-router'
import { PlusOutlined, EditOutlined, DeleteOutlined, EyeOutlined } from '@ant-design/icons-vue'
import TechniqueFilters from '../components/TechniqueFilters.vue'
import TechniqueTypeBadge from '../components/TechniqueTypeBadge.vue'
import TechniqueDeleteModal from '../components/TechniqueDeleteModal.vue'
import { useTechniqueList } from '../composables/useTechniqueList'
import { useDeleteTechnique } from '../composables/useTechniqueMutations'
import type { TechniqueListItem, TechniqueListParams } from '../types/technique.types'

const router = useRouter()

const filters = ref<TechniqueListParams>({ page: 1, per_page: 15 })
const { data, isLoading } = useTechniqueList(filters)

const {
  deleteTechnique,
  isPending: isDeleting,
  deleteBlockedError,
  clearDeleteError,
  mutateAsync: mutateDelete,
} = useDeleteTechnique()

const deleteTarget = ref<TechniqueListItem | null>(null)
const showDeleteModal = shallowRef(false)

function openDeleteModal(technique: TechniqueListItem) {
  deleteTarget.value = technique
  showDeleteModal.value = true
  clearDeleteError()
}

function onDeleteConfirm() {
  if (deleteTarget.value) mutateDelete(deleteTarget.value.guid)
}

function onDeleteCancel() {
  showDeleteModal.value = false
  deleteTarget.value = null
  clearDeleteError()
}

const columns = [
  { title: 'Nombre', key: 'name', dataIndex: 'name' },
  { title: 'Tipo', key: 'type', width: 120 },
  { title: 'Sub-técnicas', key: 'children_count', dataIndex: 'children_count', width: 130 },
  { title: 'Acciones', key: 'actions', width: 140, alwaysVisible: true },
]
</script>

<template>
  <div>
    <AppHeader title="Técnicas de Reproducción" subtitle="Gestioná las técnicas y vacunas del sistema">
      <template #actions="{ buttonSize }">
        <PermissionGuard permission="techniques.create">
          <BaseButton :size="buttonSize" @click="router.push('/admin/techniques/create')">
            <template #icon><PlusOutlined /></template>
            Nueva técnica
          </BaseButton>
        </PermissionGuard>
      </template>
    </AppHeader>

    <TechniqueFilters v-model="filters" />

    <BaseDataTable
      :columns="columns"
      :data-source="data?.data ?? []"
      :loading="isLoading"
      row-key="guid"
      :scroll="{ x: 700 }"
      :pagination="false"
    >
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'type'">
          <TechniqueTypeBadge :type="record.type" />
        </template>
        <template v-else-if="column.key === 'actions'">
          <BaseTableActions>
            <PermissionGuard permission="techniques.read">
              <BaseButton variant="row-action" size="small" tooltip="Ver detalle"
                @click="router.push(`/admin/techniques/${record.guid}`)">
                <template #icon><EyeOutlined /></template>
              </BaseButton>
            </PermissionGuard>
            <PermissionGuard permission="techniques.update">
              <BaseButton variant="row-action" size="small" tooltip="Editar"
                @click="router.push(`/admin/techniques/${record.guid}/edit`)">
                <template #icon><EditOutlined /></template>
              </BaseButton>
            </PermissionGuard>
            <PermissionGuard permission="techniques.delete">
              <BaseButton variant="row-action" size="small" danger tooltip="Eliminar"
                @click="openDeleteModal(record)">
                <template #icon><DeleteOutlined /></template>
              </BaseButton>
            </PermissionGuard>
          </BaseTableActions>
        </template>
      </template>
    </BaseDataTable>

    <BasePagination
      v-if="data"
      :current="data.current_page"
      :total="data.total"
      :page-size="data.per_page"
      @change="(page) => filters = { ...filters, page }"
    />

    <TechniqueDeleteModal
      v-model="showDeleteModal"
      :technique="deleteTarget"
      :is-pending="isDeleting"
      :blocked-error="deleteBlockedError"
      @confirm="onDeleteConfirm"
      @cancel="onDeleteCancel"
    />
  </div>
</template>
```

---

#### `front/src/modules/techniques/pages/TechniqueCreatePage.vue`
**Propósito:** Página `/admin/techniques/create`.

```vue
<script setup lang="ts">
import { useRouter } from 'vue-router'
import TechniqueForm from '../components/TechniqueForm.vue'
import { useCreateTechnique } from '../composables/useTechniqueMutations'
import type { TechniqueFormData } from '../types/technique.types'

const router = useRouter()
const { mutateAsync, isPending, fieldErrors, generalError, resetErrors } = useCreateTechnique()

async function handleSubmit(values: TechniqueFormData) {
  await mutateAsync({
    name: values.name,
    type: values.type,
    target_date_name: values.target_date_name || null,
    protocols_name: values.protocols_name || null,
    children: values.children.map((c) => ({
      name: c.name,
      protocols_name: c.protocols_name ?? null,
    })),
  })
  router.push('/admin/techniques')
}
</script>

<template>
  <div>
    <AppHeader title="Nueva Técnica" subtitle="Creá una técnica o vacuna con sus sub-técnicas" />
    <BaseAlert v-if="generalError" type="error" :message="generalError" />
    <TechniqueForm
      :loading="isPending"
      :field-errors="fieldErrors"
      @submit="handleSubmit"
      @cancel="router.push('/admin/techniques')"
    />
  </div>
</template>
```

---

#### `front/src/modules/techniques/pages/TechniqueEditPage.vue`
**Propósito:** Página `/admin/techniques/:guid/edit`.

```vue
<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import TechniqueForm from '../components/TechniqueForm.vue'
import { useTechniqueDetail } from '../composables/useTechniqueDetail'
import { useUpdateTechnique } from '../composables/useTechniqueMutations'
import type { TechniqueFormData } from '../types/technique.types'

const props = defineProps<{ guid: string }>()
const router = useRouter()

const { data, isLoading } = useTechniqueDetail(computed(() => props.guid))
const { mutateAsync, isPending, fieldErrors, generalError, childConflicts } = useUpdateTechnique()

const initialValues = computed(() => {
  const t = data.value?.technique
  if (!t) return undefined
  return {
    name: t.name,
    type: t.type,
    target_date_name: t.target_date_name ?? '',
    protocols_name: t.protocols_name ?? '',
    children: t.children,
  }
})

async function handleSubmit(values: TechniqueFormData) {
  await mutateAsync({
    guid: props.guid,
    payload: {
      name: values.name,
      type: values.type,
      target_date_name: values.target_date_name || null,
      protocols_name: values.protocols_name || null,
      children: values.children.map((c) => ({
        guid: c.guid,
        name: c.name,
        protocols_name: c.protocols_name ?? null,
      })),
    },
  })
  router.push(`/admin/techniques/${props.guid}`)
}
</script>

<template>
  <div>
    <AppHeader title="Editar Técnica" />

    <BaseSkeleton v-if="isLoading" />

    <template v-else-if="data">
      <BaseAlert v-if="generalError" type="error" :message="generalError" />
      <BaseAlert
        v-if="childConflicts.length > 0"
        type="warning"
        message="Las siguientes sub-técnicas tienen programas vinculados y no pueden eliminarse:"
      >
        <ul>
          <li v-for="c in childConflicts" :key="c.guid">
            {{ c.name }} ({{ c.programs_count }} programa(s))
          </li>
        </ul>
      </BaseAlert>
      <TechniqueForm
        :initial-values="initialValues"
        :loading="isPending"
        :field-errors="fieldErrors"
        @submit="handleSubmit"
        @cancel="router.push(`/admin/techniques/${guid}`)"
      />
    </template>
  </div>
</template>
```

---

#### `front/src/modules/techniques/pages/TechniqueDetailPage.vue`
**Propósito:** Página `/admin/techniques/:guid` — 2 tabs: Sub-técnicas y Programas.

```vue
<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { EditOutlined, ArrowLeftOutlined } from '@ant-design/icons-vue'
import TechniqueTypeBadge from '../components/TechniqueTypeBadge.vue'
import TechniqueProgramsTab from '../components/TechniqueProgramsTab.vue'
import { useTechniqueDetail } from '../composables/useTechniqueDetail'

const props = defineProps<{ guid: string }>()
const router = useRouter()

const { data, isLoading } = useTechniqueDetail(computed(() => props.guid))

const technique = computed(() => data.value?.technique)
const programs = computed(() => data.value?.programs)

const childColumns = [
  { title: 'Nombre', key: 'name', dataIndex: 'name' },
  { title: 'Label Protocolos', key: 'protocols_name', dataIndex: 'protocols_name' },
]
</script>

<template>
  <div>
    <BaseButton variant="tertiary" @click="router.push('/admin/techniques')">
      <template #icon><ArrowLeftOutlined /></template>
      Volver a técnicas
    </BaseButton>

    <BaseSkeleton v-if="isLoading" />

    <template v-else-if="technique">
      <AppHeader :title="technique.name">
        <template #subtitle><TechniqueTypeBadge :type="technique.type" /></template>
        <template #actions="{ buttonSize }">
          <PermissionGuard permission="techniques.update">
            <BaseButton :size="buttonSize"
              @click="router.push(`/admin/techniques/${technique.guid}/edit`)">
              <template #icon><EditOutlined /></template>
              Editar
            </BaseButton>
          </PermissionGuard>
        </template>
      </AppHeader>

      <a-tabs>
        <a-tab-pane key="children" tab="Sub-técnicas">
          <BaseEmptyState
            v-if="technique.children.length === 0"
            message="Esta técnica no tiene sub-técnicas."
          />
          <BaseDataTable
            v-else
            :columns="childColumns"
            :data-source="technique.children"
            row-key="guid"
            :pagination="false"
          />
        </a-tab-pane>

        <a-tab-pane key="programs" tab="Programas">
          <TechniqueProgramsTab v-if="programs" :programs="programs" />
        </a-tab-pane>
      </a-tabs>
    </template>

    <div v-else>Técnica no encontrada.</div>
  </div>
</template>
```

---

#### `front/src/modules/techniques/router/technique.routes.ts`
**Propósito:** Definición de rutas del módulo.

```typescript
import type { RouteRecordRaw } from 'vue-router'

export const techniquesRoutes: RouteRecordRaw[] = [
  {
    // /create DEBE ir ANTES que /:guid para evitar que Vue Router interprete "create" como guid
    path: '/admin/techniques/create',
    name: 'admin-techniques-create',
    component: () => import('@/modules/techniques/pages/TechniqueCreatePage.vue'),
    meta: { requiresAuth: true, title: 'Nueva técnica' },
  },
  {
    path: '/admin/techniques/:guid/edit',
    name: 'admin-techniques-edit',
    component: () => import('@/modules/techniques/pages/TechniqueEditPage.vue'),
    props: true,
    meta: { requiresAuth: true, title: 'Editar técnica' },
  },
  {
    path: '/admin/techniques/:guid',
    name: 'admin-techniques-detail',
    component: () => import('@/modules/techniques/pages/TechniqueDetailPage.vue'),
    props: true,
    meta: { requiresAuth: true, title: 'Detalle de técnica' },
  },
  {
    path: '/admin/techniques',
    name: 'admin-techniques-list',
    component: () => import('@/modules/techniques/pages/TechniqueListPage.vue'),
    meta: { requiresAuth: true, title: 'Técnicas de Reproducción' },
  },
]
```

**Nota orden de rutas:** `/create` primero, luego `/:guid/edit`, luego `/:guid`, luego `/` (lista).

---

### Archivos a modificar en frontend

#### `front/src/router/index.ts`
**Cambio:** Importar y registrar las rutas del módulo techniques.
**Agregar import:**
```typescript
import { techniquesRoutes } from '@/modules/techniques/router/technique.routes'
```
**Agregar en el array `children` del layout AppLayout (junto a `adminClientsRoutes`):**
```typescript
...techniquesRoutes,
```

---

## Orden de implementación

1. Crear migration `2026_06_23_000001_create_techniques_table.php` y correrla con `php artisan migrate`.
2. Agregar permisos `techniques.*` al array en `PermissionSeeder.php`.
3. Crear `TechniquePermissionsSeeder.php` y registrarlo en `DatabaseSeeder.php`.
4. Correr `php artisan db:seed --class=TechniquePermissionsSeeder` (o `db:seed --class=PermissionSeeder` + `RoleSeeder` si se prefiere refrescar todo).
5. Crear modelo `back/app/Models/Technique.php`.
6. Crear interface `back/app/Contracts/Repositories/TechniqueRepositoryInterface.php`.
7. Crear repositorio `back/app/Repositories/TechniqueRepositoryEloquent.php`.
8. Agregar binding en `AppServiceProvider::register()`.
9. Crear excepciones `TechniqueCannotBeDeletedException.php` y `TechniqueChildHasProgramsException.php`.
10. Agregar handling de excepciones en `ResponseHelper::makeFromException()`.
11. Crear Form Requests: `Techniques/CreateTechniqueRequest.php`, `UpdateTechniqueRequest.php`, `IndexTechniqueRequest.php`.
12. Crear resources: `TechniqueListResource.php`, `TechniqueChildResource.php`, `TechniqueResource.php`.
13. Crear `TechniqueService.php`.
14. Crear `AdminTechniqueController.php` y `TechniqueController.php`.
15. Crear archivo de rutas `back/routes/api/techniques.php` (se auto-incluye via el glob en `api.php`).
16. Testear endpoints con Postman/Thunder Client: lista admin, create, show, update, delete, API vet.
17. (Frontend) Crear directorio `front/src/modules/techniques/` con subdirectorios.
18. Crear `types/technique.types.ts`.
19. Crear `api/technique.api.ts`.
20. Crear `validators/technique.validator.ts`.
21. Crear composables: `useTechniqueList.ts`, `useTechniqueDetail.ts`, `useTechniqueMutations.ts`, `useSubTechniqueRepeater.ts`.
22. Crear componentes: `TechniqueTypeBadge.vue`, `TechniqueFilters.vue`, `SubTechniqueRepeater.vue`, `TechniqueForm.vue`, `TechniqueDeleteModal.vue`, `TechniqueProgramsTab.vue`.
23. Crear páginas: `TechniqueListPage.vue`, `TechniqueCreatePage.vue`, `TechniqueEditPage.vue`, `TechniqueDetailPage.vue`.
24. Crear `router/technique.routes.ts`.
25. Modificar `front/src/router/index.ts` para registrar las rutas.
26. Verificar navegación completa en el browser.
27. Correr tests backend.

---

## Riesgos y consideraciones

**R-01 — Programas y protocolos no existen (riesgo crítico para el negocio, no para la implementación actual)**
Los métodos `countProgramsForTechnique` y `countProtocolsForTechnique` en `TechniqueService` son stubs que retornan `0`. Esto significa que hoy CUALQUIER técnica puede eliminarse sin restricción. Cuando se implementen los módulos de Programas y Protocolos, el dev DEBE reemplazar esos stubs. Se recomienda agregar un comentario `// TODO:` visible con la instrucción exacta (ya incluido en el plan).

**R-02 — `parseApiError` debe exponer `rawErrors`**
Los composables de update y delete acceden a `apiError.rawErrors` para distinguir entre errores de campo y errores de negocio (422 con `conflicts` o `reason`). Si el composable `parseApiError` existente no expone ese campo, el dev debe: (a) leer el archivo real de `parseApiError` y ajustar el acceso, o (b) agregar el campo `rawErrors` al tipo retornado. Verificar `front/src/core/composables/parseApiError.ts` antes de implementar los composables de mutación.

**R-03 — Regla de negocio: hijos no pueden tener hijos (solo 2 niveles)**
El plan NO agrega una constraint de DB para esto — solo validación en el servicio. Si alguien llama a la API con un `parent_id` de un hijo (no de una raíz), el servicio no lo bloquea explícitamente. El `CreateTechniqueRequest` no acepta `parent_id` (solo crea raíces), pero si alguien hace un UPDATE y manipula `parent_id` manualmente... no hay protección. Recomendación: agregar en `TechniqueService::update()` una validación que asegure que `parent_id` no se puede cambiar, y que no se puede setear a un hijo. Fuera del scope de este plan pero fácil de agregar.

**R-04 — `type` de los hijos siempre hereda del padre**
El campo `type` está en la tabla para todos los registros (raíces e hijos), pero semánticamente el tipo lo define la raíz. Los hijos no tienen `type` en el formulario — se hereda en `createChild()`. Si alguien actualiza el `type` de una raíz vía UPDATE, los hijos existentes mantienen el `type` antiguo (no se actualiza en cascada). Fuera del scope del plan pero considerar si es relevante.

**R-05 — Discrepancia naming permisos**
La memoria `backend_conventions.md` dice que el patrón es `module.lectura/alta/modificacion/baja`. El código real (PermissionSeeder.php) usa `module.read/create/update/delete` en inglés. El plan sigue el código real (DEC-01). Si en algún momento se decide migrar a español, hay que actualizar todos los middlewares `can:X`.

**R-06 — Multi-tenant no aplica a este módulo**
Las técnicas son datos globales de la plataforma (no son por tenant/vet). No hay `vet_id` en la tabla, ni scope de tenant. Esto es correcto — es equivalente al módulo de `tutorials`. No se requiere middleware `vet.tenant` ni scope adicional.

**R-07 — `BaseTableActions` y `BasePagination` — verificar que existen**
El plan usa `BaseTableActions` y `BasePagination`. `BasePagination` existe en `atoms/navigation/`. `BaseTableActions` se vio en uso en `TutorialsTable.vue` pero su ubicación exacta no se verificó. El dev debe confirmar el path correcto para importarlo.

---

## Pendientes / fuera de alcance

- **Programas vinculados (tab "Programas" en detalle):** `TechniqueProgramsTab.vue` es un stub. La tabla real con columnas cliente/establecimiento/protocolo/fecha/estado se implementa cuando exista el módulo de Programas.
- **Endpoint `/v1/techniques/protocols`:** Retorna `[]` hasta que exista el módulo de Protocolos.
- **Validación "hijo no puede tener hijos":** Se delega la verificación server-side a cuando se construya el CRUD de protocolos (que son los que referencian técnicas/sub-técnicas). Por ahora no hay endpoint para crear hijos de hijos.
- **Paginación de programas en el detalle admin:** El endpoint `GET /admin/techniques/{guid}` retorna `programs` con paginación stub. Cuando existan programas, se deberá agregar soporte para `?programs_page=N` en el query string.
- **Navegación en sidebar:** Agregar entrada de menú "Técnicas" en el layout admin (`AppLayout` o el componente de sidebar) queda a cargo del dev — no está en el scope de este plan porque requiere conocer la estructura interna del sidebar (no explorada).
- **Export de técnicas:** No está en el spec. Fuera de alcance.
