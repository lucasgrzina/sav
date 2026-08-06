# Plan técnico: ABM de Protocolos (SuperAdmin) — capa plantilla

## Input procesado

`.claude/docs/specs/protocolos-superadmin-abm-spec.md` (spec funcional, DU-01 a DU-03 y RD-01 ya resueltos con el usuario). Referencia estructural: `docs/planes/diseno-protocolos-programas-alertas-v2.md` (solo la parte `protocols`/`protocol_tasks`/`protocol_task_alerts`).

## Resumen ejecutivo

Se crean desde cero `Protocol`, `ProtocolTask` y `ProtocolTaskAlert` (hoy no existen en el backend nuevo), siguiendo el patrón repository+service+FormRequest+Resource ya usado por `techniques`. El alta/edición es una operación anidada de un solo POST/PUT (protocolo + tareas + alertas) en transacción atómica, con diff por `guid` igual al patrón `prepareSync` de `TechniqueRepositoryEloquent`. La validación de "protocolo con `programs` asociados" se implementa como stub (`countProgramsForProtocol()` → `0`, `TODO`), mismo patrón que `TechniqueService::countProgramsForTechnique()`. En frontend se reemplaza el placeholder `TechniqueProtocolsTab.vue` por el ABM real, reutilizando `useCountries` (módulo `vets`) y `getRoleLabel` (`core/utils/roles.ts`).

## Decisiones tomadas

**DEC-01 — Índice único de `protocols` (DU-02) resuelto: constraint a nivel aplicación, NO índice DB puro.**
Decisión: DB confirmada MySQL (`back/.env` → `DB_CONNECTION=mysql`). En MySQL un índice único compuesto NO deduplica filas donde una columna nullable (`country_id`) es `NULL` — cada `NULL` se trata como valor distinto, por lo que dos protocolos con mismo `technique_id`+`name` y `country_id` NULL pasarían el índice DB sin violarlo. Se agrega igual el índice único compuesto `(technique_id, country_id, name)` en la migración (defensa para el caso `country_id` no nulo, y evita objetos claramente duplicados a nivel DB), pero la regla de negocio real (incluyendo el caso `country_id IS NULL`) se valida en el FormRequest vía `ProtocolRepositoryInterface::existsDuplicate()`, que arma la query con `where(function($q) use ($countryId) { $countryId === null ? $q->whereNull('country_id') : $q->where('country_id', $countryId); })`.
Alternativa descartada: índice único con función `COALESCE(country_id, 0)` (columna generada) — más robusto a nivel DB pero requiere migración adicional (columna generada + índice sobre ella) y complica el modelo Eloquent sin necesidad real dado que ya hay validación de aplicación obligatoria por otros motivos (mensajes de error localizados, exclusión del propio registro en update).

**DEC-02 — RD-04 resuelto: `protocol_tasks` y `protocol_task_alerts` SÍ tienen `deleted_at` propio (soft delete).**
Decisión: las tres tablas (`protocols`, `protocol_tasks`, `protocol_task_alerts`) usan `SoftDeletes`. Ya hay precedente real en el codebase (`back/app/Models/Notification.php` usa `SoftDeletes` + `$table->softDeletes()` pese a que `backend-conventions.md` dice "sin softDeletes, el proyecto no los usa" — la convención del skill es una guía general, no absoluta; el código real la contradice al menos una vez, y la spec exige `deleted_at` explícitamente en `protocols` y lo deja abierto en `protocol_tasks`/`protocol_task_alerts` justamente para no romper `program_tasks.protocol_task_id` en el futuro).
Importante: el FK `cascadeOnDelete()` de Laravel actúa a nivel DB sobre `DELETE` físico, NO sobre soft delete (que es un `UPDATE deleted_at`). Por lo tanto el cascade de borrado debe hacerse a mano en `ProtocolService::destroy()` y en el diff de `syncTasks()`/`syncAlerts()` (soft-delete explícito de tareas/alertas cuando se elimina el protocolo o se remueven del formulario), dentro de la misma transacción.
Alternativa descartada: hard delete con cascade DB puro — se descarta porque rompe la trazabilidad de `program_tasks.protocol_task_id` (riesgo ya señalado en RD-04 de la spec) apenas exista el módulo `programs`.

**DEC-03 — RD-03 resuelto: validación de `programs` asociados vía query explícita (stub), no FK constraint.**
Decisión: mismo patrón que `TechniqueService::countProgramsForTechnique()` — un método privado `ProtocolService::countProgramsForProtocol(Protocol $protocol): int` que hoy siempre retorna `0` con comentario `// TODO: implementar cuando exista el modelo Program`. Se usa en `destroy()` (bloqueo de borrado, RF-04) y en `update()` (bloqueo de cambio de `technique_id`, DU-01). No se usa FK constraint porque `programs` no existe todavía — un FK real rompería la migración.
Alternativa descartada: agregar ya la tabla `programs` mínima solo para el FK — fuera de alcance explícito de la spec, y generaría deuda de diseño a resolver por el dev que implemente `programs` después.

**DEC-04 — Rutas: `protocols` como recurso propio, no anidado bajo `techniques`.**
Decisión: `back/routes/api/protocols.php`, prefijo `v1/admin/protocols`. El listado (RF-01, agrupado por técnica raíz) se resuelve con `GET /v1/admin/protocols?root_guid={guid técnica raíz}` en vez de anidar la ruta bajo `/admin/techniques/{guid}/protocols`, porque el CRUD de protocolo (crear/editar/eliminar) opera siempre sobre el guid del protocolo directamente, no en contexto de una raíz — anidar solo el índice y dejar el resto plano genera dos convenciones de URL distintas para el mismo recurso.
Alternativa descartada: anidar todo bajo `/admin/techniques/{rootGuid}/protocols/{guid}` — más "RESTful" en apariencia, pero el `technique_id` real del protocolo es la sub-técnica (child), no la raíz, así que la URL anidada bajo la raíz sería semánticamente engañosa.

**DEC-05 — Reordenamiento de tareas/alertas: botones subir/bajar, sin librería de drag & drop.**
Decisión: no hay `vuedraggable` ni librería de sortable instalada en `front/package.json`. La spec acepta "drag-and-drop o flechas" (RF-03). Se implementa con flechas (↑/↓) sobre el array en memoria, igual de simple que agregar una dependencia nueva solo para esto.
Alternativa descartada: instalar `vuedraggable` — se descarta por costo/beneficio dado que el criterio de aceptación no exige drag específicamente.

**DEC-06 — Reutilización de `useCountries` (módulo `vets`) y `getRoleLabel` (`core/utils/roles.ts`).**
Decisión: ya existen y el codebase tiene precedente de imports cross-módulo (23 archivos importan de `@/modules/vets/...` desde otros módulos). No se duplica un `useCountries` propio del módulo `protocols`.
Alternativa descartada: mover `useCountries` a `core/composables/` antes de reutilizarlo — refactor válido pero fuera de alcance de esta spec, no lo pide ningún RF.

**DEC-07 — `created_by_id` no se expone en el Resource público.**
Decisión: `created_by_type` sí se expone (dato de negocio, no sensible). `created_by_id` es el id interno numérico del usuario SuperAdmin — exponerlo violaría la regla dura #6 (GUID como identificador, nunca id interno) si se usa tal cual. Como no hay RF que pida mostrar "creado por" en la UI, se persiste en DB (auditoría, DU-03) pero no se serializa en `ProtocolResource`. Si a futuro se necesita mostrar el autor, se debe resolver `created_by_id` contra el modelo correspondiente y exponer su `guid`, no el id crudo.
Alternativa descartada: exponer `created_by_id` igual, total "es panel admin" — se descarta porque viola la regla dura #6 sin necesidad funcional.

## Cambios en BACKEND

### Migrations

#### `back/database/migrations/{timestamp}_create_protocols_table.php`
```php
Schema::create('protocols', function (Blueprint $table) {
    $table->id();
    $table->char('guid', 36)->unique()->comment('UUID generado por HasGuid trait');
    $table->unsignedBigInteger('technique_id')->comment('Siempre una sub-técnica (parent_id NOT NULL), nunca la raíz');
    $table->unsignedBigInteger('country_id')->nullable()->comment('null = protocolo global, visible en todos los países');
    $table->unsignedBigInteger('vet_id')->nullable()->comment('null en esta iteración (solo SuperAdmin); reservado para protocolos propios de un vet');
    $table->string('created_by_type', 20)->default('superadmin')->comment("'superadmin' | 'vet'");
    $table->unsignedBigInteger('created_by_id')->comment('id interno del usuario autor; no se expone en el Resource, solo auditoría');
    $table->string('name', 255);
    $table->string('color', 20)->nullable();
    $table->softDeletes();
    $table->timestamps();

    $table->foreign('technique_id')->references('id')->on('techniques')->restrictOnDelete();
    $table->foreign('country_id')->references('id')->on('countries')->nullOnDelete();
    $table->foreign('vet_id')->references('id')->on('vets')->nullOnDelete();

    $table->unique(['technique_id', 'country_id', 'name'], 'protocols_technique_country_name_unique');
    $table->index('created_by_type');
});
```
Nota `restrictOnDelete()` en `technique_id`: `TechniqueRepositoryEloquent::deleteChild()` (llamado desde `TechniqueService::update()` al sincronizar hijos) hoy solo valida `countProgramsForTechnique()`, NO valida protocolos existentes antes de borrar un child — ver Riesgos.

#### `back/database/migrations/{timestamp}_create_protocol_tasks_table.php`
```php
Schema::create('protocol_tasks', function (Blueprint $table) {
    $table->id();
    $table->char('guid', 36)->unique();
    $table->unsignedBigInteger('protocol_id');
    $table->text('description');
    $table->integer('days_offset')->comment('Con signo: negativo = antes del D0, positivo = después');
    $table->string('time_of_day', 10)->comment("'before' | 'after'");
    $table->time('time');
    $table->boolean('important')->default(false);
    $table->unsignedInteger('sort_order')->default(0);
    $table->softDeletes();
    $table->timestamps();

    $table->foreign('protocol_id')->references('id')->on('protocols')->cascadeOnDelete();
    $table->index(['protocol_id', 'sort_order']);
});
```

#### `back/database/migrations/{timestamp}_create_protocol_task_alerts_table.php`
```php
Schema::create('protocol_task_alerts', function (Blueprint $table) {
    $table->id();
    $table->char('guid', 36)->unique();
    $table->unsignedBigInteger('protocol_task_id');
    $table->integer('offset_days')->default(0)->comment('Relativo a la fecha de la tarea; con signo');
    $table->string('time_of_day', 10)->default('before');
    $table->time('time');
    $table->json('roles')->comment('Array de nombres de roles tenant, mínimo 1');
    $table->text('message');
    $table->boolean('require_confirmation')->default(false);
    $table->unsignedInteger('sort_order')->default(0);
    $table->softDeletes();
    $table->timestamps();

    $table->foreign('protocol_task_id')->references('id')->on('protocol_tasks')->cascadeOnDelete();
    $table->index(['protocol_task_id', 'sort_order']);
});
```

### Archivos a crear

#### `back/app/Models/Protocol.php`
```php
class Protocol extends Model
{
    use HasGuid, SoftDeletes;

    protected $fillable = [
        'technique_id', 'country_id', 'vet_id',
        'created_by_type', 'created_by_id', 'name', 'color',
    ];

    protected $hidden = ['id'];

    public function technique(): BelongsTo { return $this->belongsTo(Technique::class); }
    public function country(): BelongsTo { return $this->belongsTo(Country::class); }
    public function tasks(): HasMany { return $this->hasMany(ProtocolTask::class)->orderBy('sort_order'); }
}
```

#### `back/app/Models/ProtocolTask.php`
```php
class ProtocolTask extends Model
{
    use HasGuid, SoftDeletes;

    protected $fillable = ['protocol_id', 'description', 'days_offset', 'time_of_day', 'time', 'important', 'sort_order'];
    protected $hidden = ['id'];
    protected function casts(): array { return ['important' => 'boolean']; }

    public function protocol(): BelongsTo { return $this->belongsTo(Protocol::class); }
    public function alerts(): HasMany { return $this->hasMany(ProtocolTaskAlert::class)->orderBy('sort_order'); }
}
```

#### `back/app/Models/ProtocolTaskAlert.php`
```php
class ProtocolTaskAlert extends Model
{
    use HasGuid, SoftDeletes;

    protected $fillable = ['protocol_task_id', 'offset_days', 'time_of_day', 'time', 'roles', 'message', 'require_confirmation', 'sort_order'];
    protected $hidden = ['id'];
    protected function casts(): array { return ['roles' => 'array', 'require_confirmation' => 'boolean']; }

    public function protocolTask(): BelongsTo { return $this->belongsTo(ProtocolTask::class); }
}
```

#### `back/app/Contracts/Repositories/ProtocolRepositoryInterface.php`
```php
interface ProtocolRepositoryInterface
{
    public function paginateByRootTechnique(Technique $root, array $filters, int $perPage): LengthAwarePaginator;
    public function findByGuid(string $guid): ?Protocol;
    public function findByGuidWithTasks(string $guid): ?Protocol; // eager: tasks.alerts
    public function create(array $data): Protocol;
    public function update(Model $model, array $data): Model;
    public function destroy(Model $model): bool|null; // soft delete
    public function existsDuplicate(int $techniqueId, ?int $countryId, string $name, ?string $excludeGuid = null): bool;
}
```

#### `back/app/Repositories/ProtocolRepositoryEloquent.php`
```php
class ProtocolRepositoryEloquent extends BaseRepositoryEloquent implements ProtocolRepositoryInterface
{
    protected function model(): string { return Protocol::class; }

    public function paginateByRootTechnique(Technique $root, array $filters, int $perPage): LengthAwarePaginator
    {
        $childIds = $root->children()->pluck('id');

        $query = $this->newQuery()
            ->whereIn('technique_id', $childIds)
            ->with('technique:id,guid,name', 'country:id,guid,name')
            ->withCount('tasks');

        if (!empty($filters['technique_guid'])) {
            $query->whereHas('technique', fn ($q) => $q->where('guid', $filters['technique_guid']));
        }
        if (array_key_exists('country_guid', $filters)) {
            $filters['country_guid'] === null
                ? $query->whereNull('country_id')
                : $query->whereHas('country', fn ($q) => $q->where('guid', $filters['country_guid']));
        }
        if (!empty($filters['search'])) {
            $query->where('name', 'like', '%' . $filters['search'] . '%');
        }

        return $query->orderBy('name')->paginate($perPage);
    }

    public function findByGuid(string $guid): ?Protocol { /** @var Protocol|null */ return $this->newQuery()->where('guid', $guid)->first(); }

    public function findByGuidWithTasks(string $guid): ?Protocol
    {
        /** @var Protocol|null */
        return $this->newQuery()->with(['technique', 'country', 'tasks.alerts'])->where('guid', $guid)->first();
    }

    public function create(array $data): Protocol { /** @var Protocol */ return parent::create($data); }

    public function existsDuplicate(int $techniqueId, ?int $countryId, string $name, ?string $excludeGuid = null): bool
    {
        $query = $this->newQuery()
            ->where('technique_id', $techniqueId)
            ->where('name', $name);

        $countryId === null ? $query->whereNull('country_id') : $query->where('country_id', $countryId);

        if ($excludeGuid) {
            $query->where('guid', '!=', $excludeGuid);
        }

        return $query->exists();
    }
}
```

#### `back/app/Services/ProtocolService.php`
```php
class ProtocolService
{
    public function __construct(
        private ProtocolRepositoryInterface $protocolRepository,
        private TechniqueRepositoryInterface $techniqueRepository,
    ) {}

    public function paginateByRootTechnique(string $rootGuid, array $filters, int $perPage): LengthAwarePaginator
    {
        $root = $this->techniqueRepository->findRootByGuid($rootGuid);
        if (!$root) {
            throw new ModelNotFoundException('Técnica raíz no encontrada.');
        }
        return $this->protocolRepository->paginateByRootTechnique($root, $filters, $perPage);
    }

    public function findByGuidWithTasks(string $guid): ?Protocol
    {
        return $this->protocolRepository->findByGuidWithTasks($guid);
    }

    /** @param array $data {technique_id (int, ya resuelto), country_id?, name, color?, tasks: [...]} */
    public function create(array $data, int $createdById): Protocol
    {
        return DB::transaction(function () use ($data, $createdById) {
            $tasks = $data['tasks'] ?? [];
            unset($data['tasks']);

            $data['created_by_type'] = 'superadmin';
            $data['created_by_id']   = $createdById;
            $data['vet_id']          = null;

            $protocol = $this->protocolRepository->create($data);
            $this->syncTasks($protocol, $tasks);

            return $protocol->fresh()->load('tasks.alerts', 'technique', 'country');
        });
    }

    /**
     * @throws ProtocolTechniqueLockedException si intenta cambiar technique_id con programs asociados
     */
    public function update(Protocol $protocol, array $data): Protocol
    {
        return DB::transaction(function () use ($protocol, $data) {
            $tasks = $data['tasks'] ?? [];
            unset($data['tasks']);

            if (isset($data['technique_id']) && $data['technique_id'] !== $protocol->technique_id) {
                $count = $this->countProgramsForProtocol($protocol);
                if ($count > 0) {
                    throw new ProtocolTechniqueLockedException($count);
                }
            }

            $this->protocolRepository->update($protocol, $data);
            $this->syncTasks($protocol, $tasks);

            return $protocol->fresh()->load('tasks.alerts', 'technique', 'country');
        });
    }

    /** @throws ProtocolHasProgramsException */
    public function destroy(Protocol $protocol): void
    {
        $count = $this->countProgramsForProtocol($protocol);
        if ($count > 0) {
            throw new ProtocolHasProgramsException($count);
        }

        DB::transaction(function () use ($protocol) {
            $protocol->load('tasks.alerts');
            foreach ($protocol->tasks as $task) {
                $task->alerts()->update(['deleted_at' => now()]);
                $task->delete();
            }
            $this->protocolRepository->destroy($protocol);
        });
    }

    /**
     * Valida duplicado name+technique_id+country_id (DU-02, ver DEC-01).
     */
    public function isDuplicateName(int $techniqueId, ?int $countryId, string $name, ?string $excludeGuid = null): bool
    {
        return $this->protocolRepository->existsDuplicate($techniqueId, $countryId, $name, $excludeGuid);
    }

    /**
     * Diff por guid, mismo patrón que TechniqueRepositoryEloquent::prepareSync.
     * Tareas sin guid = nuevas. Tareas con guid ausente en $tasksData = soft-delete (+ sus alertas).
     */
    private function syncTasks(Protocol $protocol, array $tasksData): void
    {
        $existing      = $protocol->tasks()->withTrashed(false)->get()->keyBy('guid');
        $incomingGuids = collect($tasksData)->pluck('guid')->filter()->values();

        // Eliminar tareas removidas (+ alertas hijas)
        $existing->whereNotIn('guid', $incomingGuids->all())->each(function (ProtocolTask $task) {
            $task->alerts()->update(['deleted_at' => now()]);
            $task->delete();
        });

        foreach ($tasksData as $index => $taskData) {
            $fields = [
                'description' => $taskData['description'],
                'days_offset' => $taskData['days_offset'],
                'time_of_day' => $taskData['time_of_day'],
                'time'        => $taskData['time'],
                'important'   => $taskData['important'] ?? false,
                'sort_order'  => $index,
            ];

            if (!empty($taskData['guid']) && $existing->has($taskData['guid'])) {
                $task = $existing->get($taskData['guid']);
                $task->fill($fields);
                $task->save();
            } else {
                $task = $protocol->tasks()->create($fields);
            }

            $this->syncAlerts($task, $taskData['alerts'] ?? []);
        }
    }

    private function syncAlerts(ProtocolTask $task, array $alertsData): void
    {
        $existing      = $task->alerts()->get()->keyBy('guid');
        $incomingGuids = collect($alertsData)->pluck('guid')->filter()->values();

        $existing->whereNotIn('guid', $incomingGuids->all())->each(fn (ProtocolTaskAlert $a) => $a->delete());

        foreach ($alertsData as $index => $alertData) {
            $fields = [
                'offset_days'          => $alertData['offset_days'] ?? 0,
                'time_of_day'          => $alertData['time_of_day'] ?? 'before',
                'time'                 => $alertData['time'],
                'roles'                => $alertData['roles'],
                'message'              => $alertData['message'],
                'require_confirmation' => $alertData['require_confirmation'] ?? false,
                'sort_order'           => $index,
            ];

            if (!empty($alertData['guid']) && $existing->has($alertData['guid'])) {
                $existing->get($alertData['guid'])->fill($fields)->save();
            } else {
                $task->alerts()->create($fields);
            }
        }
    }

    /**
     * STUB (DEC-03 / RD-03): retorna cantidad de programas creados a partir de este protocolo.
     * Reemplazar cuando exista el modelo Program:
     *   return Program::where('protocol_id', $protocol->id)->count();
     */
    private function countProgramsForProtocol(Protocol $protocol): int
    {
        // TODO: implementar cuando exista el modelo Program
        return 0;
    }
}
```

#### `back/app/Exceptions/ProtocolHasProgramsException.php`
```php
class ProtocolHasProgramsException extends \RuntimeException
{
    public function __construct(private readonly int $count)
    {
        parent::__construct('El protocolo tiene programas vinculados y no puede eliminarse.');
    }
    public function getCount(): int { return $this->count; }
}
```

#### `back/app/Exceptions/ProtocolTechniqueLockedException.php`
```php
class ProtocolTechniqueLockedException extends \RuntimeException
{
    public function __construct(private readonly int $count)
    {
        parent::__construct('La sub-técnica no puede modificarse: el protocolo tiene programas vinculados.');
    }
    public function getCount(): int { return $this->count; }
}
```

#### `back/app/Http/Requests/Protocols/StoreProtocolRequest.php`
```php
class StoreProtocolRequest extends FormRequest
{
    public function __construct(private ProtocolRepositoryInterface $protocolRepository) { parent::__construct(); }

    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $tenantRoles = ['vet', 'vet-assistant', 'vet-administrative', 'client-owner', 'client-manager', 'client-administrative'];

        return [
            'technique_id'                    => ['required', 'string', 'uuid', 'exists:techniques,guid'],
            'name'                             => ['required', 'string', 'max:255'],
            'color'                            => ['nullable', 'string', 'max:20'],
            'country_id'                       => ['nullable', 'string', 'uuid', 'exists:countries,guid'],
            'tasks'                            => ['nullable', 'array'],
            'tasks.*.description'              => ['required', 'string'],
            'tasks.*.days_offset'              => ['required', 'integer', 'between:-365,365'],
            'tasks.*.time_of_day'              => ['required', Rule::in(['before', 'after'])],
            'tasks.*.time'                     => ['required', 'date_format:H:i'],
            'tasks.*.important'                => ['nullable', 'boolean'],
            'tasks.*.alerts'                   => ['nullable', 'array'],
            'tasks.*.alerts.*.offset_days'     => ['nullable', 'integer', 'between:-365,365'],
            'tasks.*.alerts.*.time_of_day'     => ['nullable', Rule::in(['before', 'after'])],
            'tasks.*.alerts.*.time'            => ['required', 'date_format:H:i'],
            'tasks.*.alerts.*.roles'           => ['required', 'array', 'min:1'],
            'tasks.*.alerts.*.roles.*'         => [Rule::in($tenantRoles)],
            'tasks.*.alerts.*.message'         => ['required', 'string'],
            'tasks.*.alerts.*.require_confirmation' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $technique = Technique::where('guid', $this->input('technique_id'))->first();
            if ($technique && $technique->parent_id === null) {
                $v->errors()->add('technique_id', 'El protocolo debe asociarse a una sub-técnica, nunca a la técnica raíz.');
                return;
            }
            if (!$technique) { return; } // ya cubierto por exists:

            $country = $this->input('country_id') ? Country::where('guid', $this->input('country_id'))->first() : null;
            if ($this->protocolRepository->existsDuplicate($technique->id, $country?->id, (string) $this->input('name'))) {
                $v->errors()->add('name', 'Ya existe un protocolo con este nombre para esta sub-técnica y país.');
            }
        });
    }

    public function messages(): array { /* ... mensajes en español, mismo patrón que CreateTechniqueRequest ... */ }
}
```
Nota: el controller resuelve `technique_id`/`country_id` de guid a id numérico ANTES de llamar al Service (mismo patrón que otros módulos: el Request valida contra `guid`, el Controller/Service traduce a `id` interno para persistir en `protocols.technique_id`).

#### `back/app/Http/Requests/Protocols/UpdateProtocolRequest.php`
Igual a `StoreProtocolRequest` más:
- `tasks.*.guid` → `['nullable', 'string', 'uuid']`
- `tasks.*.alerts.*.guid` → `['nullable', 'string', 'uuid']`
- En `withValidator`: excluir el propio protocolo (`$this->route('guid')`) del chequeo de duplicado, y si `technique_id` cambia respecto al actual, validar que la nueva sub-técnica comparta la misma raíz (`parent_id` igual) que la técnica actual del protocolo — RF-03 último bullet. El bloqueo por `programs` asociados (DU-01) NO se valida acá: eso lo hace `ProtocolService::update()` porque requiere el stub de conteo, que es lógica de negocio, no de forma.

#### `back/app/Http/Resources/V1/ProtocolListResource.php`
```php
class ProtocolListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'guid'        => $this->guid,
            'name'        => $this->name,
            'color'       => $this->color,
            'technique'   => ['guid' => $this->technique->guid, 'name' => $this->technique->name],
            'country'     => $this->country ? ['guid' => $this->country->guid, 'name' => $this->country->name] : null,
            'is_global'   => $this->country_id === null,
            'tasks_count' => $this->tasks_count ?? 0,
            'created_at'  => $this->created_at?->toISOString(),
        ];
    }
}
```

#### `back/app/Http/Resources/V1/ProtocolResource.php`
Igual a `ProtocolListResource` + `updated_at` + `created_by_type` + `tasks` (via `ProtocolTaskResource::collection($this->whenLoaded('tasks'))`).

#### `back/app/Http/Resources/V1/ProtocolTaskResource.php`
```php
'guid', 'description', 'days_offset', 'time_of_day', 'time' (format H:i), 'important', 'sort_order',
'alerts' => ProtocolTaskAlertResource::collection($this->whenLoaded('alerts')),
```

#### `back/app/Http/Resources/V1/ProtocolTaskAlertResource.php`
```php
'guid', 'offset_days', 'time_of_day', 'time' (format H:i), 'roles', 'message', 'require_confirmation', 'sort_order',
```

#### `back/app/Http/Controllers/V1/AdminProtocolController.php`
```php
class AdminProtocolController extends Controller
{
    public function __construct(private ProtocolService $protocolService) {}

    public function index(IndexProtocolRequest $request): JsonResponse
    {
        try {
            $filters = [
                'technique_guid' => $request->query('technique_id'),
                'country_guid'   => $request->has('country_id') ? $request->query('country_id') : false,
                'search'         => $request->query('search'),
            ];
            // 'country_guid' => false significa "sin filtro"; null explícito significa "solo globales"
            if ($filters['country_guid'] === false) unset($filters['country_guid']);

            $perPage   = $request->integer('per_page', 15);
            $paginator = $this->protocolService->paginateByRootTechnique($request->query('root_guid'), $filters, $perPage);

            return $this->makeSuccessPagination($paginator, ProtocolListResource::class);
        } catch (ModelNotFoundException $e) {
            return $this->makeNotFound('Técnica raíz no encontrada.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function store(StoreProtocolRequest $request): JsonResponse
    {
        try {
            $data = $this->resolveGuidsToIds($request->validated());
            $protocol = $this->protocolService->create($data, $request->user()->id);

            return $this->makeSuccess(new ProtocolResource($protocol), 'Protocolo creado correctamente.', 201);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function show(string $guid): JsonResponse
    {
        try {
            $protocol = $this->protocolService->findByGuidWithTasks($guid);
            if (!$protocol) { return $this->makeNotFound('Protocolo no encontrado.'); }

            return $this->makeSuccess(new ProtocolResource($protocol));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function update(UpdateProtocolRequest $request, string $guid): JsonResponse
    {
        try {
            $protocol = $this->protocolService->findByGuidWithTasks($guid);
            if (!$protocol) { return $this->makeNotFound('Protocolo no encontrado.'); }

            $data = $this->resolveGuidsToIds($request->validated());
            $protocol = $this->protocolService->update($protocol, $data);

            return $this->makeSuccess(new ProtocolResource($protocol), 'Protocolo actualizado correctamente.');
        } catch (ProtocolTechniqueLockedException $e) {
            return $this->makeError(['reason' => 'technique_locked', 'count' => $e->getCount()], $e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function destroy(string $guid): JsonResponse
    {
        try {
            $protocol = $this->protocolService->findByGuidWithTasks($guid);
            if (!$protocol) { return $this->makeNotFound('Protocolo no encontrado.'); }

            $this->protocolService->destroy($protocol);

            return $this->makeSuccess(null, 'Protocolo eliminado correctamente.');
        } catch (ProtocolHasProgramsException $e) {
            return $this->makeError(['reason' => 'has_programs', 'count' => $e->getCount()], $e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    /** Traduce technique_id/country_id (guid) → id interno antes de pasar al Service. */
    private function resolveGuidsToIds(array $data): array
    {
        $data['technique_id'] = Technique::where('guid', $data['technique_id'])->value('id');
        $data['country_id']   = $data['country_id'] ? Country::where('guid', $data['country_id'])->value('id') : null;
        return $data;
    }
}
```

#### `back/app/Http/Requests/Protocols/IndexProtocolRequest.php`
```php
public function rules(): array
{
    return [
        'root_guid'    => ['required', 'string', 'uuid', 'exists:techniques,guid'],
        'technique_id' => ['nullable', 'string', 'uuid'],
        'country_id'   => ['nullable', 'string', 'uuid'],
        'search'       => ['nullable', 'string', 'max:255'],
        'page'         => ['nullable', 'integer', 'min:1'],
        'per_page'     => ['nullable', 'integer', 'min:1', 'max:100'],
    ];
}
```

### Archivos a modificar

#### `back/app/Models/Technique.php`
**Cambio:** agregar relación inversa.
**Después:** `public function protocols(): HasMany { return $this->hasMany(Protocol::class); }`

#### `back/app/Services/TechniqueService.php`
**Cambio:** reemplazar el stub `countProtocolsForTechnique()` por la query real, ahora que `Protocol` existe.
**Antes:** `// TODO: implementar cuando exista el modelo Protocol` + `return 0;`
**Después:** `return Protocol::where('technique_id', $technique->id)->count();`
Esto es necesario porque `TechniqueService::destroy()` (RF de `techniques`, ya implementado) usa este stub para bloquear el borrado de una raíz con protocolos directos — hoy siempre pasa en falso. Si no se actualiza, el borrado de técnicas raíz con protocolos quedará roto silenciosamente.

#### `back/app/Providers/AppServiceProvider.php`
**Cambio:** agregar el binding del nuevo repository en `register()`.
**Después:** `$this->app->bind(ProtocolRepositoryInterface::class, ProtocolRepositoryEloquent::class);` (junto a los demás `bind()`, mismo bloque que `TechniqueRepositoryInterface`).

#### `back/routes/api.php`
**Cambio:** incluir el nuevo archivo de rutas.
**Después:** agregar `require __DIR__.'/api/protocols.php';` junto al resto de los `require` de `routes/api/*.php`.

#### `back/database/seeders/DatabaseSeeder.php`
**Cambio:** agregar `ProtocolPermissionsSeeder::class` al array de `$this->call([...])`, después de `TechniquePermissionsSeeder::class`.

### Rutas API

`back/routes/api/protocols.php` (nuevo archivo, mismo formato que `techniques.php`):
```php
Route::prefix('v1/admin/protocols')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [AdminProtocolController::class, 'index'])->middleware('can:protocols.read');
    Route::post('/', [AdminProtocolController::class, 'store'])->middleware('can:protocols.create');
    Route::get('/{guid}', [AdminProtocolController::class, 'show'])->middleware('can:protocols.read');
    Route::put('/{guid}', [AdminProtocolController::class, 'update'])->middleware('can:protocols.update');
    Route::delete('/{guid}', [AdminProtocolController::class, 'destroy'])->middleware('can:protocols.delete');
});
```

### Permisos Spatie

`back/database/seeders/ProtocolPermissionsSeeder.php` (nuevo, copia exacta del patrón de `TechniquePermissionsSeeder.php`):
```php
class ProtocolPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = ['protocols.read', 'protocols.create', 'protocols.update', 'protocols.delete'];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web'], ['guid' => Str::uuid()->toString()]);
        }

        $superAdmin = Role::where('name', 'super-admin')->first();
        if ($superAdmin) {
            $superAdmin->syncPermissions(Permission::all());
        }
    }
}
```
No se asignan a roles tenant (`vet`, `client-owner`, etc.) — son solo receptores de alertas en esta iteración, no administradores de protocolos (confirmado en la spec, sección "Impacto en dominio SAV").

### Contrato del endpoint

**`POST /v1/admin/protocols`**
Request:
```json
{
  "technique_id": "uuid-sub-tecnica",
  "name": "Protocolo IATF estándar",
  "color": "#3B82F6",
  "country_id": null,
  "tasks": [
    {
      "description": "Colocar CIDR",
      "days_offset": 0,
      "time_of_day": "before",
      "time": "08:00",
      "important": true,
      "alerts": [
        { "offset_days": -1, "time_of_day": "before", "time": "08:00", "roles": ["vet"], "message": "Recordatorio: colocar CIDR mañana", "require_confirmation": false }
      ]
    }
  ]
}
```
Response 201:
```json
{ "success": true, "data": { "guid": "...", "name": "...", "technique": {...}, "country": null, "is_global": true, "tasks_count": 1, "created_by_type": "superadmin", "tasks": [ { "guid": "...", "description": "...", "alerts": [ {...} ] } ], "created_at": "...", "updated_at": "..." }, "message": "Protocolo creado correctamente." }
```
Errores posibles:
- `422` validación estándar Laravel (`errors` por campo, incluye `name` duplicado y `technique_id` = raíz).
- `422` `{ errors: { reason: "technique_locked", count: N } }` — solo en `update`, DU-01.
- `422` `{ errors: { reason: "has_programs", count: N } }` — solo en `destroy`, RF-04.
- `404` técnica raíz no encontrada (`index`) o protocolo no encontrado (`show`/`update`/`destroy`).
- `403` sin permiso (`can:protocols.*` middleware).

**`GET /v1/admin/protocols?root_guid={guid}&technique_id=&country_id=&search=&page=&per_page=`**
Response 200: paginación estándar (`data`, `current_page`, `last_page`, `per_page`, `total`) con `ProtocolListResource` — SIN `tasks`/`alerts` (NFR performance, lazy).

**`GET /v1/admin/protocols/{guid}`**
Response 200: `ProtocolResource` con `tasks.alerts` eager-loaded (uso: precarga de formulario de edición, RF-03).

### Tests a generar

- `ProtocolServiceTest`: alta con tareas+alertas anidadas en una transacción; rollback si una alerta falla validación de rol vacío; edición que agrega/quita/reordena tareas y alertas (verificar `sort_order` recalculado); bloqueo de `technique_id` cuando `countProgramsForProtocol` > 0 (mockear el método o usar reflection dado que hoy siempre retorna 0 — cubrir con un test que reemplace el stub temporalmente o testear el camino "no bloqueado" únicamente, dejando un test `@todo` marcado como skip hasta que exista `Program`).
- `ProtocolRepositoryEloquentTest`: `existsDuplicate()` con `country_id` null vs con valor — casos que el índice DB solo no cubre (DEC-01).
- `AdminProtocolControllerTest` (feature): 201 alta completa, 422 duplicado, 422 técnica raíz como `technique_id`, 422 rol vacío en alerta, 404 protocolo inexistente, 403 sin permiso `protocols.create`.
- `TechniqueServiceTest`: regresión sobre `countProtocolsForTechnique()` ahora real — verificar que `destroy()` de una técnica raíz con protocolos directos lanza `TechniqueCannotBeDeletedException` con `reason: 'has_protocols'`.

## Cambios en FRONTEND

### Archivos a crear

Nuevo módulo `front/src/modules/protocols/` (feature module pattern). `pages/` y `router/` no se crean esta iteración: el ABM vive embebido en el tab "Protocolos" de `TechniqueDetailPage.vue` (módulo `techniques`), no tiene rutas propias — ver Riesgos.

#### `front/src/modules/protocols/types/protocol.types.ts`
```typescript
export type TimeOfDay = 'before' | 'after'
export type TenantRole = 'vet' | 'vet-assistant' | 'vet-administrative' | 'client-owner' | 'client-manager' | 'client-administrative'

export interface ProtocolTaskAlert {
  guid: string
  offset_days: number
  time_of_day: TimeOfDay
  time: string
  roles: TenantRole[]
  message: string
  require_confirmation: boolean
  sort_order: number
}

export interface ProtocolTask {
  guid: string
  description: string
  days_offset: number
  time_of_day: TimeOfDay
  time: string
  important: boolean
  sort_order: number
  alerts: ProtocolTaskAlert[]
}

export interface ProtocolTechniqueRef { guid: string; name: string }
export interface ProtocolCountryRef { guid: string; name: string }

export interface ProtocolListItem {
  guid: string
  name: string
  color: string | null
  technique: ProtocolTechniqueRef
  country: ProtocolCountryRef | null
  is_global: boolean
  tasks_count: number
  created_at: string
}

export interface ProtocolDetail extends ProtocolListItem {
  created_by_type: 'superadmin' | 'vet'
  updated_at: string
  tasks: ProtocolTask[]
}

export interface ProtocolListParams {
  root_guid: string
  technique_id?: string
  country_id?: string | null
  search?: string
  page?: number
  per_page?: number
}

export interface ProtocolTaskAlertPayload {
  guid?: string
  offset_days: number
  time_of_day: TimeOfDay
  time: string
  roles: TenantRole[]
  message: string
  require_confirmation: boolean
}

export interface ProtocolTaskPayload {
  guid?: string
  description: string
  days_offset: number
  time_of_day: TimeOfDay
  time: string
  important: boolean
  alerts: ProtocolTaskAlertPayload[]
}

export interface CreateProtocolPayload {
  technique_id: string
  name: string
  color: string | null
  country_id: string | null
  tasks: ProtocolTaskPayload[]
}

export type UpdateProtocolPayload = CreateProtocolPayload

export interface ProtocolDeleteError { reason: 'has_programs'; count: number }
export interface ProtocolTechniqueLockedError { reason: 'technique_locked'; count: number }
```

#### `front/src/modules/protocols/api/protocol.api.ts`
Mismo patrón que `technique.api.ts`:
```typescript
export async function adminListProtocolsApi(params: ProtocolListParams, signal?: AbortSignal): Promise<PaginatedResponse<ProtocolListItem>>
export async function adminGetProtocolApi(guid: string): Promise<ProtocolDetail>
export async function adminCreateProtocolApi(payload: CreateProtocolPayload): Promise<ProtocolDetail>
export async function adminUpdateProtocolApi(guid: string, payload: UpdateProtocolPayload): Promise<ProtocolDetail>
export async function adminDeleteProtocolApi(guid: string): Promise<void>
```
Endpoints: `/v1/admin/protocols` (GET/POST), `/v1/admin/protocols/{guid}` (GET/PUT/DELETE).

#### `front/src/modules/protocols/composables/useProtocolList.ts`
```typescript
export function useProtocolList(params: Ref<ProtocolListParams>) {
  return useQuery({
    queryKey: ['admin-protocols', params],
    queryFn: () => adminListProtocolsApi(toValue(params)),
    enabled: computed(() => Boolean(toValue(params).root_guid)),
  })
}
```

#### `front/src/modules/protocols/composables/useProtocolDetail.ts`
Mismo patrón que `useTechniqueDetail.ts`, query key `['admin-protocol', guid]`, `enabled` solo cuando `guid` tiene valor (se usa al abrir el drawer de edición, no en el listado — NFR performance).

#### `front/src/modules/protocols/composables/useProtocolMutations.ts`
`useCreateProtocol`, `useUpdateProtocol`, `useDeleteProtocol` — mismo patrón que `useTechniqueMutations.ts` (fieldErrors/generalError vía `parseApiError`, manejo especial de errores `422` con `reason: 'has_programs'` o `reason: 'technique_locked'` en `errors`, invalidación de `['admin-protocols']` y `['admin-protocol', guid]` en `onSuccess`).

#### `front/src/modules/protocols/composables/useProtocolTaskRepeater.ts` y `useProtocolAlertRepeater.ts`
Mismo patrón que `useSubTechniqueRepeater.ts`: `add`, `remove`, `moveUp`/`moveDown` (DEC-05), `setItems`, `reset` operando sobre un array en memoria antes del submit final.

#### `front/src/modules/protocols/validators/protocol.validator.ts`
Zod, con `roles` mínimo 1 (RF-05):
```typescript
const tenantRoleValues = ['vet', 'vet-assistant', 'vet-administrative', 'client-owner', 'client-manager', 'client-administrative'] as const

export const protocolTaskAlertSchema = z.object({
  guid: z.string().uuid().optional(),
  offset_days: z.number().int().default(0),
  time_of_day: z.enum(['before', 'after']).default('before'),
  time: z.string().regex(/^([01]\d|2[0-3]):[0-5]\d$/, 'Hora inválida'),
  roles: z.array(z.enum(tenantRoleValues)).min(1, 'Seleccioná al menos un rol'),
  message: z.string().min(1, 'El mensaje es requerido'),
  require_confirmation: z.boolean().default(false),
})

export const protocolTaskSchema = z.object({
  guid: z.string().uuid().optional(),
  description: z.string().min(1, 'La descripción es requerida'),
  days_offset: z.number().int(),
  time_of_day: z.enum(['before', 'after']),
  time: z.string().regex(/^([01]\d|2[0-3]):[0-5]\d$/, 'Hora inválida'),
  important: z.boolean().default(false),
  alerts: z.array(protocolTaskAlertSchema).default([]),
})

export const protocolSchema = z.object({
  technique_id: z.string().uuid('Seleccioná una sub-técnica'),
  name: z.string().min(1, 'El nombre es requerido').max(255),
  color: z.string().max(20).nullable().optional().transform((v) => v ?? null),
  country_id: z.string().uuid().nullable().optional().transform((v) => v ?? null),
  tasks: z.array(protocolTaskSchema).default([]),
})

export type ProtocolFormValues = z.infer<typeof protocolSchema>
```

#### `front/src/modules/protocols/components/ProtocolFormDrawer.vue`
`BaseDrawer` con `useForm` (vee-validate + `protocolSchema`). Campos: `technique_id` (`BaseSelect`, opciones = `technique.children` recibidas como prop, nunca la raíz — RF-06), `name` (`BaseInput`), `color` (input color/hex), `country_id` (`BaseSelect` alimentado por `useCountries()` del módulo `vets`, opción "Global" = `null`). Renderiza `<ProtocolTaskList v-model="tasks" />` anidado. Deshabilitado con mensaje si `technique.children.length === 0` (RF-06, segundo bullet).

#### `front/src/modules/protocols/components/ProtocolTaskList.vue` + `ProtocolTaskFormItem.vue`
Lista repetible de tareas usando `useProtocolTaskRepeater`. Cada item: `description`, `days_offset` (number input, acepta negativos), `time_of_day` (`BaseSelect` before/after), `time` (time input), `important` (checkbox), botones ↑/↓ (DEC-05) y eliminar. Renderiza `<ProtocolAlertList v-model="task.alerts" />` anidado.

#### `front/src/modules/protocols/components/ProtocolAlertList.vue` + `ProtocolAlertFormItem.vue`
Lista repetible de alertas dentro de una tarea usando `useProtocolAlertRepeater`. Cada item: `offset_days`, `time_of_day`, `time`, `roles` (`BaseSelect` `mode="multiple"`, opciones = las 6 roles tenant con label vía `getRoleLabel()` de `@/core/utils/roles`), `message` (textarea), `require_confirmation` (checkbox), ↑/↓, eliminar. Bloquea guardado si `roles.length === 0` (RF-05, validado por Zod + backend).

#### `front/src/modules/protocols/components/ProtocolDeleteModal.vue`
`BaseConfirmDialog`, mismo patrón que `TechniqueDeleteModal.vue`. Si `blockedError?.reason === 'has_programs'`, muestra mensaje "Este protocolo tiene {count} programa(s) vinculado(s) y no puede eliminarse." en vez del confirm genérico.

### Archivos a modificar

#### `front/src/modules/techniques/components/TechniqueProtocolsTab.vue`
**Cambio:** reemplazo completo del placeholder por el listado real.
**Antes:** recibe `protocols: ProgramsStub` (stub `{data: [], total: 0, ...}`) y muestra solo un contador.
**Después:** recibe `technique: Technique` (la raíz completa, con `children`) como prop en vez de `protocols`. Internamente usa `useProtocolList({ root_guid: technique.guid, ... })` (filtros de sub-técnica y país vía `TechniqueFilters`-like local component o controles inline), `BaseDataTable` con columnas nombre/sub-técnica/país/color/`tasks_count`/`created_at` (RF-01, último bullet), `BaseTableActions` para editar/eliminar detrás de `PermissionGuard`, botón "Nuevo protocolo" detrás de `PermissionGuard permission="protocols.create"` que abre `ProtocolFormDrawer`. Estado vacío (`a-empty`) cuando `technique.children.length === 0`, con mensaje "Primero creá una sub-técnica" (RF-01, segundo bullet / RF-06).

#### `front/src/modules/techniques/pages/TechniqueDetailPage.vue`
**Cambio:** pasar `technique` (no `protocols`/`programs`) al tab.
**Antes:** `<TechniqueProtocolsTab v-if="protocols" :protocols="protocols" />` (línea 115, usa el stub `data.value?.programs`).
**Después:** `<TechniqueProtocolsTab :technique="technique" />` — ya no depende del stub `programs` del endpoint `GET /v1/admin/techniques/{guid}` (ese stub queda intacto en el backend de `techniques`, fuera de alcance tocarlo; el tab de protocolos ahora hace su propio fetch vía `useProtocolList`).

#### `front/src/modules/techniques/types/technique.types.ts`
**Cambio:** ninguno estrictamente necesario (el tipo `ProgramsStub`/`TechniqueDetail` se mantiene igual, el backend de `techniques` no cambia su contrato). Si el dev quiere limpiar el nombre confuso `ProgramsStub` ya que ahora "protocolos" es un concepto real y separado, es opcional — no forma parte de esta spec.

### Tests a generar

- `ProtocolFormDrawer.spec.ts`: no permite guardar con `roles` vacío en una alerta; no permite seleccionar la técnica raíz como `technique_id` (opciones ya vienen filtradas a `children`, pero validar que el schema Zod rechaza igual); `days_offset` negativo se acepta.
- `useProtocolMutations.spec.ts`: mapeo de error `422` `reason: 'has_programs'` → `deleteBlockedError`; mapeo de `reason: 'technique_locked'` → mensaje de campo bloqueado.
- `TechniqueProtocolsTab.spec.ts`: estado vacío cuando `technique.children.length === 0`; fetch solo se dispara con `root_guid` presente.

## Orden de implementación

1. Migraciones (`protocols`, `protocol_tasks`, `protocol_task_alerts`) + `php artisan migrate`.
2. Modelos `Protocol`, `ProtocolTask`, `ProtocolTaskAlert` + relación `Technique::protocols()`.
3. `ProtocolRepositoryInterface` + `ProtocolRepositoryEloquent` + binding en `AppServiceProvider`.
4. `ProtocolHasProgramsException`, `ProtocolTechniqueLockedException`.
5. `ProtocolService` (incluye `syncTasks`/`syncAlerts`/stub `countProgramsForProtocol`).
6. `StoreProtocolRequest`, `UpdateProtocolRequest`, `IndexProtocolRequest`.
7. `ProtocolListResource`, `ProtocolResource`, `ProtocolTaskResource`, `ProtocolTaskAlertResource`.
8. `AdminProtocolController` + `routes/api/protocols.php` + include en `routes/api.php`.
9. `ProtocolPermissionsSeeder` + registrar en `DatabaseSeeder` + correr seeder en entorno de desarrollo.
10. Actualizar `TechniqueService::countProtocolsForTechnique()` (stub → query real) — regresión sobre `techniques` ya implementado.
11. Tests backend (Service, Repository, Controller feature, regresión `TechniqueService`).
12. Frontend: `types/`, `api/`, `validators/` del módulo `protocols`.
13. Frontend: `composables/` (`useProtocolList`, `useProtocolDetail`, `useProtocolMutations`, repeaters).
14. Frontend: componentes anidados (`ProtocolAlertFormItem` → `ProtocolAlertList` → `ProtocolTaskFormItem` → `ProtocolTaskList` → `ProtocolFormDrawer` → `ProtocolDeleteModal`), de adentro hacia afuera.
15. Frontend: reemplazar `TechniqueProtocolsTab.vue` y el prop que le pasa `TechniqueDetailPage.vue`.
16. Tests frontend.
17. QA manual end-to-end: alta con tareas+alertas, edición con reorden, bloqueo de raíz sin children, borrado bloqueado (forzar `countProgramsForProtocol` a devolver >0 temporalmente para probar el camino de error, luego revertir).

## Riesgos y consideraciones

- **Gap real en `TechniqueService::update()`**: al sincronizar hijos de una técnica raíz, `deleteChild()` solo valida `countProgramsForTechnique()` sobre el child a eliminar, NUNCA valida si ese child tiene `protocols` asociados. Con este plan, borrar una sub-técnica que tiene protocolos hijos fallará recién a nivel DB por el FK `restrictOnDelete()` en `protocols.technique_id` (excepción SQL cruda, no un `TechniqueChildHasProgramsException` prolijo). Recomendación: ticket de seguimiento para extender `TechniqueService::update()` con un chequeo análogo a `countProgramsForTechnique()` pero sobre protocolos, para devolver un 422 de negocio en vez de un error 500 de FK. Fuera de alcance de esta spec (toca código de `techniques`, no de `protocols`), pero el riesgo nace directamente de este plan.
- **Discrepancia con `backend-conventions.md`**: el skill dice "Sin `softDeletes()` — el proyecto no los usa", pero la spec exige `deleted_at` en `protocols` (y este plan lo extiende a `protocol_tasks`/`protocol_task_alerts`, DEC-02). El código real tiene precedente (`Notification` model) que contradice el skill. Se prioriza spec + código real sobre el skill, tal como indican las reglas de exploración.
- **`countProgramsForProtocol()` como stub permanente hasta que exista `Program`**: mientras no se implemente `programs`, RF-04 (bloqueo de borrado) y DU-01 (bloqueo de `technique_id`) están "abiertos" en la práctica — cualquier protocolo puede borrarse o recategorizarse aunque en el futuro tenga programas, porque hoy la función siempre retorna 0. Esto es aceptado explícitamente por la spec (RD-03), pero debe quedar visible como deuda técnica activa hasta que el dev de `programs` reemplace el stub.
- **Multi-tenant**: no aplica en escritura (confirmado en NFR), pero el endpoint se diseñó para no romper la extensión futura (`vet_id` ya existe en el modelo, `created_by_type`/`created_by_id` ya distinguen origen) — si más adelante se reutiliza el mismo controller/service para vets, hay que agregar scope de tenant en la query de `paginateByRootTechnique` y en `create()`/`update()` para que `vet_id` no nulo respete al vet autenticado.
- **Multi-país**: `country_id` nullable ya contemplado desde el diseño (regla dura #5), filtro de listado y selector de formulario usan el catálogo real de `countries` vía `country_id`/`guid`, no hardcodean Argentina.
- **`ProtocolTaskAlert.roles` validado contra lista hardcodeada de 6 strings** (no hay `RolesEnum` ni tabla de catálogo de roles tenant en el backend, se confirmó con Grep). Si en el futuro se agrega un rol tenant nuevo, hay que actualizar el `Rule::in([...])` en ambos FormRequests Y el array `tenantRoleValues` del validator Zod en frontend — dos lugares desincronizables. Riesgo aceptado porque es el mismo patrón que ya usa el resto del proyecto (no hay abstracción central de roles tenant hoy).
- **Módulo frontend `protocols` sin `pages/`/`router/` propios**: se decidió (DEC-04 implícito) no crear rutas standalone porque el ABM vive 100% embebido en `TechniqueDetailPage.vue`. Si a futuro se pide una vista de detalle de protocolo independiente, hay que agregar esas carpetas — no rompe nada hoy, pero es una desviación de la convención de "8 sub-carpetas obligatorias" que vale la pena que el dev tenga presente al ejecutar.

## Pendientes / fuera de alcance

- `programs`, `program_tasks`, `program_task_alerts` — capa instancia completa (incluye reemplazar los stubs de `countProgramsForProtocol()` y `countProgramsForTechnique()` por queries reales).
- Motor de despacho (`alerts`, `alert_user`, `HasAlertsTrait`, `SendAlertsNotifications`).
- Flujo de creación de programa por el vet (wizard, `suggested-recipients`, overrides) — ver doc base v2, sección 5.
- UI de creación de protocolos propios por un vet (`vet_id` no nulo) — el esquema ya lo soporta, falta el flujo/permiso/scope de tenant.
- Duplicar/clonar protocolo.
- Ticket de seguimiento: extender `TechniqueService::update()` para validar protocolos antes de borrar un child de técnica raíz (ver Riesgos).
