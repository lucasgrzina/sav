# Plan técnico: ABM de Protocolos (Panel Tenant Vet)

## Input procesado

Brief informal del usuario (sin spec/ticket previo). Contexto obligatorio: `.claude/docs/plans/protocolos-superadmin-abm-plan.md` (ABM SuperAdmin ya implementado), código real de `back/app/Models/Protocol.php`, `back/app/Services/ProtocolService.php`, `back/app/Repositories/ProtocolRepositoryEloquent.php`, `back/routes/api/protocols.php`, `back/routes/api/vets.php`, `back/routes/api/clients.php`, `back/routes/api/techniques.php`, `back/app/Http/Middleware/EnsureUserBelongsToVet.php`, `back/app/Providers/AppServiceProvider.php` (Gate::before tenant), `back/database/seeders/RoleSeeder.php`, `front/src/modules/protocols/**`, `front/src/modules/clients/**` (patrón tenant de referencia), `front/src/components/layouts/partials/VetMenu.vue`, `front/src/modules/vets/composables/useVetTenant.ts`, `front/src/core/composables/usePermissions.ts`.

## Resumen ejecutivo

Se agrega un ABM de protocolos propio del panel tenant vet, bajo `v1/vets/{vet}/protocols`, reutilizando el 90% del backend ya construido para SuperAdmin (`Protocol`, `ProtocolTask`, `ProtocolTaskAlert`, `ProtocolService`, `ProtocolRepositoryEloquent`, FormRequests, Resources) con dos extensiones puntuales: un método de listado con scope `vet_id IS NULL OR vet_id = :vetId` y una corrección al método de listado admin que hoy NO excluye protocolos de vets (bug real encontrado en el código, ver DEC-08). El bloqueo de editar/eliminar protocolos ajenos se resuelve en el `VetProtocolController` con un chequeo de ownership que devuelve 404 (no 403), siguiendo el patrón "no es tuyo → no existe" ya usado en el resto del panel tenant (`ClientController`/`EstablishmentController` resuelven todo dentro del scope de `{vet}` de la URL, nunca devuelven 403 por pertenencia — el 403 queda reservado para permisos Spatie). "Crear versión" reutiliza el mismo endpoint `show` (guid del protocolo global) para precarga y un nuevo endpoint de creación (`POST .../protocols/{sourceGuid}/version`) que llama a la misma lógica de `ProtocolService::replicate()` ya existente (usada hoy por SuperAdmin para "duplicar"), parametrizada con `vet_id` y `created_by_type = 'vet'`. En frontend se agrega un módulo nuevo de páginas tenant (`front/src/modules/protocols/pages/tenant/`) y un componente de formulario propio (`VetProtocolFormDrawer.vue`) que NO reutiliza `ProtocolFormDrawer.vue` tal cual (ese está acoplado a una técnica raíz con hijos, patrón admin embebido en tab), pero sí reutiliza `ProtocolTaskList`/`ProtocolAlertList`/`protocolSchema`/tipos existentes. Se agrega el grupo de menú "Reproducción" en `VetMenu.vue` detrás de `protocols.read`.

## Decisiones tomadas

**DEC-01 — Rutas: `v1/vets/{vet}/protocols`, mismo prefijo tenant que `clients`/`vets`/`contacts`.**
Decisión: se agrega un bloque nuevo dentro de `back/routes/api/protocols.php`, `Route::prefix('v1/vets/{vet}/protocols')->middleware(['auth:sanctum', 'vet.tenant'])`, análogo al patrón de `clients.php`/`vets.php` (grupo `v1/vets/{vet}` + middleware `vet.tenant`). El controller resuelve la vet actual vía `$request->attributes->get('current_vet')`, exactamente como `VetController::show()`/`update()` — NO se re-implementa resolución de tenant, se usa `EnsureUserBelongsToVet` tal cual está.
Alternativa descartada: anidar bajo `v1/vets/{vet}/techniques/{guid}/protocols` — se descarta porque el requerimiento no pide agrupar por técnica raíz en el panel vet (a diferencia del admin, que vive embebido en el tab de una técnica); un listado plano con filtro opcional de técnica es más simple y evita forzar al vet a navegar por técnica para ver todos sus protocolos.

**DEC-02 — Permisos: se reutilizan los mismos nombres `protocols.read/create/update/delete`, sin prefijo distinto.**
Decisión: confirmado en código (`AppServiceProvider::boot()`, `Gate::before`) que el mismo nombre de permiso se resuelve contra el rol de `current_profile` (tenant) cuando existe, y contra los roles Spatie del `User` (plataforma) cuando no — es exactamente el mecanismo que ya usa `clients.*` para admin Y tenant simultáneamente (ver `RoleSeeder.php` líneas 65-104, `vets.php`/`clients.php` reusan `can:clients.read`, etc., tanto en el grupo admin como en el grupo tenant). No hay colisión posible: en el panel admin no hay `current_profile` (no pasa por `vet.tenant`), así que el Gate cae al chequeo Spatie normal del rol `super-admin`.
Alternativa descartada: `vet-protocols.read` con prefijo distinto — se descarta porque rompe el patrón ya establecido en todo el proyecto (mismo nombre, dos guards de resolución) y no aporta ningún beneficio real, solo duplicación de seeders.

**DEC-03 — Repository: un método nuevo `paginateForVet()` en el `ProtocolRepositoryEloquent` compartido, NO un repository/service propio de tenant.**
Decisión: se agrega `paginateForVet(int $vetId, array $filters, int $perPage): LengthAwarePaginator` al mismo `ProtocolRepositoryInterface`/`ProtocolRepositoryEloquent` que ya usa SuperAdmin, y se reutiliza `ProtocolService` (create/update/destroy/replicate/simulate) inyectando `vet_id` en los datos en vez de duplicar toda la clase. Los métodos de escritura (`create`, `update`, `destroy`) YA son genéricos sobre `Protocol` — no conocen si el llamador es admin o vet, solo reciben el modelo/datos ya resueltos. Esto es consistente con cómo el resto del proyecto resuelve tenant vs admin: mismo Service, distinto Controller que arma los datos según el contexto (ver `VetController`/`AdminVetController` ambos usando `VetService`).
Alternativa descartada: `VetProtocolService` propio — se descarta porque duplicaría `syncTasks()`/`syncAlerts()`/`countProgramsForProtocol()` (soft-delete cascada, stub de `programs`) palabra por palabra; el único código realmente distinto es "cómo se arma el query de listado" y "quién puede tocar qué fila", que caben perfectamente en el Repository (query) y el Controller (ownership check), sin tocar el Service.

**DEC-04 — Ownership de editar/eliminar: 404, no 403, siguiendo el patrón tenant existente.**
Decisión: se agrega `ProtocolRepositoryInterface::findByGuidForVet(string $guid, int $vetId): ?Protocol` (scoped por `vet_id = :vetId`, NO incluye protocolos globales). El `VetProtocolController::update()`/`destroy()` llaman a este método en vez de `findByGuidWithTasks()`; si retorna `null` (porque no existe, o porque existe pero es de otro vet, o porque es global) se responde `404 Protocolo no encontrado`, sin distinguir el motivo. Esto replica el patrón ya usado en `ClientController`/`EstablishmentController` (recursos siempre resueltos dentro del scope de `{vet}` de la URL — un establishment de otro client jamás se resuelve, ni siquiera con 403, directamente no aparece). El panel admin usa `findByGuidWithTasks()` (sin scope) porque allí no hay concepto de "ajeno".
Alternativa descartada: 403 con mensaje "no te pertenece" — se descarta porque revelaría la existencia del recurso (fuga de información sobre protocolos de otras vets, aunque sean poco sensibles) y porque ningún módulo tenant existente en el proyecto usa ese patrón; se prioriza consistencia con el código real sobre preferencia estética.

**DEC-05 — "Crear versión": reutiliza `ProtocolService::replicate()`, no una lógica nueva.**
Decisión: `replicate()` ya existe (agregado en una iteración posterior al plan admin original, confirmado en el código real de `ProtocolService.php`) y hace exactamente lo que pide el requerimiento: clona tasks+alerts completos vía `mapTasksForClone()` + `create()`, generando un protocolo NUEVO (nunca un update). Se extiende su firma a `replicate(Protocol $source, int $createdById, ?int $vetId = null): Protocol` — cuando `$vetId` es no nulo, el `create()` interno persiste `vet_id = $vetId`, `created_by_type = 'vet'`, `country_id = null` (ver DEC-07); cuando es nulo (uso admin actual, "duplicar"), el comportamiento no cambia. El controller de vet expone esto como `POST /v1/vets/{vet}/protocols/{guid}/version`, donde `{guid}` es el guid del protocolo ORIGEN (global o propio) — el flujo de precarga en frontend usa el mismo `GET /v1/vets/{vet}/protocols/{guid}` (show) para traer tasks/alerts, y al confirmar el formulario llama a este endpoint nuevo en vez de al de creación genérica, para que el nombre resuelva colisiones con `resolveReplicateName()` tal como ya hace `replicate()` (sufijo automático si el nombre choca).
Nota importante: el requerimiento dice "el flujo de creación de versión funciona como edición (precarga) pero al guardar crea un protocolo nuevo". Esto NO es un update-then-fork con los valores editados por el usuario en el medio — es clonar el protocolo original tal cual (sin permitir edición previa) y después el vet edita la copia ya creada. Se resuelve así porque es el comportamiento que ya provee `replicate()` sin cambios de lógica; si el negocio pidiera "clonar con los cambios ya aplicados en un solo submit" habría que fusionar el payload editado con la clonación, lo cual el usuario explícitamente no pidió ("no fue pedido, no lo agregues si no es necesario" aplica también acá por extensión) — queda como ambigüedad resuelta a favor de la opción más simple y ya soportada por el código.
Alternativa descartada: endpoint nuevo `POST .../protocols` con un campo `source_protocol_id` en el payload, donde el frontend arma el payload completo (tasks/alerts editados) igual que un create normal — más flexible (permite editar antes de guardar la versión) pero exige duplicar en frontend toda la lógica de "cargar tasks/alerts del original en el form" que ya vive en `ProtocolFormDrawer`/`VetProtocolFormDrawer` para edición; se prefiere la ruta más simple alineada a lo pedido explícitamente.

**DEC-06 — NO se agrega `source_protocol_id` a la tabla `protocols`.**
Decisión: el usuario fue explícito ("no fue pedido, no lo agregues si no es necesario"). No hay ningún RF que pida trazabilidad de qué protocolo global originó una versión, y agregarlo implica una migración + FK + columna sin uso real hoy. Si en el futuro se pide trazabilidad, se puede agregar como migración incremental (`nullable`, FK `nullOnDelete` a `protocols.id`) sin romper nada existente.
Alternativa descartada: agregarlo "por las dudas" — descartado explícitamente por el usuario y por buen criterio (YAGNI).

**DEC-07 — Protocolos creados por vet: `country_id` siempre `null`, no seleccionable en el formulario.**
Decisión: el concepto de `country_id` en `protocols` existe para decidir la visibilidad de un protocolo GLOBAL entre países distintos (capa plantilla, DEC-01 del plan admin). Un protocolo con `vet_id` no nulo ya está acotado a una única vet (que pertenece a un único país) — el campo país no aporta ninguna decisión de negocio adicional en ese caso. Se persiste `country_id = null` de forma automática al crear/versionar desde el panel vet, y el campo NO se expone en `VetProtocolFormDrawer.vue`. Esto también simplifica DEC-08 (índice único) al no tener que combinar dos columnas nullable con reglas de negocio superpuestas.
Alternativa descartada: `country_id = vet.country_id` automático — se descarta porque no hay ningún requerimiento de filtrado/reporting por país para protocolos de vet, y mantenerlo en `null` es más simple de razonar (evita que alguien filtre accidentalmente protocolos de vet por país esperando el comportamiento "global" del admin).

**DEC-08 — Bug real encontrado: `ProtocolRepositoryEloquent::paginateByRootTechnique()` (admin) NO excluye protocolos de vet. Se corrige en este plan.**
Decisión: el método usado hoy por `AdminProtocolController::index()` filtra solo por `whereIn('technique_id', $childIds)` — una vez que existan protocolos con `vet_id` no nulo, el listado admin (que debe ser SOLO la capa plantilla global) los mostraría mezclados con los globales. Se agrega `->whereNull('vet_id')` a este método como parte de este plan (no es responsabilidad de un ticket aparte porque el bug se vuelve real recién ahora que se habilita la escritura de `vet_id`; hasta este plan siempre era `null` por construcción y el bug era inofensivo).
Alternativa descartada: dejarlo para un ticket de seguimiento — se descarta porque introducir esta feature sin el fix deja el panel admin roto desde el día uno de este plan, no es una mejora futura sino una regresión directa causada por este cambio.

**DEC-09 — Unicidad de nombre: `existsDuplicate()` se extiende con `vetId` y la migración agrega `vet_id` al índice único compuesto.**
Decisión: mismo razonamiento que DEC-01 del plan admin (MySQL trata cada `NULL` como distinto en índices únicos). Se migra el índice único existente `protocols_technique_country_name_unique (technique_id, country_id, name)` a `protocols_technique_country_vet_name_unique (technique_id, country_id, vet_id, name)` — sin este cambio, dos vets DISTINTAS no podrían crear un protocolo con el mismo nombre en la misma sub-técnica (porque `country_id` es `null` en ambas por DEC-07, y el índice actual sin `vet_id` las trataría como si fueran el mismo registro potencial, aunque en MySQL con NULLs distintos en realidad el índice actual SÍ las dejaría duplicar sin querer detectarlo — el problema real es al revés: sin `vet_id` en el índice, la validación de aplicación (`existsDuplicate`) es la única que puede prevenir duplicados con criterio de negocio correcto). Se agrega el parámetro `?int $vetId` a `existsDuplicate()`, con el mismo patrón de rama nullable ya usado para `country_id` (`$vetId === null ? whereNull('vet_id') : where('vet_id', $vetId)`), y se llama con `vetId: null` desde los FormRequests admin (comportamiento sin cambios) y con `vetId: $vet->id` desde los FormRequests/validación tenant.
Alternativa descartada: no tocar el índice DB y confiar solo en la validación de aplicación — se descarta porque el índice actual, SIN `vet_id`, sí puede producir un falso positivo de "constraint violation" a nivel DB si dos vets distintas (ambas con `country_id = null` por DEC-07) crean el mismo `technique_id` + `name`: MySQL con dos columnas nullable (`country_id` y ahora también necesitaría `vet_id` para diferenciarlas) evaluaría (`technique_id`, `NULL`, `'mismo nombre'`) como el MISMO conjunto de valores para ambas filas si `vet_id` no está en el índice → el índice actual SÍ bloquearía por error un caso completamente legítimo (dos vets con protocolos homónimos). Este es un bug latente adicional que DEC-09 corrige.

**DEC-10 — Rol `vet-assistant`: recibe el set completo (`read/create/update/delete`), igual que `vet`.**
Decisión: confirmado explícitamente con el usuario — a diferencia del criterio de solo-lectura aplicado a `clients.*`, en protocolos `vet-assistant` opera con los mismos permisos que `vet`. `vet`, `vet-assistant` y `vet-administrative` reciben el set completo de `protocols.*`.
Alternativa descartada: replicar el criterio solo-lectura de `clients.*` — se descarta porque el usuario pidió expresamente equiparar `vet-assistant` a `vet` en este módulo, rompiendo la consistencia con `clients.*` a propósito.

**DEC-11 — Frontend: NO se reutiliza `ProtocolFormDrawer.vue` tal cual; se crea `VetProtocolFormDrawer.vue` reutilizando sus piezas internas.**
Decisión: `ProtocolFormDrawer.vue` recibe `technique: Technique` (una única raíz con sus `children`) como prop porque vive embebido en el tab de una técnica específica (`TechniqueDetailPage.vue`). El panel vet no tiene ese contexto — el vet debe poder elegir la técnica desde CERO entre todas las existentes (`GET /v1/techniques`, endpoint YA existente y sin uso actual en frontend, confirmado por Grep — pensado justamente para este caso: "Retorna jerarquía completa (raíces + hijos) para el selector de técnicas del vet"). Se crea `VetProtocolFormDrawer.vue` con un selector de dos niveles (técnica raíz → sub-técnica) alimentado por `listTechniquesApi()`, pero reutiliza sin cambios `ProtocolTaskList.vue`, `ProtocolAlertList.vue`, `protocolSchema`/`protocolTaskSchema`/`protocolTaskAlertSchema` (Zod), y los tipos de `protocol.types.ts` (agregando solo los tipos específicos de vet listados abajo).
Alternativa descartada: modificar `ProtocolFormDrawer.vue` para aceptar `techniques: Technique[]` en vez de `technique: Technique` — se descarta porque cambiaría el contrato de un componente ya en producción usado por el panel admin (`TechniqueProtocolsTab.vue`), obligando a tocar código que funciona y no forma parte de este requerimiento, por una feature (selector de dos niveles) que el admin no necesita.

**DEC-12 — Menú: grupo "Reproducción" en `VetMenu.vue`, ítem único "Protocolos", detrás de `protocols.read`.**
Decisión: se agrega una nueva sección de menú (mismo patrón que "Veterinaria"/"Soporte" ya existentes en `VetMenu.vue`: `dash-nav-section` + lista de `dash-nav-item`), condicionada por `can('protocols.read')` (mismo composable `usePermission()` ya usado para el link "Volver al admin"). El nombre del grupo es literalmente "Reproducción" (pedido explícito del usuario), no "Protocolos" — el ítem de navegación dentro del grupo sí se llama "Protocolos" porque es lo que identifica al módulo.
Alternativa descartada: meter el link dentro de la sección "Veterinaria" existente — se descarta porque el usuario pidió explícitamente un grupo nuevo llamado "Reproducción", probablemente anticipando que más adelante se agreguen ahí otros módulos relacionados (programas, alertas).

## Cambios en BACKEND

### Migrations

#### `back/database/migrations/{timestamp}_alter_protocols_unique_index_add_vet_id.php`
```php
Schema::table('protocols', function (Blueprint $table) {
    $table->dropUnique('protocols_technique_country_name_unique');
    $table->unique(['technique_id', 'country_id', 'vet_id', 'name'], 'protocols_technique_country_vet_name_unique');
});
```
Sin cambios de columnas (DEC-09) — `vet_id` ya existe (nullable, FK a `vets`) desde la migración original de `protocols`.

### Archivos a modificar

#### `back/app/Contracts/Repositories/ProtocolRepositoryInterface.php`
**Cambio:** agregar 2 firmas nuevas.
**Después:**
```php
public function paginateForVet(int $vetId, array $filters, int $perPage): LengthAwarePaginator;
public function findByGuidForVet(string $guid, int $vetId): ?Protocol; // con tasks.alerts eager-loaded
```
Y actualizar la firma existente:
```php
public function existsDuplicate(int $techniqueId, ?int $countryId, string $name, ?int $vetId = null, ?string $excludeGuid = null): bool;
```

#### `back/app/Repositories/ProtocolRepositoryEloquent.php`
**Cambio 1 — fix DEC-08:** `paginateByRootTechnique()` agrega `->whereNull('vet_id')`.
**Antes:**
```php
$query = $this->newQuery()
    ->whereIn('technique_id', $childIds)
    ->with('technique:id,guid,name', 'country:id,guid,name')
    ->withCount('tasks');
```
**Después:**
```php
$query = $this->newQuery()
    ->whereIn('technique_id', $childIds)
    ->whereNull('vet_id') // capa plantilla: NUNCA protocolos propios de una vet (ver DEC-08)
    ->with('technique:id,guid,name', 'country:id,guid,name')
    ->withCount('tasks');
```

**Cambio 2 — nuevo método `paginateForVet()`:**
```php
public function paginateForVet(int $vetId, array $filters, int $perPage): LengthAwarePaginator
{
    $query = $this->newQuery()
        ->where(fn ($q) => $q->whereNull('vet_id')->orWhere('vet_id', $vetId))
        ->with('technique:id,guid,name')
        ->withCount('tasks');

    if (!empty($filters['technique_guid'])) {
        $query->whereHas('technique', fn ($q) => $q->where('guid', $filters['technique_guid']));
    }
    if (!empty($filters['search'])) {
        $query->where('name', 'like', '%' . $filters['search'] . '%');
    }

    return $query->orderBy('name')->paginate($perPage);
}
```
Nota: sin `country:id,guid,name` en el eager load — el frontend de vet no necesita mostrar país (DEC-07), y sin filtro `country_guid` en `$filters` (no aplica en este contexto).

**Cambio 3 — nuevo método `findByGuidForVet()`:**
```php
public function findByGuidForVet(string $guid, int $vetId): ?Protocol
{
    /** @var Protocol|null */
    return $this->newQuery()
        ->with(['technique', 'tasks.alerts'])
        ->where('guid', $guid)
        ->where('vet_id', $vetId) // scope estricto: nunca resuelve protocolos globales ni de otra vet
        ->first();
}
```

**Cambio 4 — `existsDuplicate()` agrega rama `vet_id`:**
**Antes:**
```php
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
```
**Después:** agregar parámetro `?int $vetId = null` (después de `$name`, antes de `$excludeGuid` para no romper el orden con el que ya lo llaman los FormRequests admin — ver nota abajo) y una rama análoga a `country_id`:
```php
public function existsDuplicate(int $techniqueId, ?int $countryId, string $name, ?int $vetId = null, ?string $excludeGuid = null): bool
{
    $query = $this->newQuery()->where('technique_id', $techniqueId)->where('name', $name);

    $countryId === null ? $query->whereNull('country_id') : $query->where('country_id', $countryId);
    $vetId === null ? $query->whereNull('vet_id') : $query->where('vet_id', $vetId);

    if ($excludeGuid) {
        $query->where('guid', '!=', $excludeGuid);
    }

    return $query->exists();
}
```
**IMPORTANTE:** esta firma cambia de posición de parámetros — hay que actualizar las 2 llamadas existentes en `StoreProtocolRequest`/`UpdateProtocolRequest` (admin) agregando `vetId: null` explícito (named argument, ver abajo) para no romper el llamado por `$excludeGuid` posicional.

#### `back/app/Http/Requests/Protocols/StoreProtocolRequest.php` y `UpdateProtocolRequest.php` (admin, existentes)
**Cambio:** actualizar las 2 llamadas a `existsDuplicate()` para pasar `vetId: null` explícito (named argument), dado el cambio de firma.
**Antes:** `$this->protocolRepository->existsDuplicate($technique->id, $country?->id, (string) $this->input('name'))`
**Después:** `$this->protocolRepository->existsDuplicate($technique->id, $country?->id, (string) $this->input('name'), vetId: null)`
(y en `UpdateProtocolRequest`, el llamado con `$currentGuid` pasa a `existsDuplicate($technique->id, $country?->id, (string) $this->input('name'), vetId: null, excludeGuid: $currentGuid)`).

#### `back/app/Services/ProtocolService.php`
**Cambio 1:** nuevo método público de listado para vet.
```php
public function paginateForVet(int $vetId, array $filters, int $perPage): LengthAwarePaginator
{
    return $this->protocolRepository->paginateForVet($vetId, $filters, $perPage);
}
```

**Cambio 2:** nuevo método de resolución con ownership.
```php
public function findByGuidForVet(string $guid, int $vetId): ?Protocol
{
    return $this->protocolRepository->findByGuidForVet($guid, $vetId);
}
```

**Cambio 3:** `create()` acepta `vetId` opcional en vez de forzar `null` siempre.
**Antes:**
```php
public function create(array $data, int $createdById): Protocol
{
    return DB::transaction(function () use ($data, $createdById) {
        $tasks = $data['tasks'] ?? [];
        unset($data['tasks']);

        $data['created_by_type'] = 'superadmin';
        $data['created_by_id']   = $createdById;
        $data['vet_id']          = null;
        ...
```
**Después:**
```php
public function create(array $data, int $createdById, ?int $vetId = null): Protocol
{
    return DB::transaction(function () use ($data, $createdById, $vetId) {
        $tasks = $data['tasks'] ?? [];
        unset($data['tasks']);

        $data['created_by_type'] = $vetId !== null ? 'vet' : 'superadmin';
        $data['created_by_id']   = $createdById;
        $data['vet_id']          = $vetId;
        if ($vetId !== null) {
            $data['country_id'] = null; // DEC-07: país no aplica a protocolos propios de vet
        }
        ...
```

**Cambio 4:** `replicate()` acepta `vetId` opcional (DEC-05).
**Antes:**
```php
public function replicate(Protocol $protocol, int $createdById): Protocol
{
    $protocol->loadMissing('tasks.alerts');

    $data = [
        'technique_id' => $protocol->technique_id,
        'country_id'   => $protocol->country_id,
        'name'         => $this->resolveReplicateName($protocol),
        'color'        => $protocol->color,
        'tasks'        => $this->mapTasksForClone($protocol->tasks),
    ];

    return $this->create($data, $createdById);
}
```
**Después:**
```php
public function replicate(Protocol $protocol, int $createdById, ?int $vetId = null): Protocol
{
    $protocol->loadMissing('tasks.alerts');

    $data = [
        'technique_id' => $protocol->technique_id,
        'country_id'   => $vetId !== null ? null : $protocol->country_id,
        'name'         => $this->resolveReplicateName($protocol, $vetId),
        'color'        => $protocol->color,
        'tasks'        => $this->mapTasksForClone($protocol->tasks),
    ];

    return $this->create($data, $createdById, $vetId);
}
```

**Cambio 5:** `resolveReplicateName()` y `isDuplicateName()` propagan `vetId`.
**Antes:**
```php
public function isDuplicateName(int $techniqueId, ?int $countryId, string $name, ?string $excludeGuid = null): bool
{
    return $this->protocolRepository->existsDuplicate($techniqueId, $countryId, $name, $excludeGuid);
}
...
private function resolveReplicateName(Protocol $protocol): string
{
    $candidate = $protocol->name . ' (copia)';
    $suffix = 2;
    while ($this->isDuplicateName($protocol->technique_id, $protocol->country_id, $candidate)) {
        ...
```
**Después:**
```php
public function isDuplicateName(int $techniqueId, ?int $countryId, string $name, ?int $vetId = null, ?string $excludeGuid = null): bool
{
    return $this->protocolRepository->existsDuplicate($techniqueId, $countryId, $name, $vetId, $excludeGuid);
}
...
private function resolveReplicateName(Protocol $protocol, ?int $vetId = null): string
{
    $candidate = $protocol->name . ' (copia)';
    $suffix = 2;
    $scopeCountryId = $vetId !== null ? null : $protocol->country_id;
    while ($this->isDuplicateName($protocol->technique_id, $scopeCountryId, $candidate, $vetId)) {
        $candidate = $protocol->name . " (copia {$suffix})";
        $suffix++;
    }
    return $candidate;
}
```

**Cambio 6:** `update()` — al llamar `existsDuplicate`/`isDuplicateName` en el flujo de vet (ver `VetProtocolService`... no existe, ver DEC-03 — esto se valida en `UpdateVetProtocolRequest`, no en el `Service`), no requiere cambios adicionales en `update()` propiamente (ya recibe `Protocol $protocol` con `vet_id` ya persistido, y no toca ese campo).

#### `back/app/Http/Controllers/V1/AdminProtocolController.php`
**Cambio:** ninguno funcional — actualizar el llamado a `create`/`replicate` para pasar explícitamente sin `vetId` no es necesario porque el parámetro es opcional con default `null` y las llamadas actuales no lo pasan.

#### `back/routes/api/protocols.php`
**Cambio:** agregar bloque tenant nuevo, después del bloque admin existente.
**Después (agregar):**
```php
use App\Http\Controllers\V1\VetProtocolController;

// Panel Tenant Vet — capa "propia" + lectura de capa plantilla global
Route::prefix('v1/vets/{vet}/protocols')->middleware(['auth:sanctum', 'vet.tenant'])->group(function () {
    Route::get('/', [VetProtocolController::class, 'index'])->middleware('can:protocols.read');
    Route::post('/', [VetProtocolController::class, 'store'])->middleware('can:protocols.create');
    Route::post('/{guid}/version', [VetProtocolController::class, 'createVersion'])->middleware('can:protocols.create');
    Route::get('/{guid}', [VetProtocolController::class, 'show'])->middleware('can:protocols.read');
    Route::put('/{guid}', [VetProtocolController::class, 'update'])->middleware('can:protocols.update');
    Route::delete('/{guid}', [VetProtocolController::class, 'destroy'])->middleware('can:protocols.delete');
});
```
Nota: `{guid}` en `show` puede ser un protocolo GLOBAL o propio (para precarga de "crear versión" o edición propia); `update`/`destroy` solo resuelven contra `findByGuidForVet()` (scope estricto, DEC-04).

#### `back/database/seeders/ProtocolPermissionsSeeder.php`
**Cambio:** agregar asignación de permisos a roles tenant (mismo seeder, no se toca `RoleSeeder.php` para evitar el problema de orden — `RoleSeeder` corre ANTES que `ProtocolPermissionsSeeder` en `DatabaseSeeder`, así que los permisos deben asignarse desde acá, donde las `Role` filas ya existen).
**Después (agregar al final de `run()`):**
```php
$vet = Role::where('name', 'vet')->first();
$vet?->givePermissionTo(Permission::whereIn('name', $permissions)->get());

$vetAdmin = Role::where('name', 'vet-administrative')->first();
$vetAdmin?->givePermissionTo(Permission::whereIn('name', $permissions)->get());

$vetAssistant = Role::where('name', 'vet-assistant')->first();
$vetAssistant?->givePermissionTo(Permission::where('name', 'protocols.read')->get());
```
Se usa `givePermissionTo()` (no `syncPermissions()`) para no pisar permisos de otros módulos ya asignados a esos roles por `RoleSeeder` (`clients.*`, `tutorials.read`, etc.) — `syncPermissions()` reemplaza TODO el set, `givePermissionTo()` es aditivo.

### Archivos a crear

#### `back/app/Http/Controllers/V1/VetProtocolController.php`
```php
class VetProtocolController extends Controller
{
    public function __construct(private ProtocolService $protocolService) {}

    public function index(IndexVetProtocolRequest $request): JsonResponse
    {
        try {
            $vet = $request->attributes->get('current_vet');
            $filters = [
                'technique_guid' => $request->query('technique_id'),
                'search'         => $request->query('search'),
            ];
            $perPage   = $request->integer('per_page', 15);
            $paginator = $this->protocolService->paginateForVet($vet->id, $filters, $perPage);

            return $this->makeSuccessPagination($paginator, VetProtocolListResource::class);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function store(StoreVetProtocolRequest $request): JsonResponse
    {
        try {
            $vet  = $request->attributes->get('current_vet');
            $data = $this->resolveGuidsToIds($request->validated());
            $protocol = $this->protocolService->create($data, $request->user()->id, $vet->id);

            return $this->makeSuccess(new VetProtocolResource($protocol), 'Protocolo creado correctamente.', 201);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    /** Precarga: show funciona sobre CUALQUIER protocolo visible (global o propio). */
    public function show(Request $request, string $guid): JsonResponse
    {
        try {
            $vet = $request->attributes->get('current_vet');
            $protocol = $this->protocolService->findByGuidWithTasks($guid);

            if (!$protocol || ($protocol->vet_id !== null && $protocol->vet_id !== $vet->id)) {
                return $this->makeNotFound('Protocolo no encontrado.');
            }

            return $this->makeSuccess(new VetProtocolResource($protocol));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    /** "Crear versión" (DEC-05): clona $guid (global o propio) como protocolo NUEVO de esta vet. */
    public function createVersion(Request $request, string $guid): JsonResponse
    {
        try {
            $vet = $request->attributes->get('current_vet');
            $source = $this->protocolService->findByGuidWithTasks($guid);

            if (!$source || ($source->vet_id !== null && $source->vet_id !== $vet->id)) {
                return $this->makeNotFound('Protocolo no encontrado.');
            }

            $version = $this->protocolService->replicate($source, $request->user()->id, $vet->id);

            return $this->makeSuccess(new VetProtocolResource($version), 'Versión creada correctamente.', 201);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function update(UpdateVetProtocolRequest $request, string $guid): JsonResponse
    {
        try {
            $vet = $request->attributes->get('current_vet');
            $protocol = $this->protocolService->findByGuidForVet($guid, $vet->id);
            if (!$protocol) {
                return $this->makeNotFound('Protocolo no encontrado.');
            }

            $data = $this->resolveGuidsToIds($request->validated());
            $protocol = $this->protocolService->update($protocol, $data);

            return $this->makeSuccess(new VetProtocolResource($protocol), 'Protocolo actualizado correctamente.');
        } catch (ProtocolTechniqueLockedException $e) {
            return $this->makeError(['reason' => 'technique_locked', 'count' => $e->getCount()], $e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function destroy(Request $request, string $guid): JsonResponse
    {
        try {
            $vet = $request->attributes->get('current_vet');
            $protocol = $this->protocolService->findByGuidForVet($guid, $vet->id);
            if (!$protocol) {
                return $this->makeNotFound('Protocolo no encontrado.');
            }

            $this->protocolService->destroy($protocol);

            return $this->makeSuccess(null, 'Protocolo eliminado correctamente.');
        } catch (ProtocolHasProgramsException $e) {
            return $this->makeError(['reason' => 'has_programs', 'count' => $e->getCount()], $e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    private function resolveGuidsToIds(array $data): array
    {
        $data['technique_id'] = Technique::where('guid', $data['technique_id'])->value('id');
        unset($data['country_id']); // DEC-07: nunca se persiste desde el panel vet
        return $data;
    }
}
```

#### `back/app/Http/Requests/Protocols/IndexVetProtocolRequest.php`
```php
public function rules(): array
{
    return [
        'technique_id' => ['nullable', 'string', 'uuid'],
        'search'       => ['nullable', 'string', 'max:255'],
        'page'         => ['nullable', 'integer', 'min:1'],
        'per_page'     => ['nullable', 'integer', 'min:1', 'max:100'],
    ];
}
```
Nota: sin `root_guid` obligatorio (a diferencia de `IndexProtocolRequest` admin) — el panel vet lista TODOS sus protocolos visibles sin requerir contexto de técnica raíz (DEC-01).

#### `back/app/Http/Requests/Protocols/StoreVetProtocolRequest.php`
Igual a `StoreProtocolRequest` (mismas reglas de `tasks.*`/`tasks.*.alerts.*`) MENOS `country_id` (no aplica, DEC-07) MÁS: en `withValidator`, la validación de duplicado usa `$vet->id` (resuelto desde `$request->attributes->get('current_vet')`, inyectado vía `Request $request` en el constructor igual que `Country`/`Technique` se resuelven hoy):
```php
public function __construct(private ProtocolRepositoryInterface $protocolRepository) { parent::__construct(); }

public function rules(): array
{
    $tenantRoles = ['vet', 'vet-assistant', 'vet-administrative', 'client-owner', 'client-manager', 'client-administrative'];
    return [
        'technique_id' => ['required', 'string', 'uuid', 'exists:techniques,guid'],
        'name'         => ['required', 'string', 'max:255'],
        'color'        => ['nullable', 'string', 'max:20'],
        // sin country_id
        'tasks'                        => ['nullable', 'array'],
        'tasks.*.description'         => ['required', 'string'],
        'tasks.*.days_offset'         => ['required', 'integer', 'between:0,365'],
        'tasks.*.time_of_day'         => ['required', Rule::in(['before', 'after'])],
        'tasks.*.time'                => ['required', 'date_format:H:i'],
        'tasks.*.important'           => ['nullable', 'boolean'],
        'tasks.*.alerts'              => ['nullable', 'array'],
        'tasks.*.alerts.*.offset_days' => ['nullable', 'integer', 'between:0,365'],
        'tasks.*.alerts.*.time_of_day' => ['nullable', Rule::in(['before', 'after'])],
        'tasks.*.alerts.*.time'        => ['required', 'date_format:H:i'],
        'tasks.*.alerts.*.roles'       => ['required', 'array', 'min:1'],
        'tasks.*.alerts.*.roles.*'     => [Rule::in($tenantRoles)],
        'tasks.*.alerts.*.message'     => ['required', 'string'],
        'tasks.*.alerts.*.require_confirmation' => ['nullable', 'boolean'],
    ];
}

public function withValidator(ValidatorContract $validator): void
{
    $validator->after(function (ValidatorContract $v) {
        $technique = Technique::where('guid', $this->input('technique_id'))->first();
        if (!$technique) return;
        if ($technique->parent_id === null) {
            $v->errors()->add('technique_id', 'El protocolo debe asociarse a una sub-técnica, nunca a la técnica raíz.');
            return;
        }
        $vet = $this->attributes->get('current_vet'); // Request::$attributes, disponible tras vet.tenant
        if ($this->protocolRepository->existsDuplicate($technique->id, null, (string) $this->input('name'), $vet->id)) {
            $v->errors()->add('name', 'Ya tenés un protocolo con este nombre para esta sub-técnica.');
        }
    });
}
```
Mensajes de error: mismos textos que `StoreProtocolRequest`, sin las claves de `country_id`.

#### `back/app/Http/Requests/Protocols/UpdateVetProtocolRequest.php`
Igual a `StoreVetProtocolRequest` + `tasks.*.guid`/`tasks.*.alerts.*.guid` nullable uuid (mismo patrón que `UpdateProtocolRequest` admin), excluyendo el propio guid del chequeo de duplicado (`$this->route('guid')`) y validando que la nueva `technique_id` comparta la misma raíz que la técnica actual del protocolo (mismo bloque que `UpdateProtocolRequest`, pero resolviendo `$current` vía `findByGuidForVet($currentGuid, $vet->id)` en vez de `findByGuid()` — así la validación también actúa como segunda barrera de ownership, aunque el controller ya bloquea antes de llegar acá).

#### `back/app/Http/Resources/V1/VetProtocolListResource.php`
```php
public function toArray(Request $request): array
{
    return [
        'guid'        => $this->guid,
        'name'        => $this->name,
        'color'       => $this->color,
        'technique'   => ['guid' => $this->technique->guid, 'name' => $this->technique->name],
        'is_global'   => $this->vet_id === null,
        'is_own'      => $this->vet_id !== null, // true = fue creado/versionado por esta vet, false = capa plantilla
        'tasks_count' => $this->tasks_count ?? 0,
        'created_at'  => $this->created_at?->toISOString(),
    ];
}
```
Nota: `is_own` se calcula sin comparar explícitamente contra el vet actual porque el query de `paginateForVet()` YA garantiza que cualquier fila con `vet_id` no nulo pertenece al vet autenticado (nunca trae filas de otras vets) — no hace falta pasar el `vetId` al Resource.

#### `back/app/Http/Resources/V1/VetProtocolResource.php`
Igual a `VetProtocolListResource` + `updated_at` + `tasks` (`ProtocolTaskResource::collection($this->whenLoaded('tasks'))`, reutilizado tal cual del módulo admin — mismo shape de tarea/alerta, no depende de si es global o propio).

### Contrato del endpoint

**`GET /v1/vets/{vet}/protocols?technique_id=&search=&page=&per_page=`**
Response 200: paginación estándar con `VetProtocolListResource[]` (incluye globales + propios de `{vet}`).

**`POST /v1/vets/{vet}/protocols`**
Request: igual al admin sin `country_id`.
Response 201: `VetProtocolResource` con `is_global: false`, `is_own: true`.

**`POST /v1/vets/{vet}/protocols/{guid}/version`**
`{guid}` = protocolo origen (global o propio). Sin body.
Response 201: `VetProtocolResource` del protocolo NUEVO (`is_own: true`), con nombre resuelto por `resolveReplicateName()` (sufijo "(copia)" si choca).
Errores: `404` si `{guid}` no existe o es de otra vet.

**`GET /v1/vets/{vet}/protocols/{guid}`**
`{guid}` = cualquier protocolo visible (global o propio). Uso: precarga de edición (propio) o precarga de "crear versión" (global o propio).
Errores: `404` si `{guid}` es de otra vet.

**`PUT /v1/vets/{vet}/protocols/{guid}`** — SOLO protocolos propios.
Errores: `404` si `{guid}` es global o de otra vet (DEC-04); `422` `{reason: 'technique_locked', count}` igual que admin.

**`DELETE /v1/vets/{vet}/protocols/{guid}`** — SOLO protocolos propios.
Errores: `404` si `{guid}` es global o de otra vet; `422` `{reason: 'has_programs', count}` igual que admin.

### Tests a generar

- `ProtocolRepositoryEloquentTest`: `paginateByRootTechnique()` excluye filas con `vet_id` no nulo (regresión DEC-08); `paginateForVet()` trae globales + propios de la vet dada, excluye protocolos de otra vet; `findByGuidForVet()` retorna `null` para protocolo global y para protocolo de otra vet; `existsDuplicate()` con `vetId` distinto no colisiona (dos vets, mismo nombre+técnica, ambas `country_id null` → ambas deben poder crear).
- `ProtocolServiceTest`: `create()` con `vetId` no nulo persiste `created_by_type = 'vet'` y `country_id = null` aunque se pase uno; `replicate()` con `vetId` no nulo crea protocolo nuevo con `vet_id` seteado y NO modifica el original (assert guid distinto, `source_protocol_id` no existe — DEC-06).
- `VetProtocolControllerTest` (feature): 200 listado mezcla globales+propios; 201 alta con `vet_id` correcto; 201 `createVersion` sobre protocolo global crea copia con `vet_id`; 404 al editar/eliminar un protocolo global; 404 al editar/eliminar un protocolo de OTRA vet (setup con 2 vets); 422 nombre duplicado dentro de la misma vet; 200 dos vets distintas SÍ pueden tener protocolos homónimos (regresión DEC-09); 403 sin permiso `protocols.create` (perfil `vet-assistant`).
- `AdminProtocolControllerTest` (regresión): el listado admin ya NO debe incluir protocolos con `vet_id` seteado (setup: crear uno global + uno de vet sobre la misma sub-técnica, assert que el índice devuelve solo el global).

## Cambios en FRONTEND

### Archivos a crear

#### `front/src/modules/protocols/types/vet-protocol.types.ts`
```typescript
import type { ProtocolTask, ProtocolTaskPayload } from './protocol.types'

export interface VetProtocolTechniqueRef { guid: string; name: string }

export interface VetProtocolListItem {
  guid: string
  name: string
  color: string | null
  technique: VetProtocolTechniqueRef
  is_global: boolean
  is_own: boolean
  tasks_count: number
  created_at: string
}

export interface VetProtocolDetail extends VetProtocolListItem {
  updated_at: string
  tasks: ProtocolTask[]
}

export interface VetProtocolListParams {
  technique_id?: string
  search?: string
  page?: number
  per_page?: number
}

export interface CreateVetProtocolPayload {
  technique_id: string
  name: string
  color: string | null
  tasks: ProtocolTaskPayload[]
}

export type UpdateVetProtocolPayload = CreateVetProtocolPayload
```

#### `front/src/modules/protocols/api/vet-protocol.api.ts`
```typescript
export async function listVetProtocolsApi(vetGuid: string, params: VetProtocolListParams, signal?: AbortSignal): Promise<PaginatedResponse<VetProtocolListItem>>
export async function getVetProtocolApi(vetGuid: string, guid: string): Promise<VetProtocolDetail>
export async function createVetProtocolApi(vetGuid: string, payload: CreateVetProtocolPayload): Promise<VetProtocolDetail>
export async function createVetProtocolVersionApi(vetGuid: string, sourceGuid: string): Promise<VetProtocolDetail>
export async function updateVetProtocolApi(vetGuid: string, guid: string, payload: UpdateVetProtocolPayload): Promise<VetProtocolDetail>
export async function deleteVetProtocolApi(vetGuid: string, guid: string): Promise<void>
```
Endpoints: `/v1/vets/${vetGuid}/protocols` (GET/POST), `/v1/vets/${vetGuid}/protocols/${guid}` (GET/PUT/DELETE), `/v1/vets/${vetGuid}/protocols/${guid}/version` (POST). Mismo patrón que `clients.api.ts` (primer parámetro siempre `vetGuid`).

#### `front/src/modules/protocols/composables/useVetProtocolList.ts`, `useVetProtocolDetail.ts`
Mismo patrón que `useProtocolList`/`useProtocolDetail`, query keys `['vet-protocols', vetGuid, params]` / `['vet-protocol', vetGuid, guid]`.

#### `front/src/modules/protocols/composables/useVetProtocolMutations.ts`
`useCreateVetProtocol`, `useCreateVetProtocolVersion`, `useUpdateVetProtocol`, `useDeleteVetProtocol` — mismo patrón que `useProtocolMutations.ts`, invalidando `['vet-protocols', vetGuid]`.

#### `front/src/modules/protocols/composables/useTechniqueTree.ts`
```typescript
export function useTechniqueTree() {
  return useQuery({ queryKey: ['techniques-tree'], queryFn: () => listTechniquesApi() })
}
```
Envuelve `listTechniquesApi()` (YA existe en `technique.api.ts`, sin consumidor previo) para alimentar el selector de dos niveles de `VetProtocolFormDrawer`.

#### `front/src/modules/protocols/components/tenant/VetProtocolFormDrawer.vue`
`BaseDrawer` con `useForm` + `protocolSchema` (reutilizado, sin el campo `country_id` en el template — el schema Zod puede mantenerse igual dado que `country_id` queda `nullable().optional()` y simplemente no se renderiza ni se envía; el payload final омite `country_id` antes de llamar a la API). Selector de técnica en 2 pasos: `BaseSelect` de técnica raíz (`useTechniqueTree()`, `type=technique` o `vaccine` según corresponda) → `BaseSelect` de sub-técnica (opciones = `children` de la raíz elegida). Reutiliza `<ProtocolTaskList v-model="tasks" />` sin cambios. Prop `initialValues: VetProtocolDetail | null` (para edición) y prop separada `versionSource: ProtocolDetail | null` (para precarga de "crear versión" — mismo `toFormValues()` pero el submit dispara `createVersion` en vez de `create`/`update`).

#### `front/src/modules/protocols/components/tenant/VetProtocolsTable.vue`
`BaseDataTable`. Columnas: nombre, sub-técnica, badge "Global"/"Propio" (según `is_global`/`is_own`), `tasks_count`, `created_at`. `BaseTableActions`: "Editar" y "Eliminar" SOLO visibles si `row.is_own` (chequeo adicional en el template, además del `PermissionGuard` de permiso); "Crear versión" visible siempre (tanto en filas globales como propias, permite versionar una versión propia también), detrás de `PermissionGuard permission="protocols.create"`.

#### `front/src/modules/protocols/components/tenant/VetProtocolDeleteModal.vue`
Igual a `ProtocolDeleteModal.vue`, apuntando a `useDeleteVetProtocol`.

#### `front/src/modules/protocols/pages/tenant/VetProtocolsListPage.vue`
Página de listado, mismo layout que `ClientsListPage.vue` (`AppHeader` + filtros + `EmptyState` + tabla + paginación). `vetGuid` desde `route.params.vetGuid`. Botón "Nuevo protocolo" y control de filtro por técnica (usa `useTechniqueTree()` para el select de filtro).

#### `front/src/modules/protocols/router/vet-protocols.routes.ts`
```typescript
export const vetProtocolsRoutes: RouteRecordRaw[] = [
  {
    path: '/vets/:vetGuid/protocols',
    name: 'vet-protocols-list',
    component: () => import('@/modules/protocols/pages/tenant/VetProtocolsListPage.vue'),
    meta: { requiresAuth: true, title: 'Protocolos' },
  },
]
```

### Archivos a modificar

#### `front/src/router/index.ts`
**Cambio:** registrar `vetProtocolsRoutes`, mismo bloque donde están `clientsRoutes`/`adminClientsRoutes`.

#### `front/src/components/layouts/partials/VetMenu.vue`
**Cambio:** agregar grupo "Reproducción" con el ítem "Protocolos", condicionado a `can('protocols.read')`.
**Después (agregar tras el bloque `vetNavItems`/antes de "Soporte"):**
```typescript
const reproduccionNavItems = computed(() => [
  { path: `/vets/${vetGuid.value}/protocols`, label: 'Protocolos', icon: ProfileOutlined },
])
```
```html
<template v-if="can('protocols.read')">
  <div class="dash-nav-divider" />
  <Transition name="label-fade">
    <span v-if="!collapsed" class="dash-nav-section">Reproducción</span>
  </Transition>
  <RouterLink
    v-for="item in reproduccionNavItems"
    :key="item.path"
    :to="item.path"
    class="dash-nav-item"
    :class="{ 'is-active': route.path.startsWith(item.path) }"
    :title="collapsed ? item.label : undefined"
  >
    <component :is="item.icon" class="dash-nav-icon" />
    <Transition name="label-fade">
      <span v-if="!collapsed" class="dash-nav-label">{{ item.label }}</span>
    </Transition>
  </RouterLink>
</template>
```
Ícono: reutilizar `ProfileOutlined` (ya importado en el archivo para "Mi perfil") o importar uno nuevo específico (ej. `HeartOutlined`) si se prefiere diferenciar visualmente — decisión menor, cualquiera de las dos es válida; se sugiere un ícono propio para no confundir con "Mi perfil".

#### `front/src/modules/protocols/types/protocol.types.ts`
**Cambio:** ninguno obligatorio. `ProtocolDetail`/`ProtocolTask`/`ProtocolTaskAlert` se reutilizan tal cual desde `vet-protocol.types.ts` (import cruzado dentro del mismo módulo).

### Tests a generar

- `VetProtocolFormDrawer.spec.ts`: el selector de sub-técnica se resetea al cambiar la técnica raíz; no permite enviar sin sub-técnica seleccionada; `versionSource` precarga tasks/alerts pero el submit llama a `createVersion`, no a `update`.
- `VetProtocolsTable.spec.ts`: oculta "Editar"/"Eliminar" cuando `is_own === false`; muestra "Crear versión" en todas las filas.
- `useVetProtocolMutations.spec.ts`: invalidación de `['vet-protocols', vetGuid]` tras create/update/delete/createVersion.

## Orden de implementación

1. Migración `alter_protocols_unique_index_add_vet_id` + `php artisan migrate`.
2. `ProtocolRepositoryInterface` + `ProtocolRepositoryEloquent`: `paginateForVet()`, `findByGuidForVet()`, fix `paginateByRootTechnique()` (DEC-08), `existsDuplicate()` con `vetId`.
3. Actualizar llamadas existentes a `existsDuplicate()` en `StoreProtocolRequest`/`UpdateProtocolRequest` (admin) con `vetId: null` explícito — sin esto el admin rompe en runtime.
4. `ProtocolService`: `paginateForVet()`, `findByGuidForVet()`, `create()`/`replicate()`/`resolveReplicateName()`/`isDuplicateName()` con `vetId` opcional.
5. Tests de regresión backend admin (paso 2-4) ANTES de seguir — confirmar que nada de SuperAdmin se rompió.
6. `IndexVetProtocolRequest`, `StoreVetProtocolRequest`, `UpdateVetProtocolRequest`.
7. `VetProtocolListResource`, `VetProtocolResource`.
8. `VetProtocolController` + rutas tenant en `routes/api/protocols.php`.
9. `ProtocolPermissionsSeeder`: asignación a roles tenant (`vet`, `vet-administrative`, `vet-assistant`) + correr seeder en entorno de desarrollo.
10. Tests backend nuevos (Repository, Service, Controller feature, regresión Admin).
11. Frontend: `vet-protocol.types.ts`, `vet-protocol.api.ts`.
12. Frontend: `useTechniqueTree.ts`, `useVetProtocolList.ts`, `useVetProtocolDetail.ts`, `useVetProtocolMutations.ts`.
13. Frontend: `VetProtocolFormDrawer.vue` (reutilizando `ProtocolTaskList`/`ProtocolAlertList`/`protocolSchema`), `VetProtocolsTable.vue`, `VetProtocolDeleteModal.vue`.
14. Frontend: `VetProtocolsListPage.vue` + `vet-protocols.routes.ts` + registro en `router/index.ts`.
15. Frontend: grupo "Reproducción" en `VetMenu.vue`.
16. Tests frontend.
17. QA manual end-to-end: listado mezclado global+propio, alta propia, "crear versión" desde un global (verificar nombre con sufijo si choca), edición propia, intento de editar/eliminar un global (esperar 404 vía Network tab, UI no debería ni ofrecer el botón), dos vets de prueba con protocolo homónimo (no debe fallar), verificar que el panel admin sigue mostrando solo protocolos globales tras esta iteración.

## Riesgos y consideraciones

- **DEC-08 es una corrección de bug retroactiva, no una feature nueva** — el equipo debe tratarla como fix obligatorio antes de habilitar escritura de `vet_id`, no como algo opcional para "después". Si se despliega este plan sin el fix, el panel SuperAdmin empieza a mostrar protocolos de vets mezclados con la capa plantilla desde el primer protocolo que un vet cree.
- **Cambio de firma en `existsDuplicate()`**: agrega un parámetro en el medio de la lista (`$vetId` entre `$name` y `$excludeGuid`). Esto rompe cualquier llamado posicional existente que use `$excludeGuid` sin nombrar el parámetro — se identificaron 2 llamados (`StoreProtocolRequest`, `UpdateProtocolRequest`), ambos se actualizan en este plan (paso 3), pero si existe algún otro código que llame a `existsDuplicate()` fuera de lo relevado (no encontrado por Grep en esta exploración), quedaría roto en runtime. Recomendación al dev: correr `grep -rn "existsDuplicate" back/app back/tests` antes de tocar la firma, como último chequeo.
- **`VetProtocolFormDrawer` no reutiliza `ProtocolFormDrawer` por completo** (DEC-11): esto significa dos componentes de formulario con lógica de validación/submit parecida pero no idéntica. Si en el futuro se agrega un campo nuevo a `protocolSchema` (ej. una alerta con un campo extra), hay que verificar que ambos drawers lo soporten — el schema Zod SÍ es compartido, pero el binding de campos en el template de cada drawer es independiente. Riesgo aceptado porque los dos flujos (admin embebido en tab de técnica vs. vet con selector libre de técnica) son estructuralmente distintos.
- **"Crear versión" no permite editar antes de guardar** (DEC-05): si el negocio pide después "permitime cambiar el nombre/tareas ANTES de crear la versión, no después", hay que rediseñar el endpoint para aceptar un payload completo en vez de ser un simple "clonar". Documentado como decisión explícita, no como omisión.
- **Multi-tenant**: cada query de escritura/lectura tenant pasa por `vet.tenant` (`EnsureUserBelongsToVet`) + el scope explícito `vet_id` en `paginateForVet()`/`findByGuidForVet()` — cumple la regla dura #4. El listado (`GET`) intencionalmente incluye protocolos de OTRAS vets — no, aclaración: incluye protocolos GLOBALES (`vet_id IS NULL`), nunca de otra vet específica; esto es coherente con el requerimiento ("protocolos globales + propios de esa vet"), no una violación del scope tenant.
- **Multi-país**: los protocolos de vet no usan `country_id` (DEC-07) — esto es intencional y no una omisión de la regla dura #5; el país sigue siendo relevante únicamente para la capa plantilla (admin), donde ya está correctamente soportado.
- **`countProgramsForProtocol()` sigue siendo un stub** (heredado del plan admin) — aplica igual para protocolos de vet: hoy nada bloquea el borrado de un protocolo propio con "programas" (instancias) asociados, porque el módulo `programs` no existe. Deuda técnica ya documentada, no se resuelve en este plan.

## Pendientes / fuera de alcance

- `source_protocol_id` / trazabilidad de versión — explícitamente descartado por el usuario (DEC-06), documentado para revisitar si el negocio lo pide más adelante.
- Filtro de listado vet por "solo global" / "solo propios" — el requerimiento solo pide mezclar ambos; si se necesita filtrar, agregar un parámetro `scope=own|global|all` a `IndexVetProtocolRequest`/`paginateForVet()`.
- `programs`/`program_tasks`/`program_task_alerts` (capa instancia) — sigue fuera de alcance, igual que en el plan admin.
- Reordenar el grupo "Reproducción" respecto a los demás grupos del menú (arriba/abajo de "Veterinaria") — no especificado por el usuario, se sugiere ubicarlo después de "Veterinaria" y antes de "Soporte" por ser el orden de aparición más natural (gestión operativa → soporte), pero es una decisión de UX menor que el dev puede ajustar sin impacto funcional.
