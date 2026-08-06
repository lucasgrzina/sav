# Plan técnico: Módulo "Programa" (alta greenfield — panel tenant Vet)

## Input procesado
`docs/planes/spec-program.md` (spec funcional cerrada, §8 = diseño adoptado a implementar).

> **Actualización 2026-07-22**: se agregó al final del documento una sección **"Actualización — Ajustes de UX en alta/edición y detalle"** con DEC-11 a DEC-16, que **reemplaza** el enfoque de Drawer para alta/edición (era el default implícito de este plan, ver componente `ProgramFormDrawer.vue` más abajo) por una vista de página completa por secciones, agrega la vista de detalle con tareas/alertas proyectadas, y cierra dos gaps de código reales encontrados en la exploración (`target_date_name` faltante en `TechniqueChildResource`, endpoints de staff vet/cliente ya existentes y reutilizables). El contenido original del plan (DEC-01 a DEC-10) permanece vigente y no se modifica salvo donde la actualización lo indica explícitamente.

## Resumen ejecutivo
Se implementa `Program` como entidad greenfield con 1..N `ProgramTarget` (fecha propia por objetivo), animales persistentes y compartibles vía tabla `animals`, y managers vía `program_manager` sobre `user_profiles`. Alcance: CRUD completo (alta/edición/cancelación/listado/detalle) scoped al panel tenant Vet, sin motor de alertas (queda para otro spec) y sin API de Client. Se sigue al pie de la letra el patrón repository+service+form request+resource+controller que ya usan `Protocol`/`Technique` en este proyecto. La selección de animales por Objetivo en el frontend usa un `a-select` de Ant Design Vue en modo `tags` con búsqueda asíncrona contra un endpoint nuevo de animales por cliente — **reemplaza** el enfoque legacy de `animals_rp` como string separado por comas (ver DEC-10). Las reglas de unicidad de `rp` y de ownership de `id` de animal ya están confirmadas por el usuario (ver DEC-07 y Riesgos resueltos). **Actualización**: el alta/edición pasa a ser una página completa por secciones (no modal/Drawer) y se agrega una vista de detalle que muestra tareas y alertas proyectadas mapeadas a responsables por rol (ver DEC-11 a DEC-16).

## Decisiones tomadas

DEC-01 — `vet_id` explícito en `programs`
  Decisión: agregar columna `vet_id` (FK obligatoria a `vets`) a `programs`, aunque el spec funcional no la liste explícitamente en §2.1.
  Justificación: `Client` es `belongsToMany` de `Vet` (pivot `client_vet` — un mismo cliente puede tener vínculo con más de una vet). Sin `vet_id` propio en `programs`, el scope multi-tenant (regla dura #4) dependería de un join indirecto vía `client_vet`, que se rompe si el mismo cliente trabaja con dos vets — un programa creado por la Vet A para un Client compartido aparecería también en el listado de la Vet B. `Protocol` ya resuelve esto con `vet_id` propio (nullable ahí porque también sirve de plantilla global; acá es siempre obligatorio, no hay "Program global"). Este patrón replica exactamente `ProtocolRepositoryEloquent::paginateForVet`.
  Alternativa descartada: scope solo por `client_id` + join a `client_vet`. Descartada por ambigüedad de tenant cuando un cliente tiene más de una vet.

DEC-02 — `cancelled_at` en vez de `state` string
  Decisión: columna `cancelled_at` (timestamp nullable), sin columna `state`. `editable` es un accessor calculado (`cancelled_at === null`).
  Justificación: instrucción explícita del usuario (decisión 5 del pedido) — `pending`/`completed`/`in_progress` dependen de `Alert.delivered_at`, que no existe todavía. Documentado igual en §4 del spec como "pendiente para cuando se defina el motor de alertas".
  Alternativa descartada: persistir `state` string con solo el valor `'cancelled'` usado hoy (como legacy). Descartada porque induce a error: un dev leería la columna y asumiría que los otros valores están soportados.

DEC-03 — Permisos en inglés `programs.read/create/update/delete`
  Decisión: usar el patrón real del código (`protocols.read`, `techniques.read`, etc.), NO el patrón `{modulo}.lectura/alta/modificacion/baja` que describe `.claude/skills/backend-conventions.md`.
  Justificación: el código es la fuente de verdad (regla de exploración). `PermissionSeeder`/`ProtocolPermissionsSeeder`/`TechniquePermissionsSeeder` usan consistentemente el sufijo en inglés `read/create/update/delete` en TODOS los módulos existentes, sin una sola excepción en español. Seguir el doc introduciría el primer permiso inconsistente del sistema.
  Riesgo anotado abajo: el skill doc está desactualizado, recomendar corregirlo en paralelo (fuera de este plan).

DEC-04 — `ProgramManagerSeeder`/`ProgramPermissionsSeeder` separado, igual a `ProtocolPermissionsSeeder`
  Decisión: seeder de permisos propio `ProgramPermissionsSeeder`, registrado en `DatabaseSeeder` después de `ProtocolPermissionsSeeder`.
  Justificación: convención 1:1 existente (`TechniquePermissionsSeeder`, `ProtocolPermissionsSeeder`). Roles `vet`, `vet-administrative`, `vet-assistant` reciben el set completo (mismo criterio DEC-10 de Protocol — son quienes gestionan programas del tenant).
  **Nota de estado (exploración 2026-07-22): este seeder ya existe en el repo** (`back/database/seeders/ProgramPermissionsSeeder.php`), con exactamente los 3 permisos (`programs.read/create/update`) y los 3 roles tenant — confirma que esta decisión ya fue aplicada, no hace falta crearlo de nuevo.

DEC-05 — Validación de pertenencia Client→Vet y Establishment→Client reutiliza repos existentes
  Decisión: `StoreProgramRequest`/`UpdateProgramRequest` usan `ClientRepositoryInterface::findByGuidForVet()` (ya existe) para validar que el `client_id` recibido pertenece al tenant, y validan que `establishment_id` pertenece a ese `client` consultando `Establishment::where('guid', ...)->where('client_id', $client->id)`.
  Justificación: evita reinventar scope de tenant; reutiliza el mismo mecanismo que ya usa el módulo de clients.
  Alternativa descartada: crear una regla de validación Rule custom nueva — innecesario, el patrón `withValidator` + repos inyectados ya es el usado en `StoreVetProtocolRequest`.

DEC-06 — `technique_id` en `programs` no se re-deriva de `protocol_id`
  Decisión: se persiste igual que en el spec (§2.1), aunque sea denormalizado respecto a `protocol.technique_id`. Se valida en el FormRequest que `protocol.technique_id === technique_id` recibido (o se ignora `technique_id` del payload y se toma directamente de `$protocol->technique_id` en el Service).
  Decisión final: el Service toma `technique_id` SIEMPRE de `$protocol->technique_id` (ignora cualquier valor que llegue del front para ese campo) — elimina la posibilidad de inconsistencia de raíz, más simple que validar coherencia. El campo se mantiene en la tabla por requerimiento explícito del spec (permite filtrar programas por técnica sin joinear `protocols`, igual razón que en el legado).
  Alternativa descartada: exigir que el front mande `technique_id` y validar coherencia con el protocolo — más superficie de error para ningún beneficio.

DEC-07 — Animales por Objetivo: contrato `{ id?: guid, rp: string }`, unicidad por cliente y ownership silencioso (reemplaza `animals_rp` string legacy y el diseño inicial de este plan)
  Decisión: cada animal dentro de `targets.*.animals` viaja como `{ id?: string (guid de Animal existente), rp: string }`. Si `id` viene, el Service resuelve y asocia el `Animal` existente, **validando que pertenece al `client_id` del programa**. Si `id` no viene, el Service resuelve/crea un `Animal` con ese `rp` bajo el `client_id` del programa. `rp` viaja SIEMPRE (incluso para animales existentes, tal como los devuelve el picker) porque es el valor que el componente de selección de Ant Design Vue maneja nativamente en modo `tags`.
  **Reglas de negocio confirmadas por el usuario (cierran los 3 riesgos que habían quedado abiertos en la versión anterior de este plan):**
  1. **Unicidad de `rp` es por cliente, no global** — constraint `unique(client_id, rp)` en la tabla `animals` (ver migración). Dos clientes distintos pueden tener animales con el mismo `rp` sin conflicto, son entidades independientes.
  2. **`id` de animal que pertenece a OTRO cliente (no al `client_id` del Program): se descarta silenciosamente** — no se agrega al target, no se crea nada en su lugar, no rompe el resto del guardado. Es un comportamiento esperado (defensivo), no un error 422. Se loguea (nivel `warning`, no visible al usuario) para poder debuggear si ocurre con frecuencia — ver pseudocódigo de `syncTargetAnimals` abajo.
  3. **`rp` nuevo (sin `id`) que coincide con un `rp` ya existente de OTRO cliente: se crea igual, como animal nuevo de este cliente** — no hay conflicto (la unicidad es compuesta `(client_id, rp)`) y NO se debe buscar/reusar animales de otros clientes por `rp` — el scope de "reuso" es siempre dentro del mismo `client_id`.
  Justificación: reemplaza la decisión original de este plan (`{ guid?, rp?, name?, establishment_id? }`, más flexible pero sin contrato de UI definido) por el contrato específico pedido por el usuario para alimentar un `a-select mode="tags"`, y cierra con reglas de negocio explícitas los 3 puntos que habían quedado como riesgo abierto. Se elimina `name`/`establishment_id` del payload de creación inline — no están en el alcance de esta interacción (quedan como campos que se pueden completar después, vía un futuro endpoint de edición de `Animal` si el spec de HealthPlan lo requiere).
  Alternativa descartada: rechazar con 422 un `id` que no pertenece al cliente (en vez de descartarlo silenciosamente) — descartada porque el usuario confirmó explícitamente que debe ser un "skip" defensivo, no un error visible; forzar un 422 ahí rompería el resto del guardado de un target que puede tener otros animales válidos.

DEC-08 — Managers: reemplazo total (`sync`) en updates, no diff manual
  Decisión: `program_manager` se sincroniza con `sync()` de Eloquent (reemplaza todo el set), igual que hace `UpdateProgramAction` hoy en el legado para managers (no para animales).
  Justificación: managers no tienen historial que preservar (a diferencia de `Animal`, que sí es entidad persistente con trazabilidad); `sync()` es la operación correcta y ya es el patrón usado en el propio legado para esta relación específica.

DEC-09 — Listado: `next_target_date` + `targets_count` calculados en el repository, no en el Resource
  Decisión: `ProgramRepositoryEloquent::paginateForVet()` hace `withCount('targets')` y anota `next_target_date` con una subquery/`with(['targets' => fn ($q) => $q->orderByRaw(...)->limit(1)])` resuelta en PHP tras cargar (no en SQL crudo), evaluada así: se cargan los targets ordenados por fecha ascendente, se busca en la colección PHP el primero con `target_date >= hoy`, si no hay ninguno se toma el máximo (más próximo en general, aunque vencido) — regla exacta que pide el punto 6 del pedido del usuario.
  Justificación: mantiene la lógica de "vencido vs. no vencido" testeable en Service/Repository en vez de en el Resource (que debe ser una capa de serialización pura, no de negocio) — sigue la convención "Resources solo exponen shape, la lógica va en Service".
  Alternativa descartada: calcularlo en el Resource accediendo a `$this->targets` — mezclaría lógica de negocio (qué es "vencido") en la capa de serialización.

DEC-10 — Búsqueda de animales existentes: endpoint dedicado `GET /v1/vets/{vet}/clients/{guid}/animals`, consumido por un `a-select mode="tags"` con debounce en el frontend
  Decisión: se agrega un endpoint de solo lectura, scoped al `client` (y transitivamente al `vet` vía middleware `vet.tenant`), que retorna animales del cliente filtrados por `search` (match parcial sobre `rp` y `name`). El frontend lo consume desde un `a-select` de Ant Design Vue en modo `tags`: escribir dispara `@search` (debounced) contra este endpoint; seleccionar un resultado agrega un tag resuelto a un `id` real; escribir un valor que no matchea ningún resultado y confirmarlo (Enter/blur) agrega un tag "libre" que se interpreta como RP nuevo (sin `id`) — ver DEC-07 para el contrato que llega al backend.
  Justificación: esto es exactamente lo que pidió el usuario, y resuelve formalmente el riesgo abierto que había quedado marcado en la versión anterior de este plan ("no hay endpoint de búsqueda de animales existentes por cliente todavía"). Reemplaza el enfoque legacy de `animals_rp` como string separado por comas (§2.6 del spec) por una interacción de selección real, sin renombrar ni tocar el modelo de datos ya definido (`animals`, `program_target_animal`) — el cambio es puramente de contrato de payload (DEC-07) + UI + un endpoint de lectura nuevo.
  Alternativa descartada: reutilizar `AnimalRepositoryInterface::findManyByGuids` desde el frontend con un input de texto libre para escribir guids a mano — inviable para UX real, el usuario nunca ve ni tipea guids.
  **Nota de estado (exploración 2026-07-22): esta decisión ya está aplicada en código.** `back/routes/api/programs.php` y `back/routes/api/animals.php` ya existen con exactamente el shape descripto acá (prefijo, permisos, controllers). No requieren recreación, solo verificar que `ProgramController`/`AnimalController`/Services/Repositories detrás de esas rutas estén completos según el resto de este plan.

## Cambios en BACKEND

### Migrations (orden exacto de creación)

1. `back/database/migrations/2026_MM_DD_000001_create_animals_table.php`
   - `id`, `guid char(36) unique`
   - `client_id` unsignedBigInteger, FK → `clients.id` `cascadeOnDelete()`
   - `establishment_id` unsignedBigInteger nullable, FK → `establishments.id` `nullOnDelete()`
   - `rp` string(50) — comment: "Identificación de rodeo (RP), reemplaza rp_donor legacy. Único por cliente, no globalmente (ver constraint abajo)."
   - `name` string(150) nullable — comment: "Uso futuro: nombre de mascota (HealthPlan)"
   - `type` string(20) default `'livestock'` — comment: "'livestock' | 'pet' — solo 'livestock' se usa en este módulo"
   - `timestamps()`
   - índice: `index(['client_id', 'type'])`
   - **`unique(['client_id', 'rp'], 'animals_client_id_rp_unique')`** — regla de negocio confirmada (DEC-07, regla 1): el `rp` es único POR CLIENTE, no global. Dos clientes distintos pueden compartir el mismo `rp` sin conflicto; el mismo cliente no puede tener dos animales con el mismo `rp`. Este índice sirve simultáneamente como constraint de integridad Y como índice de soporte para `WHERE client_id = ? AND rp LIKE ?` del endpoint de búsqueda (DEC-10) — no hace falta un índice separado `(client_id, rp)` solo para búsqueda, este ya cubre ambos casos (aunque el `LIKE '%...%'` con comodín inicial no puede usar el índice para el propio filtro `LIKE`, sí lo usa para la igualdad `client_id = ?`, que es lo que realmente acota el volumen de filas a escanear).

2. `back/database/migrations/2026_MM_DD_000002_create_programs_table.php`
   - `id`, `guid char(36) unique`
   - `vet_id` unsignedBigInteger, FK → `vets.id` `cascadeOnDelete()` (ver DEC-01)
   - `client_id` unsignedBigInteger, FK → `clients.id` `cascadeOnDelete()`
   - `establishment_id` unsignedBigInteger, FK → `establishments.id` `cascadeOnDelete()`
   - `technique_id` unsignedBigInteger, FK → `techniques.id` `restrictOnDelete()`
   - `protocol_id` unsignedBigInteger, FK → `protocols.id` `restrictOnDelete()`
   - `comments` text nullable
   - `cancelled_at` timestamp nullable
   - `timestamps()`
   - índices: `index('vet_id')`, `index(['vet_id', 'cancelled_at'])` (listado filtra "activos" frecuentemente)

3. `back/database/migrations/2026_MM_DD_000003_create_program_targets_table.php`
   - `id`, `guid char(36) unique`
   - `program_id` unsignedBigInteger, FK → `programs.id` `cascadeOnDelete()`
   - `target_date` date
   - `timestamps()`
   - índice: `index(['program_id', 'target_date'])`

4. `back/database/migrations/2026_MM_DD_000004_create_program_target_animal_table.php`
   - `id`
   - `program_target_id` unsignedBigInteger, FK → `program_targets.id` `cascadeOnDelete()`
   - `animal_id` unsignedBigInteger, FK → `animals.id` `cascadeOnDelete()`
   - `timestamps()`
   - `unique(['program_target_id', 'animal_id'])`

5. `back/database/migrations/2026_MM_DD_000005_create_program_manager_table.php`
   - `id`
   - `program_id` unsignedBigInteger, FK → `programs.id` `cascadeOnDelete()`
   - `user_profile_id` unsignedBigInteger, FK → `user_profiles.id` `cascadeOnDelete()`
   - `timestamps()`
   - `unique(['program_id', 'user_profile_id'])`

No hay migración de datos (greenfield, §8.6 del spec no aplica — no hay `AnimalsGroup`/`Program` legacy en este repo).

### Modelos a crear

#### `back/app/Models/Animal.php`
```php
class Animal extends Model
{
    use HasGuid;
    protected $fillable = ['client_id', 'establishment_id', 'rp', 'name', 'type'];
    protected $hidden = ['id'];

    public function client(): BelongsTo;          // belongsTo Client
    public function establishment(): BelongsTo;   // belongsTo Establishment
    public function programTargets(): BelongsToMany; // belongsToMany(ProgramTarget::class, 'program_target_animal')
}
```

#### `back/app/Models/Program.php`
```php
class Program extends Model
{
    use HasGuid;
    protected $fillable = ['vet_id', 'client_id', 'establishment_id', 'technique_id', 'protocol_id', 'comments', 'cancelled_at'];
    protected $hidden = ['id'];
    protected function casts(): array { return ['cancelled_at' => 'datetime']; }

    public function vet(): BelongsTo;
    public function client(): BelongsTo;
    public function establishment(): BelongsTo;
    public function technique(): BelongsTo;
    public function protocol(): BelongsTo;
    public function targets(): HasMany;              // hasMany(ProgramTarget::class)->orderBy('target_date')
    public function managers(): BelongsToMany;        // belongsToMany(UserProfile::class, 'program_manager')

    public function editable(): Attribute            // accessor calculado
    {
        return Attribute::get(fn () => $this->cancelled_at === null);
    }
}
```

#### `back/app/Models/ProgramTarget.php`
```php
class ProgramTarget extends Model
{
    use HasGuid;
    protected $fillable = ['program_id', 'target_date'];
    protected $hidden = ['id'];
    protected function casts(): array { return ['target_date' => 'date']; }

    public function program(): BelongsTo;
    public function animals(): BelongsToMany; // belongsToMany(Animal::class, 'program_target_animal')
}
```

### Repository — interface + Eloquent

#### `back/app/Contracts/Repositories/ProgramRepositoryInterface.php`
```php
interface ProgramRepositoryInterface
{
    public function paginateForVet(int $vetId, array $filters, int $perPage): LengthAwarePaginator;
    public function findByGuidForVet(string $guid, int $vetId): ?Program;
    public function create(array $data): Program;
    public function update(Model $model, array $data): Model;
}
```
Filtros soportados en `paginateForVet`: `technique_guid` (incluye hijos, mismo patrón que `ProtocolRepositoryEloquent::paginateForVet` + `Technique::children()`), `client_guid`, `establishment_guid`, `cancelled` (bool|null — null = todos), `search` (sobre `comments` o nombre de establecimiento).

#### `back/app/Repositories/ProgramRepositoryEloquent.php`
- `model()`: retorna `Program::class`.
- `paginateForVet`: `where('vet_id', $vetId)->with(['client:id,guid,name','establishment:id,guid,name','technique:id,guid,name','protocol:id,guid,name'])->withCount('targets')->with(['targets' => fn ($q) => $q->orderBy('target_date')])`. Aplica filtros. `orderByDesc('created_at')`.
- `findByGuidForVet`: scope estricto por `vet_id`, con `with(['targets.animals', 'managers.user', 'managers.role', 'client', 'establishment', 'technique', 'protocol.tasks.alerts'])` (se agrega `protocol.tasks.alerts` y `managers.role` respecto a la versión original, requerido por DEC-15/DEC-16 de la actualización).
- `create`/`update`: delegan a `BaseRepositoryEloquent`.

Binding en `back/app/Providers/AppServiceProvider.php::register()`:
```php
$this->app->bind(ProgramRepositoryInterface::class, ProgramRepositoryEloquent::class);
```

#### `back/app/Contracts/Repositories/AnimalRepositoryInterface.php` + `back/app/Repositories/AnimalRepositoryEloquent.php`
```php
interface AnimalRepositoryInterface
{
    public function findByGuid(string $guid): ?Animal;
    public function findManyByGuids(array $guids): Collection; // resolver selección existente al guardar un Program
    public function create(array $data): Animal;

    /**
     * Resuelve o crea un Animal para un rp dentro del scope de UN cliente (DEC-07, reglas 1 y 3).
     * Si ya existe una fila (client_id, rp), la reutiliza en vez de fallar por el unique constraint.
     * Si el mismo rp existe para OTRO cliente, no interfiere: crea una fila nueva propia de este cliente.
     */
    public function firstOrCreateForClient(int $clientId, string $rp): Animal;

    /**
     * Búsqueda para el picker de frontend (DEC-10). Match parcial sobre rp y name,
     * scoped SIEMPRE a un client_id — nunca se expone búsqueda cross-cliente.
     */
    public function searchForClient(int $clientId, ?string $search, int $limit = 20): Collection;
}
```
`firstOrCreateForClient` y `searchForClient` en la implementación Eloquent:
```php
public function firstOrCreateForClient(int $clientId, string $rp): Animal
{
    /** @var Animal */
    return $this->newQuery()->firstOrCreate(
        ['client_id' => $clientId, 'rp' => $rp],
        ['type' => 'livestock'],
    );
}

public function searchForClient(int $clientId, ?string $search, int $limit = 20): Collection
{
    return $this->newQuery()
        ->where('client_id', $clientId)
        ->when($search, fn ($q) => $q->where(fn ($q2) => $q2
            ->where('rp', 'like', "%{$search}%")
            ->orWhere('name', 'like', "%{$search}%")))
        ->orderBy('rp')
        ->limit($limit)
        ->get();
}
```
Binding análogo en `AppServiceProvider`.

### Service — orquestación

#### `back/app/Services/ProgramService.php`
```php
class ProgramService
{
    public function __construct(
        private ProgramRepositoryInterface $programRepository,
        private AnimalRepositoryInterface $animalRepository,
    ) {}

    public function paginateForVet(int $vetId, array $filters, int $perPage): LengthAwarePaginator;

    /** Detalle: adjunta la proyección de tareas/alertas (DEC-15) antes de retornar. */
    public function findByGuidForVet(string $guid, int $vetId): ?Program;

    /**
     * @param array $data {client_id, establishment_id, protocol_id (ints ya resueltos),
     *                     comments?, targets: [{target_date, animals?: [{id?: int, rp: string}]}],
     *                     manager_profile_ids: int[]}
     */
    public function create(array $data, int $vetId): Program;

    public function update(Program $program, array $data): Program;

    /** Marca cancelled_at = now(). No borra targets/animals (trazabilidad). */
    public function cancel(Program $program): Program;

    private function syncTargets(Program $program, array $targetsData): void;
    private function syncTargetAnimals(ProgramTarget $target, array $animalsData, int $clientId): void;

    /** DEC-15/DEC-16 (actualización): proyecta tareas/alertas por target, sin persistir nada. */
    private function projectTargetTasks(Program $program): void;
    private function applyOffset(\Carbon\Carbon|string $date, int $offsetDays, string $timeOfDay): \Carbon\Carbon;
}
```
Pseudocódigo `create` (dentro de `DB::transaction`):
1. `$targets = $data['targets']; unset($data['targets']);`
2. `$managerProfileIds = $data['manager_profile_ids']; unset($data['manager_profile_ids']);`
3. `$data['vet_id'] = $vetId;`
4. `$data['technique_id'] = Protocol::find($data['protocol_id'])->technique_id;` (DEC-06 — se ignora cualquier `technique_id` recibido)
5. `$program = $this->programRepository->create($data);`
6. `$this->syncTargets($program, $targets);` — crea 1 `ProgramTarget` por fila, y por cada una llama `syncTargetAnimals`.
7. `$program->managers()->sync($managerProfileIds);`
8. `return $program->fresh()->load('targets.animals', 'managers.user', 'client', 'establishment', 'technique', 'protocol');`

Pseudocódigo `update`: igual pero:
- Bloquea si `!$program->editable` → lanza `ProgramNotEditableException` (nueva, análoga a `ProtocolTechniqueLockedException`).
- `syncTargets` hace diff por `guid` (igual patrón que `ProtocolService::syncTasks`): targets sin guid = nuevos, targets con guid ausente en el payload = `delete()` (cascade borra pivot `program_target_animal`), pero **rechaza** dejar el programa en 0 targets — si el resultado final tendría 0 filas, lanza `ProgramMustHaveOneTargetException` ANTES de tocar la base (validar el payload completo primero, no a mitad de sync).
- `managers()->sync()` reemplaza todo el set (DEC-08).
- **No regenera alertas** (no existe el motor todavía — no hay nada que regenerar; no se replica el bug del legado porque simplemente no aplica).

Pseudocódigo `syncTargetAnimals($target, $animalsData, $clientId)` — contrato `{ id?: int (ya resuelto de guid), rp: string }` por DEC-07/DEC-10, con las 3 reglas de negocio confirmadas ya implementadas:
```php
private function syncTargetAnimals(ProgramTarget $target, array $animalsData, int $clientId): void
{
    $animalIds = [];

    foreach ($animalsData as $animalData) {
        if (!empty($animalData['id'])) {
            $animal = $this->animalRepository->findByGuid($animalData['id']);

            if ($animal && $animal->client_id === $clientId) {
                $animalIds[] = $animal->id;
            } else {
                // Regla 2 confirmada: el id no pertenece al client_id del programa (o no existe).
                // Se descarta SILENCIOSAMENTE — no se agrega al target, no se crea nada en su lugar,
                // no aborta el resto del guardado. Se loguea para debugging (no visible al usuario):
                // ej. el picker no se reseteó al cambiar de cliente, o el front mandó un id viejo.
                Log::warning('Program: animal id descartado, no pertenece al client_id del programa', [
                    'animal_guid' => $animalData['id'],
                    'client_id'   => $clientId,
                ]);
            }
            continue;
        }

        // Sin id = rp nuevo o existente DENTRO DE ESTE MISMO cliente (reglas 1 y 3 confirmadas):
        // firstOrCreateForClient reutiliza la fila si (client_id, rp) ya existe (evita el error de
        // unique constraint y un duplicado silencioso), y crea una fila nueva propia de este cliente
        // si el rp coincide con el de OTRO cliente (sin reusar cross-cliente, por diseño).
        $animal = $this->animalRepository->firstOrCreateForClient($clientId, $animalData['rp']);
        $animalIds[] = $animal->id;
    }

    $target->animals()->sync($animalIds);
}
```

`cancel()`: solo `update(['cancelled_at' => now()])`, sin transacción compleja (no hay alertas que borrar/crear en este alcance — eso es del spec de alertas, §6 explícitamente fuera de alcance).

Pseudocódigo `projectTargetTasks` (DEC-15/DEC-16, actualización — se llama SOLO desde `findByGuidForVet`, nunca desde `paginateForVet`):
```php
private function projectTargetTasks(Program $program): void
{
    $program->loadMissing('protocol.tasks.alerts', 'managers.role', 'managers.user');

    foreach ($program->targets as $target) {
        $target->projected_tasks = $program->protocol->tasks->map(function ($task) use ($target) {
            $taskDate = $this->applyOffset($target->target_date, $task->days_offset, $task->time_of_day);

            return [
                'protocol_task_guid' => $task->guid,
                'description'        => $task->description,
                'occurs_on'          => $taskDate->toDateString(),
                'occurs_at'          => $task->time,
                'important'          => $task->important,
                'alerts'             => $task->alerts->map(function ($alert) use ($taskDate, $program) {
                    $alertDate  = $this->applyOffset($taskDate, $alert->offset_days, $alert->time_of_day);
                    $recipients = $program->managers->filter(
                        fn ($m) => in_array($m->role->name, $alert->roles, true)
                    )->values();

                    return [
                        'protocol_task_alert_guid' => $alert->guid,
                        'occurs_on'                => $alertDate->toDateString(),
                        'occurs_at'                => $alert->time,
                        'roles'                    => $alert->roles,
                        'message'                  => $alert->message,
                        'require_confirmation'     => $alert->require_confirmation,
                        'recipients'               => $recipients->map(fn ($m) => [
                            'guid' => $m->guid, 'name' => $m->user->name, 'role' => $m->role->name,
                        ])->all(),
                    ];
                })->all(),
            ];
        })->all();
    }
}

private function applyOffset(\Carbon\Carbon|string $date, int $offsetDays, string $timeOfDay): \Carbon\Carbon
{
    $base = \Carbon\Carbon::parse($date);
    return $timeOfDay === 'after' ? $base->copy()->addDays($offsetDays) : $base->copy()->subDays($offsetDays);
}
```
`findByGuidForVet` llama a `projectTargetTasks($program)` antes de retornar, únicamente cuando el resultado no es `null`.

### Excepciones nuevas
- `back/app/Exceptions/ProgramNotEditableException.php` — análoga a `ProtocolTechniqueLockedException`, mensaje "El programa está cancelado y no puede editarse."
- `back/app/Exceptions/ProgramMustHaveOneTargetException.php` — mensaje "El programa debe tener al menos un objetivo."

### Form Requests

#### `back/app/Http/Requests/Programs/StoreProgramRequest.php`
```php
public function rules(): array
{
    return [
        'client_id'       => ['required', 'string', 'uuid', 'exists:clients,guid'],
        'establishment_id'=> ['required', 'string', 'uuid', 'exists:establishments,guid'],
        'protocol_id'     => ['required', 'string', 'uuid', 'exists:protocols,guid'],
        'comments'        => ['nullable', 'string'],
        'targets'                       => ['required', 'array', 'min:1'],
        'targets.*.target_date'        => ['required', 'date'],
        'targets.*.animals'             => ['nullable', 'array'],
        'targets.*.animals.*.id'        => ['nullable', 'string', 'uuid'], // guid de Animal existente (resuelto por el picker)
        'targets.*.animals.*.rp'        => ['required', 'string', 'max:50'], // viaja siempre (DEC-07), incluso para animales existentes
        'manager_profile_ids'           => ['required', 'array', 'min:1'],
        'manager_profile_ids.*'         => ['string', 'uuid'], // guids de UserProfile
    ];
}
```
`withValidator` (análogo a `StoreVetProtocolRequest`):
- Resuelve `client` con `ClientRepositoryInterface::findByGuidForVet($guid, $vet)` — si `null`, error en `client_id` ("El cliente no pertenece a esta veterinaria").
- Resuelve `establishment` y valida `establishment->client_id === $client->id`.
- Resuelve `protocol` y valida `protocol->vet_id === null || protocol->vet_id === $vet->id` (mismo criterio de visibilidad que `paginateForVet` de protocolos).
- Resuelve cada `manager_profile_ids[i]` vía `UserProfileRepositoryInterface` y valida que pertenece a esta vet **o al cliente del programa** (DEC-12, actualización — antes de la actualización solo se contemplaba "pertenece a esta vet"; ahora un manager puede ser un `UserProfile` con `authenticatable_type = 'client'` y `authenticatable_id = $client->id`, así que la validación es: pertenece a la vet (`findByGuidForVet`) **O** pertenece al cliente resuelto en este mismo request (`findByGuidForClient`) — si no matchea ninguno de los dos, 422).
- **`targets.*.animals.*.id` NO se valida acá** (a diferencia de `client_id`/`establishment_id`/`protocol_id`/`manager_profile_ids`, que sí rechazan con 422 si no pertenecen al tenant). Por decisión de negocio confirmada (DEC-07, regla 2), un `id` de animal que no pertenece al `client_id` del programa se descarta silenciosamente en el `ProgramService` (no es un error de validación) — ver `syncTargetAnimals`. Esto es intencional: NO agregar aquí una regla `exists`/`Rule::exists` con scope de cliente para este campo.

#### `back/app/Http/Requests/Programs/UpdateProgramRequest.php`
Mismas reglas + `targets.*.guid` opcional (`nullable, string, uuid` — presente = editar target existente, ausente = nuevo). `targets.*.animals.*.id` sigue la misma regla de "no se valida ownership acá" documentada arriba.

#### `back/app/Http/Requests/Programs/IndexProgramRequest.php`
```php
public function rules(): array
{
    return [
        'technique_id'     => ['nullable', 'string', 'uuid'],
        'client_id'        => ['nullable', 'string', 'uuid'],
        'establishment_id' => ['nullable', 'string', 'uuid'],
        'cancelled'        => ['nullable', 'boolean'],
        'search'           => ['nullable', 'string', 'max:255'],
        'per_page'         => ['nullable', 'integer', 'min:1', 'max:100'],
        'page'             => ['nullable', 'integer', 'min:1'],
    ];
}
```

#### `back/app/Http/Requests/Animals/IndexAnimalRequest.php` (nuevo, para el endpoint de búsqueda DEC-10)
```php
public function rules(): array
{
    return [
        'search' => ['nullable', 'string', 'max:100'],
    ];
}
```

### Resources

#### `back/app/Http/Resources/V1/ProgramTargetResource.php`
```php
[
    'guid'        => $this->guid,
    'target_date' => $this->target_date->toDateString(),
    'animals'     => $this->animals->map(fn ($a) => [
        'guid' => $a->guid, 'rp' => $a->rp, 'name' => $a->name,
    ]),
    // DEC-15/DEC-16 (actualización): presente SOLO cuando el Service anotó projected_tasks
    // (detalle, nunca listado). whenNotNull evita romper el listado si en algún momento
    // se reutiliza este Resource ahí.
    'tasks'       => $this->when(isset($this->projected_tasks), fn () => $this->projected_tasks),
]
```

#### `back/app/Http/Resources/V1/ProgramResource.php`
```php
[
    'guid'         => $this->guid,
    'client'       => ['guid' => $this->client->guid, 'name' => $this->client->name],
    'establishment'=> ['guid' => $this->establishment->guid, 'name' => $this->establishment->name],
    'technique'    => ['guid' => $this->technique->guid, 'name' => $this->technique->name],
    'protocol'     => ['guid' => $this->protocol->guid, 'name' => $this->protocol->name],
    'comments'     => $this->comments,
    'cancelled_at' => $this->cancelled_at?->toISOString(),
    'editable'     => $this->editable,
    'targets'      => ProgramTargetResource::collection($this->whenLoaded('targets')),
    'managers'     => $this->whenLoaded('managers', fn () => $this->managers->map(fn ($m) => [
        'guid'   => $m->guid,
        'name'   => $m->user->name,
        'role'   => $m->role->name,
        // DEC-16 (actualización): evita que el frontend duplique la lista de roles
        // vet vs. cliente que ya vive en UserProfileService::VET_STAFF_ROLES.
        'origin' => in_array($m->role->name, \App\Services\UserProfileService::VET_STAFF_ROLES, true) ? 'vet' : 'client',
    ])),
    'created_at'   => $this->created_at?->toISOString(),
    'updated_at'   => $this->updated_at?->toISOString(),
]
```

#### `back/app/Http/Resources/V1/ProgramListResource.php`
Shape afectado por decisión 6 del pedido (DEC-09):
```php
[
    'guid'             => $this->guid,
    'client'           => ['guid' => $this->client->guid, 'name' => $this->client->name],
    'establishment'    => ['guid' => $this->establishment->guid, 'name' => $this->establishment->name],
    'technique'        => ['guid' => $this->technique->guid, 'name' => $this->technique->name],
    'protocol'         => ['guid' => $this->protocol->guid, 'name' => $this->protocol->name],
    'cancelled_at'     => $this->cancelled_at?->toISOString(),
    'editable'         => $this->editable,
    'targets_count'    => $this->targets_count,
    'next_target_date' => $this->next_target_date, // calculado en repository/service, ver DEC-09
    'created_at'       => $this->created_at?->toISOString(),
]
```
Nota de implementación: `next_target_date` NO es una columna ni un accessor Eloquent nativo — se anota como atributo dinámico sobre cada modelo `Program` dentro de `ProgramRepositoryEloquent::paginateForVet()` (tras cargar `targets`, iterar la colección paginada y hacer `$program->next_target_date = ...`) antes de retornar el paginator. El Resource simplemente lee `$this->next_target_date`.

#### `back/app/Http/Resources/V1/AnimalListResource.php` (nuevo, para el endpoint de búsqueda DEC-10)
Shape mínimo para alimentar el `a-select`:
```php
[
    'guid' => $this->guid,
    'rp'   => $this->rp,
    'name' => $this->name,
]
```

### Controller

#### `back/app/Http/Controllers/V1/ProgramController.php`
Mismo patrón que `VetProtocolController`: `ApiResponseTrait`, recibe `current_vet` de `$request->attributes`, guid siempre.
```php
public function index(IndexProgramRequest $request): JsonResponse   // programs.read
public function store(StoreProgramRequest $request): JsonResponse   // programs.create
public function show(Request $request): JsonResponse                // programs.read
public function update(UpdateProgramRequest $request): JsonResponse  // programs.update
public function cancel(Request $request): JsonResponse              // programs.update (no hay permiso .delete: cancelar no es destructivo)
```
`resolveGuidsToIds()` privado: resuelve `client_id`, `establishment_id`, `protocol_id` (guid→id) antes de pasar al Service; resuelve `manager_profile_ids[]` (guid→id de `user_profiles`, ahora aceptando tanto perfiles de vet como de client — ver Form Requests actualizado) y `targets.*.animals.*.id` (guid→id de `Animal`, dejando `null` si no viene o no resuelve — el Service decide qué hacer con eso, ver `syncTargetAnimals`, que descarta silenciosamente si no pertenece al `client_id` del programa).

`update`/`cancel` capturan `ProgramNotEditableException` → 422 `{ reason: 'not_editable' }`; `update` también captura `ProgramMustHaveOneTargetException` → 422 `{ reason: 'must_have_one_target' }`.

#### `back/app/Http/Controllers/V1/AnimalController.php` (nuevo, para el endpoint de búsqueda DEC-10)
```php
class AnimalController extends Controller
{
    public function __construct(private AnimalService $animalService) {} // wrapper fino sobre AnimalRepositoryInterface, ver nota abajo

    public function indexForClient(IndexAnimalRequest $request): JsonResponse
    {
        try {
            $vet    = $request->attributes->get('current_vet');
            $client = $this->clientRepository->findByGuidForVet($request->route('client'), $vet);
            if (!$client) {
                return $this->makeNotFound('Cliente no encontrado.');
            }

            $animals = $this->animalService->searchForClient($client->id, $request->query('search'));

            return $this->makeSuccess(AnimalListResource::collection($animals));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
```
Nota: no hace falta un `AnimalService` completo para esto — el controller puede inyectar directamente `AnimalRepositoryInterface` y `ClientRepositoryInterface` (regla de "Service inyecta la Interface, nunca el Model" aplica a Services; para un endpoint de solo lectura tan chico, seguir el mismo criterio liviano que ya usa el proyecto en otros índices simples — confirmar contra un ejemplo real equivalente al implementar, ej. algún `IndexXRequest` + Controller sin Service intermedio si existe precedente).

### Rutas API

`back/routes/api/programs.php` — **ya existe en el repo** (verificado en exploración 2026-07-22), contenido idéntico al planeado:
```php
Route::prefix('v1/vets/{vet}/programs')->middleware(['auth:sanctum', 'vet.tenant'])->group(function () {
    Route::get('/', [ProgramController::class, 'index'])->middleware('can:programs.read');
    Route::post('/', [ProgramController::class, 'store'])->middleware('can:programs.create');
    Route::get('/{guid}', [ProgramController::class, 'show'])->middleware('can:programs.read');
    Route::put('/{guid}', [ProgramController::class, 'update'])->middleware('can:programs.update');
    Route::post('/{guid}/cancel', [ProgramController::class, 'cancel'])->middleware('can:programs.update');
});
```
Ya incluido en `back/routes/api.php`.

`back/routes/api/animals.php` — **ya existe en el repo** también:
```php
Route::prefix('v1/vets/{vet}/clients/{client}/animals')->middleware(['auth:sanctum', 'vet.tenant'])->group(function () {
    Route::get('/', [AnimalController::class, 'indexForClient'])->middleware('can:programs.read');
});
```
Nota de permiso: se reutiliza `programs.read` en vez de crear un permiso `animals.read` nuevo — este endpoint hoy solo existe para alimentar el picker de Programa (regla de "no crear entidades de más sin necesidad confirmada"); si en el futuro `Animal` gana un CRUD propio (HealthPlan), ahí sí se justifica un módulo de permisos `animals.*` separado.
Ya incluido en `back/routes/api.php`.

### Permisos Spatie

`back/database/seeders/ProgramPermissionsSeeder.php` — **ya existe en el repo** (verificado), contenido idéntico al planeado:
- Permisos: `programs.read`, `programs.create`, `programs.update` (sin `programs.delete` — no hay hard-delete de programas en este alcance, solo cancelación bajo `programs.update`).
- `super-admin`: `syncPermissions(Permission::all())` ya cubre esto automáticamente.
- Roles `vet`, `vet-administrative`, `vet-assistant`: `givePermissionTo()` con el set completo.
- Ya registrado en `DatabaseSeeder` después de `ProtocolPermissionsSeeder::class` (confirmar orden exacto al implementar el resto).
- No se agrega un seeder de permisos separado para el endpoint de animales (reutiliza `programs.read`).

### Contrato del endpoint

**POST /v1/vets/{vet}/programs** — request:
```json
{
  "client_id": "guid", "establishment_id": "guid", "protocol_id": "guid",
  "comments": "opcional",
  "targets": [{
    "target_date": "2026-08-01",
    "animals": [{"rp": "A123"}, {"id": "animal-guid-existente", "rp": "B456"}]
  }],
  "manager_profile_ids": ["user-profile-guid-1", "user-profile-guid-2"]
}
```
Response 201: `{ success: true, data: ProgramResource, message: "Programa creado correctamente." }`
Errores: 422 validación estándar Laravel; 422 `{ reason: 'must_have_one_target' }` (solo en update); 404 si `client`/`establishment`/`protocol` no resuelven al scope de la vet.

**GET /v1/vets/{vet}/programs** — query params: `technique_id?, client_id?, establishment_id?, cancelled?, search?, page?, per_page?`. Response: paginado con `ProgramListResource`.

**GET /v1/vets/{vet}/programs/{guid}** — Response: `ProgramResource` con `targets.animals`, `targets.tasks[].alerts[].recipients` (DEC-15/DEC-16) y `managers` (con `origin`) cargados.

**PUT /v1/vets/{vet}/programs/{guid}** — mismo shape que store + `targets.*.guid` opcional. 422 `{ reason: 'not_editable' }` si `cancelled_at !== null`.

**POST /v1/vets/{vet}/programs/{guid}/cancel** — sin body. Response: `ProgramResource` actualizado. 422 `{ reason: 'not_editable' }` si ya estaba cancelado (idempotencia: decidir devolver 422 en vez de 200 silencioso, para que el front no permita doble click sin feedback).

**GET /v1/vets/{vet}/clients/{client}/animals?search=** — (nuevo, DEC-10) query param `search?` (opcional, matchea `rp` y `name`). Response: `{ success: true, data: AnimalListResource[] }`, sin paginar (límite fijo de 20 resultados, suficiente para un dropdown de búsqueda). 404 si `client` no pertenece a la vet.

### Tests a generar

Backend (`back/tests/Unit/` + `back/tests/Feature/` si el proyecto usa Feature — confirmar carpeta real antes de escribir, mismo patrón que `TechniqueServiceTest.php`):
- `ProgramServiceTest`: crear con 1 target sin animales (caso simple N=1); crear con N targets con animales nuevos y existentes mezclados (payload `{id, rp}` y `{rp}` sueltos); update agregando/quitando targets; update que intentaría dejar 0 targets → excepción; update sobre programa cancelado → excepción; cancel marca `cancelled_at`; managers se sincronizan con `sync` (reemplazo total); **animal con `id` que NO pertenece al `client_id` del programa se descarta silenciosamente** (no se agrega al target, no lanza excepción, el resto del target se guarda igual — regla 2 confirmada); **`rp` nuevo sin `id` que ya existe para el MISMO cliente reutiliza la fila existente** (`firstOrCreateForClient`, no falla por el unique constraint); **`rp` nuevo sin `id` que coincide con el `rp` de OTRO cliente crea una fila nueva propia de este cliente** (regla 3 confirmada, no reusa cross-cliente).
- `ProgramRepositoryEloquentTest` (o cubierto en Service test con DB real): scope estricto por `vet_id` — un programa de otra vet no aparece ni es encontrable por guid; `next_target_date` calcula correctamente el más próximo no vencido y el fallback al "más próximo vencido" cuando todos vencieron.
- Form Request tests (o Feature test de endpoint): rechazo si `client_id` no pertenece a la vet; rechazo si `establishment_id` no pertenece al `client_id`; rechazo si `protocol_id` es de otra vet; rechazo si `targets` viene vacío; rechazo si `animals.*.rp` falta (ahora siempre requerido, DEC-07); **NO rechazo (200/201 normal) si `animals.*.id` no pertenece al cliente** — confirma que el descarte ocurre en el Service, no en el Request; rechazo si `manager_profile_ids` incluye un guid que no pertenece ni a la vet ni al cliente del programa (actualización, DEC-12).
- `AnimalRepositoryEloquentTest`: `findManyByGuids` filtra correctamente; `searchForClient` matchea por `rp` y por `name`, scoped estrictamente a `client_id` (un animal de otro cliente nunca aparece aunque el `rp` matchee); `firstOrCreateForClient` reutiliza fila existente del mismo cliente y crea fila nueva si el `rp` es de otro cliente; intento de `create()` directo (sin `firstOrCreate`) con `(client_id, rp)` duplicado lanza `QueryException` por el unique constraint (test de regresión de la migración).
- `AnimalControllerTest` (Feature, endpoint DEC-10): 404 si el `client` de la URL no pertenece a la vet autenticada; devuelve solo animales del cliente indicado; `search` filtra correctamente; límite de 20 resultados.
- `ProgramServiceTest::test_project_target_tasks_calcula_fechas_con_offset_before_y_after` (actualización, DEC-15) — cubre ambos signos de `time_of_day` en tarea y en alerta.
- `ProgramServiceTest::test_project_target_tasks_mapea_recipients_por_rol` (actualización, DEC-15) — un manager con rol `vet-assistant` aparece en alertas con `roles: ['vet-assistant']` y NO en alertas con `roles: ['client-owner']`.
- `TechniqueControllerTest` (o `TechniqueChildResourceTest` si se crea uno dedicado): `target_date_name` de una sub-técnica viaja en `children[].target_date_name` (actualización, DEC-13).

## Cambios en FRONTEND

> **Ver también la sección de actualización al final del documento** — reemplaza el enfoque de Drawer (`ProgramFormDrawer.vue`, `Páginas y router` originales de acá abajo) por una vista de página por secciones, y agrega la vista de detalle. Se deja el contenido original completo por trazabilidad; los tipos/composables/api que NO cambian (types base, api base, `useAnimalSearch`, etc.) siguen vigentes tal cual.

Módulo nuevo `front/src/modules/programs/`, mismas 8 sub-carpetas que `protocols`.

### `types/program.types.ts`
```ts
export interface ProgramTargetAnimalRef { guid: string; rp: string; name: string | null }
export interface ProgramTarget { guid?: string; target_date: string; animals: ProgramTargetAnimalRef[] }
export interface ProgramRef { guid: string; name: string }
export interface ProgramManagerRef { guid: string; name: string; role: string }

export interface ProgramListItem {
  guid: string
  client: ProgramRef
  establishment: ProgramRef
  technique: ProgramRef
  protocol: ProgramRef
  cancelled_at: string | null
  editable: boolean
  targets_count: number
  next_target_date: string | null
  created_at: string
}

export interface ProgramDetail extends Omit<ProgramListItem, 'targets_count' | 'next_target_date'> {
  comments: string | null
  targets: ProgramTarget[]
  managers: ProgramManagerRef[]
  updated_at: string
}

export interface ProgramListParams {
  technique_id?: string; client_id?: string; establishment_id?: string
  cancelled?: boolean; search?: string; page?: number; per_page?: number
}

// DEC-07/DEC-10: contrato de animal por target — reemplaza el AnimalInput { guid?, rp?, name? } de la versión anterior
export interface AnimalInput { id?: string; rp: string }
export interface ProgramTargetPayload { guid?: string; target_date: string; animals: AnimalInput[] }

export interface CreateProgramPayload {
  client_id: string; establishment_id: string; protocol_id: string
  comments: string | null
  targets: ProgramTargetPayload[]
  manager_profile_ids: string[]
}
export type UpdateProgramPayload = CreateProgramPayload

// Item devuelto por el endpoint de búsqueda de animales (DEC-10), consumido por el a-select
export interface AnimalOption { guid: string; rp: string; name: string | null }
```

### `validators/program.validator.ts`
```ts
// DEC-07: rp siempre requerido (viaja tanto para animales nuevos como para tags ya resueltos por el picker)
const animalInputSchema = z.object({
  id: z.string().uuid().optional(),
  rp: z.string().min(1, 'El RP no puede estar vacío').max(50),
})

export const programTargetSchema = z.object({
  guid: z.string().uuid().optional(),
  target_date: z.string().min(1, 'La fecha objetivo es requerida'),
  animals: z.array(animalInputSchema).default([]),
})

export const programSchema = z.object({
  client_id: z.string().uuid('Seleccioná un cliente'),
  establishment_id: z.string().uuid('Seleccioná un establecimiento'),
  protocol_id: z.string().uuid('Seleccioná un protocolo'),
  comments: z.string().nullable().optional().transform((v) => v ?? null),
  targets: z.array(programTargetSchema).min(1, 'Debe haber al menos un objetivo'),
  manager_profile_ids: z.array(z.string().uuid()).min(1, 'Seleccioná al menos un manager'),
})

export type ProgramFormValues = z.infer<typeof programSchema>
export type ProgramTargetFormValues = z.infer<typeof programTargetSchema>
export type AnimalInputFormValues = z.infer<typeof animalInputSchema>
```

### `api/program.api.ts`
Mismo patrón 1:1 que `vet-protocol.api.ts`: `listProgramsApi`, `getProgramApi`, `createProgramApi`, `updateProgramApi`, `cancelProgramApi(vetGuid, guid)` (POST sin payload).

### `api/animal.api.ts` (nuevo, DEC-10)
```ts
import { http } from '@/core/api/http'
import type { AnimalOption } from '../types/program.types'

export async function searchAnimalsApi(
  vetGuid: string,
  clientGuid: string,
  search: string,
  signal?: AbortSignal,
): Promise<AnimalOption[]> {
  const res = await http.get<AnimalOption[]>(
    `/v1/vets/${vetGuid}/clients/${clientGuid}/animals`,
    { params: { search }, signal },
  )
  return res.data
}
```

### `composables/`
- `useProgramList.ts` — `useQuery` sobre `listProgramsApi`, key `['programs', vetGuid, filters]`.
- `useProgramDetail.ts` — `useQuery` sobre `getProgramApi`, key `['program', vetGuid, guid]`.
- `useProgramMutations.ts` — `useCreateProgram`, `useUpdateProgram`, `useCancelProgram` (patrón idéntico a `useVetProtocolMutations.ts`, con `invalidateQueries` sobre ambas keys tras éxito).
- `useProgramTargetRepeater.ts` — calco de `useProtocolTaskRepeater.ts`: `add()`, `remove(index)`, `setItems()`, mínimo 1 fila siempre presente (al hacer `remove` sobre la última fila, no se elimina — se limpia y se deja vacía, o se deshabilita el botón "quitar" cuando `targets.length === 1`, decisión de UX a tomar por `ui-specialist` en implementación).
- `useAnimalSearch.ts` (nuevo, reemplaza el `useAnimalPicker.ts` pendiente de la versión anterior de este plan, DEC-10):
  ```ts
  import { ref } from 'vue'
  import { useDebounceFn } from '@vueuse/core' // confirmar que la lib ya está instalada; si no, implementar debounce manual con setTimeout
  import { searchAnimalsApi } from '../api/animal.api'
  import type { AnimalOption } from '../types/program.types'

  export function useAnimalSearch(vetGuid: () => string, clientGuid: () => string) {
    const options = ref<AnimalOption[]>([])
    const loading = ref(false)

    const search = useDebounceFn(async (value: string) => {
      if (!clientGuid()) { options.value = []; return }
      loading.value = true
      try {
        options.value = await searchAnimalsApi(vetGuid(), clientGuid(), value)
      } finally {
        loading.value = false
      }
    }, 300)

    function reset() {
      options.value = []
    }

    return { options, loading, search, reset }
  }
  ```
  Nota: `vetGuid`/`clientGuid` se pasan como funciones (getters) en vez de valores directos porque el `client_id` seleccionado en el form cambia dinámicamente — la búsqueda siempre debe usar el cliente actual del form, no uno capturado en el momento de crear el composable.

### Componentes
- `components/ProgramTargetFormItem.vue` — una fila del repeater: date input para `target_date` + selector de animales. **Reemplaza el input de texto tipo `animals_rp` legacy** por:
  ```vue
  <a-select
    v-model:value="selectedTags"
    mode="tags"
    :options="animalSearch.options.value.map(a => ({ label: a.rp + (a.name ? ` (${a.name})` : ''), value: a.guid }))"
    :filter-option="false"
    :loading="animalSearch.loading.value"
    placeholder="Buscar RP existente o escribir uno nuevo"
    @search="animalSearch.search"
    @change="handleAnimalsChange"
  />
  ```
  `handleAnimalsChange(values: string[])`: por cada valor seleccionado, si coincide con un `guid` presente en `animalSearch.options.value` (un resultado real de búsqueda), arma `{ id: guid, rp: <rp del option encontrado> }`; si el valor no matchea ningún `option.value` conocido (es un tag "libre" tipeado por el usuario), lo trata como RP nuevo: `{ rp: value }` (sin `id`). Este mapeo vive en el composable `useProgramTargetRepeater` o en el propio componente — a definir en implementación cuál es más testeable, pero **no** en el Zod schema (el schema solo valida shape final, no resuelve tags).
  - Al limpiar el cliente del form (o cambiarlo), se debe llamar `animalSearch.reset()` y vaciar `selectedTags` en todas las filas — un animal seleccionado bajo un cliente A no tiene sentido si el usuario cambia a cliente B a mitad de carga.
  - Nota de comportamiento (alineada con las reglas de negocio confirmadas): el frontend NO necesita advertir ni bloquear si el usuario escribe un `rp` que coincide con un animal de otro cliente — es un caso válido (regla 3, se crea un animal nuevo propio del cliente actual). Tampoco necesita manejar un error especial si un `id` quedó "stale" (regla 2, se descarta en el backend sin romper el guardado) — no hay contrato de error que mapear para ese caso.
- `components/ProgramTargetList.vue` — wrappea el repeater, botón "Agregar objetivo". **(Actualización: se reemplaza por `ProgramGroupsSection.vue`, mismo contenido interno, label visible "Grupos" — ver sección de actualización.)**
- `components/tenant/ProgramFormDrawer.vue` — **(Actualización: NO SE IMPLEMENTA. Reemplazado por `VetProgramFormPage.vue` de página completa por secciones, ver DEC-11.)**
- `components/tenant/ProgramsTable.vue` — `BaseDataTable`, columnas: cliente, establecimiento, técnica/protocolo, `next_target_date`, badge "N objetivos" si `targets_count > 1`, estado (badge "Cancelado" si `cancelled_at`), acciones (`BaseTableActions`). Acciones navegan por router (actualización), no abren Drawer/modal.
- `components/tenant/ProgramCancelModal.vue` — `BaseConfirmDialog` para cancelar (no hay `DeleteModal` porque no hay hard-delete). Sin cambios.

### Páginas y router
- `pages/tenant/VetProgramsListPage.vue` — calco de `VetProtocolsListPage.vue`.
- `router/vet-programs.routes.ts` — **ver versión extendida en la sección de actualización**, agrega rutas `new`, `:guid/edit`, `:guid` (detalle).

### Nav
Agregar item en `front/src/components/layouts/partials/VetMenu.vue`, dentro de `reproduccionNavItems` (mismo bloque "Reproducción" donde ya vive "Protocolos"), envuelto en `can('programs.read')` como ya hace el bloque con `protocols.read`.

### Managers picker
**(Actualización: resuelto — ver DEC-12. Se reutilizan `GET /v1/vets/{vet}/staff` y `GET /v1/vets/{vet}/clients/{client}/staff`, ya existentes en el backend, en vez de un picker nuevo de "managers".)**

## Orden de implementación

1. Migraciones (5 archivos, en el orden listado arriba — `animals` antes de `programs` porque `program_target_animal` depende de ambas, y `programs` antes de `program_targets`/`program_manager`). La migración de `animals` incluye el constraint `unique(client_id, rp)` confirmado.
2. Modelos: `Animal`, `Program`, `ProgramTarget` (con relaciones).
3. Excepciones: `ProgramNotEditableException`, `ProgramMustHaveOneTargetException`.
4. Repository interfaces + Eloquent: `AnimalRepositoryInterface`/Eloquent (incluyendo `searchForClient` y `firstOrCreateForClient`, DEC-10/DEC-07), `ProgramRepositoryInterface`/Eloquent (con eager-loads de `protocol.tasks.alerts` y `managers.role` en `findByGuidForVet`, actualización). Bindings en `AppServiceProvider`.
5. `ProgramService` (create/update/cancel/paginateForVet/findByGuidForVet + métodos privados de sync, contrato `{id?, rp}` por animal, con ownership silencioso y `firstOrCreateForClient` ya implementados + `projectTargetTasks`/`applyOffset`, actualización DEC-15).
6. Form Requests: `StoreProgramRequest`, `UpdateProgramRequest`, `IndexProgramRequest`, `IndexAnimalRequest` (con validación de `manager_profile_ids` contra vet O cliente, actualización DEC-12).
7. Resources: `ProgramTargetResource` (con `tasks` condicional), `ProgramResource` (con `managers[].origin`), `ProgramListResource`, `AnimalListResource`.
8. **Fix puntual**: agregar `target_date_name` a `TechniqueChildResource::toArray()` (actualización DEC-13 — 1 línea, sin migración).
9. Controllers + rutas: `ProgramController` (ya hay rutas creadas en `back/routes/api/programs.php`, confirmar que apuntan a los métodos correctos); `AnimalController` (rutas ya creadas en `back/routes/api/animals.php`). Confirmar registro en `api.php`.
10. `ProgramPermissionsSeeder` — ya existe en el repo, confirmar que está registrado en `DatabaseSeeder` en el orden correcto (el endpoint de animales reutiliza `programs.read`, no requiere seeder propio).
11. Tests backend (Service + Repository scope + Form Request + búsqueda de animales + las 3 reglas de negocio confirmadas sobre `rp`/`id` + proyección de tareas/alertas + `target_date_name` en hijo de técnica).
12. Frontend: types → validators → api (`program.api.ts` + `animal.api.ts`) → composables (incluyendo `useAnimalSearch`, `useVetStaffList`, `useClientStaffList`) → componentes por sección (`ProgramClientSection`, `ProgramTechniqueSection`, `ProgramGroupsSection`, `ProgramOtherDataSection`, `ProgramManagerCheckboxGroup`) → páginas (`VetProgramFormPage`, `VetProgramDetailPage`, `VetProgramsListPage`) → router → nav.
13. QA manual end-to-end: alta con 1 target sin animales, alta con 3 targets mezclando animales nuevos (RP tipeado libre) y existentes (seleccionados del picker), edición agregando/quitando target, intento de dejar 0 targets (debe rechazar), cancelación, verificar que un programa de otra vet no es visible/editable, verificar que la búsqueda de animales no devuelve animales de otro cliente ni de otra vet, verificar que un `rp` nuevo repetido en dos clientes distintos no genera conflicto, verificar que la vista de detalle muestra correctamente tareas/alertas proyectadas con los responsables correctos según rol, verificar que cambiar de cliente resetea establecimiento + responsables de cliente + animales seleccionados.

## Riesgos y consideraciones

- **Discrepancia doc vs. código en permisos (DEC-03)**: `.claude/skills/backend-conventions.md` documenta `{modulo}.lectura/alta/modificacion/baja`, pero el código real usa siempre `{modulo}.read/create/update/delete`. Se siguió el código. Recomendar actualizar el skill doc en una tarea aparte para que no vuelva a inducir a error.
- **`vet_id` en `programs` es una decisión de este plan, no del spec funcional**: el spec (§2.1) no lo lista. Se agregó por regla dura de multi-tenant (#4). Si en el futuro alguien re-lee el spec original y no encuentra `vet_id`, aclarar que está documentado acá (DEC-01).
- **[RESUELTO] Animales duplicados por RP**: el usuario confirmó la regla de negocio — `rp` es único POR CLIENTE (constraint `unique(client_id, rp)` en la migración de `animals`), no globalmente. Dos clientes distintos pueden compartir un mismo `rp` sin conflicto. Dentro del mismo cliente, un intento de crear un `rp` que ya existe se resuelve con `firstOrCreateForClient` (reutiliza la fila, no falla ni duplica). Este riesgo queda cerrado — ver DEC-07 y la migración de `animals`.
- **[RESUELTO] Tag libre que coincide por texto pero no fue resuelto por el picker**: sigue siendo posible que el usuario tipee manualmente un `rp` que ya existe para el mismo cliente sin haberlo seleccionado del picker (ej. escribe rápido y confirma antes de que responda el debounce) — pero ya NO es un riesgo de datos duplicados: gracias al constraint `unique(client_id, rp)` + `firstOrCreateForClient`, ese caso simplemente reutiliza el `Animal` existente en vez de crear un duplicado o fallar con un error de base de datos. El único efecto residual (no un bug) es que el animal termina asociado al target sin que el usuario haya visto explícitamente que ya existía — aceptable, no requiere más mitigación.
- **[RESUELTO] Animal `id` recibido que no pertenece al `client_id` del programa**: el usuario confirmó que el comportamiento correcto es descartar el item silenciosamente (no 422, no crear un animal "fantasma" en su lugar) — implementado en `ProgramService::syncTargetAnimals` con un log de auditoría (`Log::warning`) para debugging. El `StoreProgramRequest`/`UpdateProgramRequest` NO validan este campo (a propósito, ver Form Requests) — la única superficie de este comportamiento es el Service.
- **Concurrencia en cancelación**: `cancel()` no es idempotente (retorna 422 si ya estaba cancelado) — decisión tomada arriba, pero vale confirmarla con UX antes de implementar el modal de confirmación en el front (evitar doble feedback de error si el usuario hace doble click).
- **`protocol_id` de otra vet pero global (vet_id null)**: se permite (mismo criterio que protocolos: visibles = propios + globales). Confirmado alineado con `ProtocolRepositoryEloquent::paginateForVet`.
- **Sin motor de alertas**: al cancelar/editar un Program en este alcance NO se genera, regenera ni borra ninguna `Alert` — es intencional (fuera de alcance, spec de alertas aparte). Si QA prueba manualmente esperando notificaciones WhatsApp, van a fallar por diseño — no es un bug de esta implementación. **(Actualización: la vista de detalle SÍ muestra una proyección calculada de tareas/alertas — ver DEC-15 — pero es una vista de solo lectura/simulación, no dispara nada real. Ver riesgo específico en la sección de actualización.)**
- **Endpoint de búsqueda de animales sin permiso propio (`animals.*`)**: se decidió reutilizar `programs.read` (ver Rutas API) para no crear un módulo de permisos nuevo sin necesidad confirmada. Si `Animal` gana un CRUD independiente más adelante (HealthPlan), este endpoint probablemente debería migrar a un permiso `animals.read` propio — anotado para no perderlo de vista.
- **Dependencia de librería de debounce en frontend (`useDebounceFn` de `@vueuse/core`)**: verificar en implementación si el paquete ya está instalado en `front/package.json`; si no, implementar debounce manual con `setTimeout`/`clearTimeout` en el composable en vez de agregar una dependencia nueva solo para esto.

## Pendientes / fuera de alcance

- Motor de generación/envío de alertas (`Alert`, `ProgramTaskNotification`, `ProgramCreatedNotification`, `ProgramCanceledNotification`) — spec aparte. La proyección de DEC-15 es solo una vista de lectura, no reemplaza este motor.
- API de Client para Programa (solo lectura, según §3 del spec) — no incluida en este alcance (solo panel Vet).
- Confirmación de alertas (`POST /tasks/{alert}/complete`) — depende del motor de alertas.
- Campos de ficha veterinaria de `Animal` tipo `pet` (especie, raza, fecha de nacimiento, etc.) — spec de HealthPlan.
- CRUD completo de `Animal` (edición de `name`/`establishment_id` fuera del alta inline vía Programa) — no incluido; el endpoint de este plan es de solo lectura (búsqueda) más creación inline mínima (`client_id` + `rp`).

---

## Actualización — Ajustes de UX en alta/edición y detalle (pedido adicional del usuario, 2026-07-22)

Contexto de la exploración de código previa a estas decisiones: se confirmó que `Technique` (`back/app/Models/Technique.php` + migración `2026_06_23_000001_create_techniques_table.php`) ya tiene `target_date_name` y `protocols_name` como columnas propias de la tabla auto-referencial (root y children son filas de la misma tabla `techniques`, distinguidas por `parent_id`); que `TechniqueResource` (raíz) expone ambos campos pero `TechniqueChildResource` (hijos, consumido por `useTechniqueTree` en el front) solo expone `protocols_name` — gap real de código a corregir; que existen y ya devuelven el shape necesario los endpoints `GET /v1/vets/{vet}/staff` (`VetStaffController::index` + `UserProfileResource`) y `GET /v1/vets/{vet}/clients/{client}/staff` (`ClientStaffController::index`), ambos con `role.name` cargado; que `ProtocolTaskAlert.roles` es exactamente el mecanismo de mapeo rol→receptor que pide el punto B del pedido (regla dura #2 del dominio); y que ya existen en el repo `back/routes/api/programs.php`, `back/routes/api/animals.php` y `back/database/seeders/ProgramPermissionsSeeder.php` con el contenido planeado en DEC-04/DEC-10 originales (scaffolding ya aplicado, pendiente completar Service/Repository/Resources/Controllers detrás).

### Decisiones tomadas (actualización)

DEC-11 — Vista de alta/edición: página completa por secciones, no Drawer/modal
  Decisión: reemplazar el `ProgramFormDrawer.vue` (implícito en el plan original, patrón `BaseDrawer`) por una página nueva `VetProgramFormPage.vue`, con rutas propias `/vets/:vetGuid/programs/new` y `/vets/:vetGuid/programs/:guid/edit`. El formulario se organiza en 4 secciones visuales dentro de la misma página (sin wizard/steps, todo visible con separadores de sección), usando `a-form layout="vertical"`.
  Justificación: pedido explícito del usuario ("debe ser una vista nueva, no modal, formulario grande agrupado en secciones"). El formulario de Programa tiene sustancialmente más carga que el de Protocolo (cliente→establecimiento→responsables, técnica→subtécnica→protocolo, N grupos con fecha+animales, comentarios); un Drawer de ancho fijo no da el espacio necesario y compite visualmente con el listado.
  Alternativa descartada: mantener el Drawer con scroll interno — descartada explícitamente por el usuario.

DEC-12 — Responsables: se reutilizan los dos endpoints de staff ya existentes, sin endpoint combinado nuevo; UI en dos grupos de checkboxes independientes
  Decisión: NO se crea un endpoint nuevo "candidatos a manager". Se consumen tal cual:
  - `GET /v1/vets/{vet}/staff` → responsables de la vet.
  - `GET /v1/vets/{vet}/clients/{client}/staff` → responsables del cliente seleccionado (deshabilitado/vacío hasta que haya `client_id`).
  Ambos devuelven `UserProfileResource::collection` con `guid`, `user.name`, `role.name` — suficiente para dos `<a-checkbox-group>` independientes (Vet / Cliente), cada checkbox togglea individualmente (no es un `a-select` multiple).
  Justificación: exploración confirmó que ambos endpoints ya existen y devuelven exactamente el shape necesario. Crear un tercero que los combine duplicaría lógica sin beneficio, y el pedido exige EXPLÍCITAMENTE dos grupos separados en la UI — combinarlos en el backend obligaría a re-separarlos en el frontend de todas formas.
  Alternativa descartada: endpoint combinado `GET /vets/{vet}/clients/{client}/program-managers-candidates` — descartado por duplicación innecesaria y porque el requerimiento ya pide la separación visual, que el frontend puede lograr con dos queries en paralelo.
  Consecuencia en backend: `StoreProgramRequest`/`UpdateProgramRequest` deben validar que cada `manager_profile_ids[i]` pertenece a la vet **O** al cliente del programa (antes de esta actualización, la validación original solo contemplaba "pertenece a la vet" porque no se había explorado el caso de managers de cliente) — ver Form Requests actualizados arriba.
  Renombrado de label: el campo del contrato sigue siendo `manager_profile_ids` (no rompe DEC-08), pero el label visible cambia de "Managers" a "Responsables" — cambio de copy en frontend únicamente.
  Nota de UX: al cambiar de cliente, el checkbox-group "Cliente" se resetea (mismo criterio que `animalSearch.reset()` de DEC-10) — un responsable del cliente A queda checkeado inválidamente si el form pasa al cliente B.

DEC-13 — Labels dinámicos de "Fecha objetivo" y "Protocolo": requiere agregar `target_date_name` a `TechniqueChildResource` (gap de código real)
  Decisión:
  1. El label de "Protocolo" ya se puede resolver sin cambios de backend: `TechniqueChildResource` ya expone `protocols_name`. El frontend usa `subTechnique.protocols_name ?? 'Protocolo'` como label del campo de protocolo.
  2. El label de "Fecha objetivo" (label de cada grupo en la sección "Grupos") NO se puede resolver hoy: `TechniqueChildResource` expone `protocols_name` pero no `target_date_name`, pese a que la columna existe en la tabla `techniques` para cualquier fila (root o child) y ya está en `$fillable`. Se agrega `'target_date_name' => $this->target_date_name` a `TechniqueChildResource::toArray()` — cambio de una línea, sin migración (la columna ya existe), sin cambio de modelo. El frontend usa `subTechnique.target_date_name ?? 'Fecha objetivo'`.
  Justificación: hallazgo de exploración — el modelo y la migración de `Technique` ya soportan esto en ambos niveles desde que se implementó el módulo de técnicas; el único gap real es que `TechniqueChildResource` no serializaba `target_date_name`, solo lo hacía `TechniqueResource` (raíz). Como Programa usa la SUB-técnica (child) para `technique_id`, y es el child el que puede tener su propio valor distinto al de la raíz, el label debe leerse del child seleccionado, no de la raíz.
  Alternativa descartada: leer ambos labels siempre de la raíz — incorrecto, contradice el propio diseño de datos (si solo la raíz debiera definir esto, la columna no existiría también en los hijos).

DEC-14 — Cascada Cliente → Establecimiento → Responsables de cliente reutiliza endpoints ya existentes
  Decisión:
  - Cliente: `a-select` alimentado por `GET /v1/vets/{vet}/clients` (ya existe).
  - Establecimiento: `a-select` `disabled` hasta que haya `client_id`, alimentado por `GET /v1/vets/{vet}/clients/{client}/establishments` (ya existe).
  - Al cambiar de cliente: resetear `establishment_id`, resetear el checkbox-group "Responsables > Cliente" (DEC-12) y resetear animales seleccionados en los Grupos (`animalSearch.reset()`, DEC-10).
  Justificación: mismo patrón de cascada que ya usa `VetProtocolFormDrawer.vue` para técnica→subtécnica, aplicado acá a cliente→establecimiento. No requiere backend nuevo.

DEC-15 — Vista de detalle: tareas y alertas del programa se PROYECTAN en el Resource (cálculo de fechas + mapeo de roles a responsables), sin persistir nada
  Decisión: se agrega un método privado `ProgramService::projectTargetTasks(Program $program): void` (con helper `applyOffset`) que, a partir de `protocol.tasks` (con `days_offset`/`time_of_day`, regla dura #1) y de cada `ProgramTarget.target_date`, calcula la fecha concreta de cada tarea, y para cada `ProtocolTaskAlert` de esa tarea, la fecha/hora concreta de la alerta más la lista de `managers` cuyo `role.name` está incluido en `alert.roles` (regla dura #2). Se invoca únicamente desde `findByGuidForVet` (detalle), nunca desde `paginateForVet` (listado).
  Fórmula de fecha de tarea: `target_date` desplazada por `days_offset` días, sumando si `time_of_day === 'after'`, restando si `'before'`.
  Fórmula de fecha de alerta: fecha de tarea desplazada por `offset_days` de la alerta, mismo criterio de signo con su propio `time_of_day`.
  Mapeo de receptores: `$program->managers->filter(fn ($m) => in_array($m->role->name, $alert->roles))` — reutiliza `managers()` (DEC-08 original, pivot `program_manager`→`user_profiles`), cargando `role`.
  Justificación: el punto B del pedido ("mapear los receptores de cada alerta con los Responsables seleccionados según el rol asignado a la alerta") es exactamente el mecanismo ya documentado en la regla dura #2 del dominio (`ProtocolAlert.roles` = array de nombres de rol tenant) — no hace falta un modelo nuevo de `Alert`/`Notification`, es una proyección de solo lectura sobre datos que ya existen (`protocol.tasks.alerts` + `program.managers.role`). Esto NO amplía el alcance "sin motor de alertas" (DEC-02 original): seguimos sin persistir ninguna `Alert`, sin `delivered_at`, sin envío real.
  Alternativa descartada: materializar filas reales de `ProgramTask`/`ProgramAlert` en base al crear/editar el Program — equivaldría a empezar a construir el motor de alertas de facto (con los problemas de invalidación al editar fechas) sin que el spec de alertas esté cerrado. El pedido solo exige "ver" la relación tarea→alerta→receptor, no persistirla.

DEC-16 — Shape del detalle: `ProgramResource` gana `targets[].tasks[]` proyectado y `managers[].origin`; `ProgramListResource` no cambia
  Decisión: `ProgramTargetResource` (usado solo dentro de `ProgramResource`, nunca en `ProgramListResource`) agrega un campo `tasks` (proyección de DEC-15) condicionado a que el Service haya anotado `projected_tasks` sobre el modelo en memoria — mismo mecanismo que `next_target_date` de DEC-09 original. `ProgramResource.managers[]` gana `origin: 'vet' | 'client'`, calculado en PHP contra `UserProfileService::VET_STAFF_ROLES` (ya existe como constante pública), para que el frontend no tenga que duplicar esa lista de roles al renderizar la sección "Responsables" del detalle.
  Justificación: mantiene la separación de DEC-09 (el listado no carga relaciones pesadas ni computa proyecciones, el detalle sí, porque se pide 1 vez).

### Cambios adicionales en BACKEND (de esta actualización)

#### Archivos a modificar

##### `back/app/Http/Resources/V1/TechniqueChildResource.php`
**Cambio:** agregar `target_date_name` al array de salida (DEC-13).
**Antes:** `['guid' => ..., 'name' => ..., 'protocols_name' => ...]`
**Después:** `['guid' => ..., 'name' => ..., 'protocols_name' => ..., 'target_date_name' => $this->target_date_name]`

##### `back/app/Http/Resources/V1/ProgramTargetResource.php`
**Cambio:** agregar `'tasks' => $this->when(isset($this->projected_tasks), fn () => $this->projected_tasks)` (DEC-15/DEC-16).

##### `back/app/Http/Resources/V1/ProgramResource.php`
**Cambio:** en `managers[]`, agregar `'origin' => in_array($m->role->name, \App\Services\UserProfileService::VET_STAFF_ROLES, true) ? 'vet' : 'client'`.

##### `back/app/Services/ProgramService.php`
**Cambio:** agregar métodos privados `projectTargetTasks(Program $program): void` y `applyOffset(...): Carbon` (ver pseudocódigo completo en la sección "Service — orquestación" de arriba, ya integrado). `findByGuidForVet` los invoca antes de retornar.

##### `back/app/Repositories/ProgramRepositoryEloquent.php`
**Cambio:** `findByGuidForVet` agrega `protocol.tasks.alerts` y `managers.role`, `managers.user` al eager-load (antes solo cargaba `targets.animals`, `managers.user`, `client`, `establishment`, `technique`, `protocol`).

##### `back/app/Http/Requests/Programs/StoreProgramRequest.php` y `UpdateProgramRequest.php`
**Cambio:** en `withValidator`, la validación de `manager_profile_ids[i]` pasa de "pertenece a esta vet" a "pertenece a esta vet O al `client` resuelto en este mismo request" (usa `UserProfileService::findByGuidForVet` y `UserProfileService::findByGuidForClient`, ambos ya existentes).

### Tests adicionales (de esta actualización)

- `ProgramServiceTest::test_project_target_tasks_calcula_fechas_con_offset_before_y_after`.
- `ProgramServiceTest::test_project_target_tasks_mapea_recipients_por_rol`.
- `TechniqueControllerTest` (o dedicado): `target_date_name` viaja en `children[]` del árbol de técnicas.
- Form Request test: `manager_profile_ids` acepta un guid de `client-owner`/`client-manager` del cliente del programa (antes solo aceptaba staff de vet); rechaza un guid de staff de OTRO cliente o de OTRA vet.

### Cambios adicionales en FRONTEND (reemplazan/extienden lo descripto en "Cambios en FRONTEND")

#### Páginas y router
- `pages/tenant/VetProgramFormPage.vue` (nueva, reemplaza `ProgramFormDrawer.vue` — DEC-11). Prop/route-based `mode: 'create' | 'edit'`.
- `pages/tenant/VetProgramDetailPage.vue` (nueva, punto B del pedido).
- `router/vet-programs.routes.ts` extendido:
```ts
export const vetProgramsRoutes: RouteRecordRaw[] = [
  { path: '/vets/:vetGuid/programs', name: 'vet-programs-list', component: () => import('@/modules/programs/pages/tenant/VetProgramsListPage.vue'), meta: { requiresAuth: true, title: 'Programas' } },
  { path: '/vets/:vetGuid/programs/new', name: 'vet-programs-new', component: () => import('@/modules/programs/pages/tenant/VetProgramFormPage.vue'), meta: { requiresAuth: true, title: 'Nuevo programa' } },
  { path: '/vets/:vetGuid/programs/:guid/edit', name: 'vet-programs-edit', component: () => import('@/modules/programs/pages/tenant/VetProgramFormPage.vue'), meta: { requiresAuth: true, title: 'Editar programa' } },
  { path: '/vets/:vetGuid/programs/:guid', name: 'vet-programs-detail', component: () => import('@/modules/programs/pages/tenant/VetProgramDetailPage.vue'), meta: { requiresAuth: true, title: 'Detalle de programa' } },
]
```
`ProgramsTable.vue`: las acciones "Editar"/"Ver" navegan por router en vez de abrir Drawer/modal.

#### Componentes nuevos (secciones del formulario, DEC-11)
- `components/tenant/form-sections/ProgramClientSection.vue` — Cliente + Establecimiento (cascada, DEC-14) + Responsables (dos `<a-checkbox-group>`, DEC-12).
- `components/tenant/form-sections/ProgramTechniqueSection.vue` — Técnica (raíz) + Tipo de programa (subtécnica) + Protocolo, cascada calcada de `VetProtocolFormDrawer.vue` (`useTechniqueTree`), label de Protocolo dinámico (`subTechnique.protocols_name`, DEC-13).
- `components/tenant/form-sections/ProgramGroupsSection.vue` — reemplaza `ProgramTargetList.vue`: label visible "Grupos" (nota de nomenclatura abajo), internamente reutiliza `useProgramTargetRepeater` y `ProgramTargetFormItem.vue` sin cambios de lógica, con label de fecha dinámico (`technique.target_date_name`, DEC-13).
- `components/tenant/form-sections/ProgramOtherDataSection.vue` — Comentarios.
- `components/tenant/ProgramManagerCheckboxGroup.vue` — átomo compartido por ambos bloques de responsables: props `options: {guid, label}[]`, `modelValue: string[]`, emite `update:modelValue`. Se instancia dos veces (staff de vet / staff de cliente) en vez de duplicar markup.

**Nota de nomenclatura**: DEC-07/DEC-09 originales llaman "targets" al dato/contrato (`ProgramTarget`, campo `targets` del payload) — esto NO cambia, no se renombra el modelo ni el payload. Solo cambia el LABEL visible en la UI ("Grupos"), pedido explícito del usuario. Documentar este mapeo dato↔label en un comentario del componente `ProgramGroupsSection.vue` para que no confunda a otro dev.

#### Composables nuevos
- `composables/useVetStaffList.ts` — `useQuery(['vet-staff', vetGuid], () => listVetStaffApi(vetGuid))`. Verificar antes de crear si ya existe un `listVetStaffApi` reutilizable en `front/src/modules/vets` (no duplicar).
- `composables/useClientStaffList.ts` — `useQuery(['client-staff', vetGuid, clientGuid], () => listClientStaffApi(vetGuid, clientGuid), { enabled: computed(() => !!clientGuid.value) })`. Verificar si ya existe `listClientStaffApi` en `front/src/modules/clients` (no duplicar).
- `useProgramDetail.ts` (ya existía en el plan original): el shape que retorna ahora incluye `targets[].tasks[]` (DEC-16), reflejado en los types de abajo.

#### Types (extensión de `types/program.types.ts`)
```ts
export interface ProgramAlertRecipient { guid: string; name: string; role: string }
export interface ProgramProjectedAlert {
  protocol_task_alert_guid: string
  occurs_on: string
  occurs_at: string
  roles: string[]
  message: string
  require_confirmation: boolean
  recipients: ProgramAlertRecipient[]
}
export interface ProgramProjectedTask {
  protocol_task_guid: string
  description: string
  occurs_on: string
  occurs_at: string
  important: boolean
  alerts: ProgramProjectedAlert[]
}
// ProgramTarget (ya existente) gana un campo opcional, solo presente en detalle:
export interface ProgramTarget {
  guid?: string
  target_date: string
  animals: ProgramTargetAnimalRef[]
  tasks?: ProgramProjectedTask[]
}
// ProgramManagerRef (ya existente) gana origin:
export interface ProgramManagerRef { guid: string; name: string; role: string; origin: 'vet' | 'client' }
```

#### `pages/tenant/VetProgramDetailPage.vue` — contenido (punto B del pedido)
- Header: cliente, establecimiento, técnica/subtécnica, protocolo, estado (badge cancelado/activo), comentarios.
- Bloque "Responsables": lista de nombre + rol + origen (`vet`/`client`, ya viene resuelto por el backend — DEC-16, evita duplicar `VET_STAFF_ROLES` en el frontend).
- Bloque "Grupos": por cada `ProgramTarget`, fecha + animales (RP) + lista/tabla de `tasks` con sus `alerts` anidadas, cada alerta mostrando `occurs_on`/`occurs_at`, `message`, y chips de `recipients` (nombre + rol).
- Sin edición inline: botón "Editar" navega a `vet-programs-edit`; botón "Cancelar programa" abre `ProgramCancelModal.vue` (sin cambios respecto al plan original).

### Riesgos y consideraciones (adicionales de esta actualización)

- **`origin` en `ProgramResource.managers[]` depende de una constante de otra capa** (`UserProfileService::VET_STAFF_ROLES`): se lee estáticamente (`public const`), no requiere inyectar el Service completo — aceptable, pero si esa lista de roles cambia en el futuro (nuevo rol de cliente/vet), recordar que `Program` depende de ella indirectamente para calcular `origin`.
- **Confusión potencial dato vs. label ("Grupos" vs. "targets")**: el contrato de API sigue llamándose `targets`, pero la UI dice "Grupos". Documentado en el nombre/comentario de `ProgramGroupsSection.vue` para que no confunda a un dev nuevo que lea el código.
- **La proyección de tareas/alertas (DEC-15) no es el motor de alertas real**: si QA prueba esperando que las fechas proyectadas disparen un envío real de WhatsApp/email, va a fallar por diseño — es una vista de simulación/preview de solo lectura, no hay persistencia ni disparo.
- **`target_date_name` faltante en `TechniqueChildResource` es un gap real de código encontrado en esta exploración**, no una tarea pendiente de otro módulo — se corrige en este mismo plan (DEC-13).
- **Los endpoints de staff reutilizados (DEC-12) no filtran por "rol relevante para alertas"**: devuelven TODO el staff de la vet/cliente, incluyendo roles que quizás nunca aparezcan en ningún `ProtocolAlert.roles` (ej. `client-administrative`). Se acepta: el checkbox permite seleccionar cualquiera, la relevancia real la determina si algún alerta del protocolo elegido incluye ese rol — no es responsabilidad de Programa pre-filtrar quién "puede" ser responsable.
