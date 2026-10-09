# Plan técnico: Vínculo Staff de Cliente <-> Establecimiento

## Input procesado
Brief informal del usuario (sin ticket). Decisiones de negocio ya tomadas por el usuario (no se reabren): vínculo N:M Establishment <-> UserProfile, sin vínculo = sin acceso ni responsabilidad, client-owner es dueño de establecimiento (sin excepción por rol), filtrar en vez de auto-resolver en Program.

---

## Resumen ejecutivo

Se agrega la tabla pivot `establishment_user_profile` y las relaciones `Establishment::staff()` / `UserProfile::establishments()`. `EstablishmentService::syncStaff` valida (en service) que cada perfil sea staff del MISMO client del establecimiento y sincroniza el pivot; al desvincular dispara un evento que quita a esos perfiles como managers de los programas activos del establecimiento y regenera las alertas pendientes. `StoreProgramRequest` / `UpdateProgramRequest` y un guard en `ProgramService` exigen que los managers de origen cliente estén vinculados al establecimiento del programa. Una migración de datos vincula cada staff actual con todos los establecimientos de su cliente, para no cortar nada. La resolución de destinatarios de alertas NO necesita cambios: verificado que todas las rutas (ProgramCreated, ProgramCancelled, ProgramTaskDue, proyección y share) leen `$program->managers`, por lo que el filtro en la asignación de managers alcanza. El scope de visibilidad para un futuro portal de clientes queda solo diseñado. `ClientOwnerController` se retira (no tiene consumidor en FE).

---

## Hallazgos verificados en el código (complementan el brief)

1. **Resolución de destinatarios: una sola fuente, `program_manager`.**
   - `ScheduleProgramCreatedAlertListener` y `HandleProgramCancelledListener`: `createForManagers($alert, $program->managers)`.
   - `GenerateProgramTaskDueAlertsListener::generateAlertForProtocolTaskAlert`: `$program->managers->filter(role in ProtocolTaskAlert.roles)`. Los ROLES del ProtocolTaskAlert solo filtran DENTRO de los managers del programa; no hay una segunda ruta que resuelva usuarios por rol.
   - `ProgramService::projectTargetTasks` (preview `recipients`): idem, `$program->managers->filter(role)`.
   - `ProgramShareService` (líneas 46 y 135): `$program->managers`.
   - Conclusión: si el pivot `program_manager` respeta el vínculo, TODAS las rutas lo respetan. No se toca ningún listener de alertas ni `AlertRecipientFactory`.
2. **Health plans:** `GenerateHealthPlanMonthAlertsListener` resuelve destinatarios con `listByRoleForVet($plan->vet, 'vet')` (solo staff del vet, nunca de cliente) -> sin impacto. `CONFIRM_ROLES` incluye client-owner/client-manager, pero `confirmActivity` recibe `current_profile` del middleware `EnsureUserBelongsToVet`, que solo resuelve perfiles `vet`; hoy un perfil de cliente no puede llegar ahí. Se agrega igual un guard barato en el service (ver DEC-07) para que el día del portal no quede un agujero.
3. **DISCREPANCIA con el brief (owner inicial):** `ClientService::create` NO crea ningún owner ni dispara `SendClientOwnerInvitationJob`. El único camino a `UserProfileService::addOwnerToClient` es `ClientOwnerController@store` (rutas `/clients/{guid}/owners`). En el FE, tras TKT-003 quedaron solo `listOwnersApi`/`createOwnerApi`/`OwnerItem`/`OwnerCreatePayload`/`ownerCreateSchema` sin ningún consumidor (`OwnersSection` y `OwnerFormModal` ya se eliminaron). `SendClientOwnerInvitationJob` sí se sigue usando desde `createAndAssignClientStaff` (se mantiene). Por lo tanto no existe hoy un "owner inicial" que haya que vincular.
4. `UserService::createUser` / `addProfile` (panel admin de usuarios) también crean perfiles `client-*` sueltos. Con el nuevo modelo nacen SIN vínculos (regla 4). Es coherente; no requiere cambio.
5. Los tests corren con sqlite `:memory:` (phpunit.xml) -> la migración de backfill debe usar query builder portable (no SQL específico de MySQL).
6. Los listeners se auto-descubren (no hay `Event::listen` explícito): el nuevo listener solo necesita el type-hint en `handle()`.
7. El pivot existente `program_manager` no tiene guid ni id expuesto; se replica el mismo estilo para `establishment_user_profile`.

---

## Decisiones tomadas

**DEC-01 — Modelado del vínculo**
- Decisión: tabla pivot `establishment_user_profile` (id, establishment_id, user_profile_id, timestamps, unique compuesto, índice en `user_profile_id`), FKs con `cascadeOnDelete()`. Relaciones `Establishment::staff()` y `UserProfile::establishments()` (`belongsToMany` + `withTimestamps()`). Sin guid en el pivot (igual que `program_manager`).
- Justificación: sigue el patrón del proyecto; el cascade en DB resuelve borrado de staff y de establecimiento sin código extra.
- Alternativa descartada: columna `establishment_id` en `user_profiles` (1:N) — el brief exige N:M.

**DEC-02 — Dónde se valida "mismo client"**
- Decisión: en `EstablishmentService::syncStaff` (fuente de verdad), vía `UserProfileRepositoryInterface::findManyByGuidsForClient($guids, $client)`. Si el conteo devuelto != guids únicos recibidos, o algún perfil no tiene rol en `UserProfileService::CLIENT_STAFF_ROLES`, se lanza `EstablishmentStaffMismatchException` (422). El FormRequest solo valida forma (array de uuid) — nunca es la única barrera.
- Justificación: pedido explícito del usuario; un perfil de otro client o de un vet nunca queda vinculado aunque alguien llame al service desde otro punto.
- Alternativa descartada: `Rule::exists` en el request — deja el service desprotegido y no verifica pertenencia al client.

**DEC-03 — Endpoint de sincronización**
- Decisión: un único `PUT` de sincronización total (`user_profile_guids` = estado final deseado; `[]` desvincula a todos). Tenant: `PUT /v1/vets/{vet}/clients/{client}/establishments/{guid}/staff`. Admin: `PUT /v1/admin/clients/{guid}/establishments/{estGuid}/staff`. Permiso reutilizado `establishments.update` (no se crea permiso nuevo). NO se crea GET dedicado: el staff viaja embebido en `EstablishmentResource` (DEC-04).
- Justificación: menor superficie de API; el form del FE ya conoce el estado final; reutilizar permisos evita tocar seeders/roles.
- Alternativa descartada: POST/DELETE por perfil (attach/detach) — más requests, más estados intermedios, y el FE de selector múltiple naturalmente produce el set final.

**DEC-04 — Shape de lectura**
- Decisión: `EstablishmentResource` agrega `staff` (`UserProfileResource::collection(whenLoaded('staff'))`) y `staff_count` (`whenCounted`). `UserProfileResource` agrega `establishments: [{guid, name}]` con `whenLoaded('establishments')`. Los listados cargan: `EstablishmentRepositoryEloquent::listForClient` -> `->with(['staff.user','staff.role'])`; `UserProfileRepositoryEloquent::listForClient` -> `with(['user','role','establishments'])`.
- Justificación: el FE del form de Program ya carga `useClientEstablishments(clientId)`; con staff embebido puede filtrar managers sin request extra (DEC-09). Y la lista de staff muestra a qué establecimientos pertenece cada persona.
- Alternativa descartada: endpoint GET `.../establishments/{guid}/staff` — N+1 requests en el form de programa.

**DEC-05 — Filtrar vs auto-resolver (confirmación de la preferencia del analista): CONFIRMADA**
- Decisión: filtrar. `manager_profile_ids` acepta (a) staff del vet (como hoy, cualquiera de la vet) y (b) staff de cliente SOLO si está vinculado al `establishment_id` del programa. Se valida en `StoreProgramRequest`/`UpdateProgramRequest` (errores por índice, para el FE) y de nuevo en `ProgramService::create/update` (guard defensivo, excepción `ProgramManagerNotLinkedException` -> 422). `AlertRecipientFactory::createForManagers` NO se modifica.
- Evidencia: (1) todos los caminos de destinatarios leen `program_manager` (hallazgo 1); auto-resolver por establecimiento obligaría a tocar 4 listeners + la proyección + share y a decidir qué hacer cuando el vínculo cambia después de crear el programa. (2) El programa hoy ya funciona por selección manual de managers; filtrar mantiene una sola fuente de verdad y el vet ve exactamente quién recibe qué. (3) El único costo del filtro es la coherencia posterior, que se cubre con el listener de desvinculación (DEC-06).
- Alternativa descartada: auto-resolver los client-* desde el vínculo — más superficie, cambia semántica de "managers" y hace no-determinista la lista de destinatarios que hoy se ve en el detalle.

**DEC-06 — Desvincular un perfil de un establecimiento con programas activos**
- Decisión: `EstablishmentService::syncStaff` calcula los `detached` ids del `sync()` y, después del commit, dispara `EstablishmentStaffUnlinkedEvent(Establishment, int[] $profileIds)`. Listener `DetachUnlinkedManagersFromProgramsListener`: para cada Program del establecimiento con `cancelled_at IS NULL` que tenga a alguno de esos perfiles como manager, hace `managers()->detach($ids)` y dispara `ProgramTargetsChangedEvent` (regenera las `program.task_due` pendientes con los managers restantes; comportamiento ya existente del listener). Programas cancelados no se tocan (trazabilidad). Un programa puede quedar con 0 managers de cliente (y hasta 0 managers): es válido a nivel persistencia; solo se exige min:1 al crear/editar desde la API.
- Justificación: sin esto el invariante "manager de cliente => vinculado" se rompería en silencio y seguirían llegando alertas a quien ya no corresponde (violaría regla 4).
- Alternativa descartada: bloquear la desvinculación si hay programas activos — friccionaría la operación diaria y el usuario no pidió ese bloqueo.

**DEC-07 — Health plans**
- Decisión: sin cambios en la generación de alertas (van solo a `vet`). En `EstablishmentHealthPlanService::confirmActivity`, si `$profile->role->name` empieza con `client-`, además de `CONFIRM_ROLES` se exige que el perfil esté vinculado al establecimiento del plan (`EstablishmentService::isProfileLinked`); si no, `EstablishmentHealthPlanActivityConfirmationNotAllowedException`. Roles `vet*` quedan exentos. Hoy es código inalcanzable para clientes (sin portal) pero deja la regla 4/5 cerrada y testeada.
- Alternativa descartada: no tocar y dejarlo para el portal — riesgo de olvido; el costo es ~5 líneas.

**DEC-08 — `ClientOwnerController` y "owner inicial"**
- Decisión: el concepto "owner del cliente" se retira. Se ELIMINAN: `ClientOwnerController`, `StoreOwnerRequest`, rutas `/clients/{guid}/owners`, `UserProfileService::addOwnerToClient` y `listOwnersForClient`, `UserProfileRepository*::listOwnersForClient`, y en FE `listOwnersApi`, `createOwnerApi`, `OwnerItem`, `OwnerCreatePayload`, `ownerCreateSchema`/`OwnerCreateForm`. Los permisos `clients.owners.*` se dejan en `PermissionSeeder`/`RoleSeeder` sin uso (no se borran filas de DB en esta iteración) y se listan como deuda. `SendClientOwnerInvitationJob` se mantiene (lo usa `createAndAssignClientStaff`). Crear un cliente NO crea ni vincula owner (ya no lo hacía). Un "owner" pasa a ser simplemente un staff con rol `client-owner`, sin privilegios de cliente: dueño solo de los establecimientos donde está vinculado.
- "Establecimiento sin nadie": no se auto-vincula (contradice regla 4: sin vínculo = nada). Es un estado válido y visible: la lista de establecimientos muestra el badge "Sin personal vinculado" cuando `staff_count = 0`, y el form de programa muestra el texto vacío del bloque de cliente. Los establecimientos existentes quedan cubiertos por el backfill (DEC-10).
- Justificación: código muerto con una semántica ahora inválida; TKT-003 prohibía eliminarlo solo porque en ese momento el FE aún lo consumía.
- Alternativa descartada: dejar el controller y adaptarlo — mantendría dos caminos para crear `client-owner` y uno de ellos ignora el vínculo.

**DEC-09 — Filtrado de opciones en el FE del form de Program**
- Decisión: `clientStaffOptions` deja de venir de `useClientStaff(vetGuid, clientId)` y se deriva del establecimiento elegido: `establishments.find(e => e.guid === establishmentId)?.staff`. Al cambiar `establishmentId` se limpian los managers de origen cliente ya seleccionados (mismo patrón que el watch de `clientId`). Los managers de vet no cambian.
- Justificación: cero requests nuevas, coherente con el backend, y evita que el usuario elija algo que el backend va a rechazar.

**DEC-10 — Migración de datos (backfill)**
- Decisión: migración separada `..._backfill_establishment_user_profile.php` que inserta (idempotente, `insertUsing` + `whereNotExists`) un par por cada `(establishment, user_profile)` con `user_profiles.authenticatable_type='client'`, `authenticatable_id = establishments.client_id` y rol en CLIENT_STAFF_ROLES. `down()` vacío (la tabla la borra la migración de esquema).
- Programas existentes: NO requieren corrección de datos. Como todo staff actual queda vinculado a todos los establecimientos de su cliente, todo manager de cliente existente cumple la nueva regla; no se altera ningún `program_manager` ni alerta pendiente.
- Justificación: no corta alertas hoy; la restricción se afina después, desde la UI.

**DEC-11 — Scope de visibilidad (fase futura, NO implementar)**
- Decisión: solo se implementa la relación `UserProfile::establishments()` (necesaria ya). Queda documentado el diseño para el portal: `Program::scopeVisibleToClientProfile(Builder $q, UserProfile $p)` = `whereIn('establishment_id', $p->establishments()->select('establishments.id'))` y análogo en `EstablishmentHealthPlan`, siempre combinado con `client_id = $p->authenticatable_id`. Regla: perfil sin vínculos => `whereRaw('1 = 0')` (nada), nunca "todos". Se usará vía métodos de repositorio (`paginateForClientProfile`), no en controllers.

---

## Cambios en BACKEND

### Archivos a crear

#### `back/database/migrations/2026_09_29_000001_create_establishment_user_profile_table.php`
**Propósito:** pivot N:M.
```php
Schema::create('establishment_user_profile', function (Blueprint $table) {
    $table->id();
    $table->foreignId('establishment_id')->constrained('establishments')->cascadeOnDelete();
    $table->foreignId('user_profile_id')->constrained('user_profiles')->cascadeOnDelete();
    $table->timestamps();

    $table->unique(['establishment_id', 'user_profile_id']);
    $table->index('user_profile_id'); // lookup inverso: establecimientos de un perfil (scope de visibilidad)
});
```
`down()`: `Schema::dropIfExists('establishment_user_profile')`.

#### `back/database/migrations/2026_09_29_000002_backfill_establishment_user_profile.php`
**Propósito:** vincular todo staff de cliente existente a todos los establecimientos de su cliente.
**Pseudocódigo (query builder portable, sin SQL crudo de MySQL):**
```php
$now = now()->toDateTimeString();
$select = DB::table('establishments as e')
    ->join('user_profiles as up', function ($j) {
        $j->on('up.authenticatable_id', '=', 'e.client_id')->where('up.authenticatable_type', 'client');
    })
    ->join('roles as r', 'r.id', '=', 'up.role_id')
    ->whereIn('r.name', ['client-owner', 'client-manager', 'client-administrative'])
    ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('establishment_user_profile as x')
        ->whereColumn('x.establishment_id', 'e.id')->whereColumn('x.user_profile_id', 'up.id'))
    ->select('e.id', 'up.id', DB::raw("'{$now}'"), DB::raw("'{$now}'"));
DB::table('establishment_user_profile')->insertUsing(
    ['establishment_id', 'user_profile_id', 'created_at', 'updated_at'], $select);
```
Nota: verificar que el `morphMap` de `AppServiceProvider` mapea `'client'` -> `Client` (los perfiles guardan el alias `'client'`, ya usado en `UserProfileService`).

#### `back/app/Exceptions/EstablishmentStaffMismatchException.php`
`extends \RuntimeException`, mensaje: `'Uno o más perfiles seleccionados no son personal del cliente de este establecimiento.'`

#### `back/app/Exceptions/ProgramManagerNotLinkedException.php`
`extends \RuntimeException`, mensaje: `'Uno o más responsables del cliente no están vinculados al establecimiento del programa.'`

#### `back/app/Events/EstablishmentStaffUnlinkedEvent.php`
```php
class EstablishmentStaffUnlinkedEvent
{
    /** @param int[] $profileIds ids internos de UserProfile desvinculados */
    public function __construct(public readonly Establishment $establishment, public readonly array $profileIds) {}
}
```

#### `back/app/Listeners/DetachUnlinkedManagersFromProgramsListener.php`
**Propósito:** mantener el invariante manager-de-cliente => vinculado (DEC-06).
```php
public function handle(EstablishmentStaffUnlinkedEvent $event): void
{
    Program::query()
        ->where('establishment_id', $event->establishment->id)
        ->whereNull('cancelled_at')
        ->whereHas('managers', fn ($q) => $q->whereIn('user_profiles.id', $event->profileIds))
        ->get()
        ->each(function (Program $program) use ($event) {
            $program->managers()->detach($event->profileIds);
            $program = $program->fresh()->load('targets', 'protocol.tasks.alerts', 'managers.role');
            event(new ProgramTargetsChangedEvent($program)); // regenera task_due pendientes
        });
}
```
No es queued (mismo criterio que el resto de listeners de programa).

#### `back/app/Http/Requests/Establishments/SyncEstablishmentStaffRequest.php`
```php
public function rules(): array
{
    return [
        'user_profile_guids'   => ['present', 'array'],
        'user_profile_guids.*' => ['string', 'uuid', 'distinct'],
    ];
}
public function messages(): array { /* 'user_profile_guids.present' => 'Debe indicar la lista de personal (puede estar vacía).' ... en español */ }
```
`authorize()` retorna `true`. Sin `exists`: la validación real está en el service (DEC-02).

### Archivos a modificar

#### `back/app/Models/Establishment.php`
**Cambio:** agregar `staff(): BelongsToMany` -> `$this->belongsToMany(UserProfile::class, 'establishment_user_profile')->withTimestamps()`.

#### `back/app/Models/UserProfile.php`
**Cambio:** agregar `establishments(): BelongsToMany` (inversa, mismo pivot, `withTimestamps()`). Además queda como base del scope futuro (DEC-11).

#### `back/app/Contracts/Repositories/EstablishmentRepositoryInterface.php` y `back/app/Repositories/EstablishmentRepositoryEloquent.php`
**Cambio:** agregar
```php
/** @return array{attached: int[], detached: int[], updated: int[]} */
public function syncStaff(Establishment $establishment, array $profileIds): array;      // $establishment->staff()->sync($ids)
public function hasStaff(Establishment $establishment, UserProfile $profile): bool;       // $establishment->staff()->whereKey($profile->id)->exists()
```
Y en `listForClient`: `->with(['staff.user', 'staff.role'])`.

#### `back/app/Contracts/Repositories/UserProfileRepositoryInterface.php` y `back/app/Repositories/UserProfileRepositoryEloquent.php`
**Cambio:** agregar `findManyByGuidsForClient(array $guids, Client $client): Collection` (`where authenticatable_type='client'`, `authenticatable_id=$client->id`, `whereIn('guid',$guids)`, `with('role')`). `listForClient` pasa a `with(['user','role','establishments'])`. Eliminar `listOwnersForClient` (DEC-08).

#### `back/app/Services/EstablishmentService.php`
**Cambio:** inyectar `UserProfileRepositoryInterface`; agregar
```php
/**
 * Sincroniza el personal de cliente vinculado a un establecimiento.
 * @param string[] $profileGuids estado final deseado ([] = desvincula a todos)
 * @throws EstablishmentStaffMismatchException
 */
public function syncStaff(Establishment $establishment, array $profileGuids): Establishment
{
    $guids    = array_values(array_unique($profileGuids));
    $client   = $establishment->client;
    $profiles = $this->userProfiles->findManyByGuidsForClient($guids, $client);

    $valid = $profiles->count() === count($guids)
        && $profiles->every(fn ($p) => in_array($p->role->name, UserProfileService::CLIENT_STAFF_ROLES, true));
    if (!$valid) { throw new EstablishmentStaffMismatchException(); }

    $result = DB::transaction(fn () => $this->establishmentRepository->syncStaff($establishment, $profiles->pluck('id')->all()));

    if ($result['detached'] !== []) {
        event(new EstablishmentStaffUnlinkedEvent($establishment, $result['detached'])); // fuera de la transacción
    }
    return $establishment->load(['staff.user', 'staff.role']);
}

public function isProfileLinked(Establishment $establishment, UserProfile $profile): bool
{
    return $this->establishmentRepository->hasStaff($establishment, $profile);
}
```
Nota: `findManyByGuidsForClient` está scopeado por `client_id` del establecimiento, y el establecimiento ya se obtuvo con `findByGuidForClient` (client scopeado al vet) => multi-tenant cubierto en las dos capas.

#### `back/app/Http/Controllers/V1/EstablishmentController.php`
**Cambio:** nuevo `syncStaff(SyncEstablishmentStaffRequest $request): JsonResponse`, mismo patrón de resolución que `update` (vet -> client -> establishment, 404 en cada nivel). Captura `EstablishmentStaffMismatchException` -> `makeError(['reason' => 'staff_client_mismatch'], $e->getMessage(), 422)`. Respuesta: `EstablishmentResource` con `staff` cargado, mensaje `'Personal del establecimiento actualizado correctamente.'`.
`index`, `update` y `store` no cambian; `EstablishmentResource` ya trae `staff` en index gracias al eager load del repo.

#### `back/app/Http/Controllers/V1/AdminClientController.php`
**Cambio:** nuevo `establishmentSyncStaff(SyncEstablishmentStaffRequest $request, string $guid, string $estGuid)`: `findByGuid` del client (sin scope de vet, igual que `establishmentUpdate`), `findByGuidForClient($estGuid, $client)`, `syncStaff`, mismos errores. `staffIndex` sigue igual (recibe `establishments` por el eager load del repo).

#### `back/app/Http/Resources/V1/EstablishmentResource.php`
**Cambio:** agregar
```php
'staff'       => UserProfileResource::collection($this->whenLoaded('staff')),
'staff_count' => $this->whenCounted('staff'),
```
`listForClient` agrega `withCount('staff')` además del eager load. (Nunca exponer `id`.)

#### `back/app/Http/Resources/V1/UserProfileResource.php`
**Cambio:** agregar `'establishments' => $this->whenLoaded('establishments', fn () => $this->establishments->map(fn ($e) => ['guid' => $e->guid, 'name' => $e->name])->values())`.

#### `back/app/Http/Requests/Programs/StoreProgramRequest.php` y `UpdateProgramRequest.php`
**Cambio:** inyectar `EstablishmentService` en el constructor. En `withValidator`, el bloque DEC-12 pasa a:
```php
$profile = $this->userProfileService->findByGuidForClient($profileGuid, $client);  // ya existente
if ($belongsToVet) { continue; }
if (!$profile) { error 'El responsable seleccionado no pertenece a esta empresa ni al cliente.' }
elseif ($establishment && !$this->establishmentService->isProfileLinked($establishment, $profile)) {
    error "manager_profile_ids.{$index}": 'El responsable seleccionado no está vinculado al establecimiento elegido.'
}
```
`$establishment` ya se resuelve en el mismo método (y solo se valida si pertenece al client). Cuidar que si el establecimiento no pertenece al client, no se agregue un segundo error confuso (saltear el chequeo de vínculo).

#### `back/app/Contracts/Repositories/ProgramRepositoryInterface.php` y `back/app/Repositories/ProgramRepositoryEloquent.php`
**Cambio:** `unlinkedClientManagerIds(int $establishmentId, array $profileIds): array` — devuelve ids de `user_profiles` con `authenticatable_type='client'` dentro de `$profileIds` que NO tienen fila en el pivot para `$establishmentId` (`whereDoesntHave('establishments', fn ($q) => $q->where('establishments.id', $establishmentId))`).

#### `back/app/Services/ProgramService.php`
**Cambio:** en `create()` y `update()`, antes de la transacción: `if ($this->programRepository->unlinkedClientManagerIds($data['establishment_id'], $managerProfileIds) !== []) throw new ProgramManagerNotLinkedException();`. Docblock: `@throws ProgramManagerNotLinkedException`. En `update()` hacerlo antes de `unset` de los ids ya resueltos (los ids llegan como int). No se toca `projectTargetTasks`.

#### `back/app/Http/Controllers/V1/ProgramController.php`
**Cambio:** en `store` y `update` agregar `catch (ProgramManagerNotLinkedException $e) { return $this->makeError(['reason' => 'manager_not_linked'], $e->getMessage(), 422); }` (antes del `catch (\Exception)`).

#### `back/app/Services/EstablishmentHealthPlanService.php`
**Cambio (DEC-07):** inyectar `EstablishmentService`; en `confirmActivity`, tras el chequeo de `CONFIRM_ROLES`:
```php
if (str_starts_with($profile->role->name, 'client-')
    && !$this->establishmentService->isProfileLinked($activity->plan->establishment, $profile)) {
    throw new EstablishmentHealthPlanActivityConfirmationNotAllowedException();
}
```
(`activity->plan` y `plan->establishment` existen; cargar con `loadMissing`.)

#### Retiro de owners (DEC-08)
- Borrar `back/app/Http/Controllers/V1/ClientOwnerController.php` y `back/app/Http/Requests/Clients/StoreOwnerRequest.php`.
- `back/routes/api/clients.php`: quitar el `use ClientOwnerController` y el grupo `/{guid}/owners`.
- `UserProfileService`: quitar `addOwnerToClient` y `listOwnersForClient` (revisar que `SendClientOwnerInvitationJob` siga importado por `createAndAssignClientStaff`). Repo: quitar `listOwnersForClient` de interface y eloquent.
- Antes de borrar: `rg "listOwnersForClient|addOwnerToClient|ClientOwnerController|StoreOwnerRequest" back` debe quedar limpio, incluidos tests.

#### `back/database/seeders/TestDataSeeder.php`
**Cambio:** después de crear establecimientos y staff de cada cliente, vincular: `$client->establishments->each(fn ($e) => $e->staff()->syncWithoutDetaching($clientProfiles))` con los perfiles `client-*` creados (el pivot no tiene guid, así que `WithoutModelEvents` no afecta; si `syncWithoutDetaching` no setea timestamps en el seeder, usa `withTimestamps()` de la relación). Owner solo a un establecimiento y manager/administrative al otro sería más realista para demo, pero el default recomendado es vincular todo (mismo resultado que el backfill).

### Rutas API
Agregar en `back/routes/api/clients.php`:

| Método | Path | Controller@action | Middleware / permiso |
|---|---|---|---|
| PUT | `/v1/vets/{vet}/clients/{client}/establishments/{guid}/staff` | `EstablishmentController@syncStaff` | `auth:sanctum`, `vet.tenant`, `can:establishments.update` |
| PUT | `/v1/admin/clients/{guid}/establishments/{estGuid}/staff` | `AdminClientController@establishmentSyncStaff` | `auth:sanctum`, `can:establishments.update` |

(Dentro del `Route::prefix('/{client}/establishments')` tenant: `Route::put('/{guid}/staff', ...)`; no colisiona con `PUT /{guid}` por tener segmento extra.)

### Permisos Spatie
Sin permisos nuevos. Se reutiliza `establishments.update`. Verificar en `RoleSeeder` (líneas ~69-103) que `vet` (y el resto que ya edita establecimientos) lo tenga; no se altera la asignación. `clients.owners.*` queda huérfano (deuda, DEC-08).

### Contrato del endpoint

**PUT `/v1/vets/{vet}/clients/{client}/establishments/{guid}/staff`**

Request:
```json
{ "user_profile_guids": ["uuid-profile-1", "uuid-profile-2"] }
```
(`[]` desvincula a todos; el campo es obligatorio para evitar borrados por omisión.)

Response 200:
```json
{
  "success": true,
  "message": "Personal del establecimiento actualizado correctamente.",
  "data": {
    "guid": "uuid", "name": "Estancia La Invernada", "renspa": "...", "address": "...", "city": "...",
    "state": "...", "zip_code": "...", "latitude": -35.05, "longitude": -58.76, "created_at": "...",
    "staff": [
      { "guid": "uuid-profile-1", "user": { "guid": "...", "name": "...", "first_name": "...", "last_name": "...", "email": "..." },
        "role": { "guid": "...", "name": "client-owner" }, "blocked_at": null, "created_at": "..." }
    ],
    "staff_count": 1
  }
}
```

`GET .../establishments` (ya existente) ahora incluye `staff` y `staff_count` en cada item. `GET .../clients/{client}/staff` incluye `establishments: [{guid, name}]` por perfil.

Errores:

| HTTP | Cuándo |
|---|---|
| 404 | Cliente no encontrado / no pertenece a la vet; establecimiento no encontrado en ese cliente |
| 422 | `user_profile_guids` ausente, no array, elementos no uuid o repetidos (errores por campo) |
| 422 `reason: staff_client_mismatch` | Algún guid no existe, es de otro client, es de un vet o su rol no es client-* |
| 422 `reason: manager_not_linked` | (Programas) manager de cliente no vinculado al establecimiento del programa |

### Tests a generar (backend-tester)

**Feature `EstablishmentStaffControllerTest` (nuevo):**
- Sync feliz: vincula 2 perfiles del mismo client; el resource devuelve `staff` y `staff_count`.
- Sync reemplaza (estado final): perfiles quitados desaparecen; `[]` desvincula todo.
- 422 mismatch: perfil de OTRO client, perfil de tipo vet, guid inexistente, guid duplicado.
- 404: establecimiento de otro client; client de otra vet (multi-tenant).
- Mismo comportamiento en el endpoint admin (sin scope de vet, con `establishments.update`).
- Mismo perfil vinculado a 2 establecimientos del mismo client (N:M).

**Feature `ProgramControllerTest` (ampliar):**
- Store/Update con manager de cliente NO vinculado -> 422 con error en `manager_profile_ids.{i}`.
- Store/Update con manager de cliente vinculado -> 201/200.
- Manager de vet siempre aceptado, sin vínculo.
- Update cambiando `establishment_id` a uno donde el manager no está vinculado -> 422.
- Cuidado: los fixtures existentes con managers de cliente deben vincularse al establecimiento (helper en `tests/Concerns`).

**Feature (desvinculación en cascada):**
- Desvincular perfil que es manager de un programa activo del establecimiento -> deja de ser manager, se regeneran las task_due pendientes sin ese perfil.
- Programa cancelado del mismo establecimiento NO se modifica.
- Programa de OTRO establecimiento del mismo client no se modifica.
- Borrar el UserProfile o el Establishment limpia el pivot (cascade).

**Unit:**
- `EstablishmentServiceTest`: `syncStaff` (mismatch por rol, por client, `detached` dispara evento sólo si hay), `isProfileLinked`.
- `ProgramServiceTest`: guard `ProgramManagerNotLinkedException` en create/update (ojo: los mocks del `ProgramRepositoryInterface` deben stubbear `unlinkedClientManagerIds`).
- `EstablishmentHealthPlanServiceTest`: `confirmActivity` con client-owner vinculado OK; no vinculado -> excepción; vet exento del chequeo.
- Migración de backfill: dataset con 2 clients, 3 establecimientos, perfiles de vet y de client; comprobar cruce correcto, no vincula perfiles vet ni de otro client, idempotente al re-ejecutar.

**Regresión de alertas (ampliar `ProgramCreatedAlertRecipientsTest`):** un programa con managers de cliente vinculados genera `AlertRecipient` solo para ellos; tras desvincular a uno y regenerar, ese perfil no recibe `ProgramTaskDue`.

---

## Cambios en FRONTEND

### Archivos a modificar

#### `front/src/modules/clients/types/client.types.ts`
- `EstablishmentItem`: agregar `staff?: ClientStaffItem[]`, `staff_count?: number`.
- `ClientStaffItem`: agregar `establishments?: { guid: string; name: string }[]`.
- Nuevo `EstablishmentStaffSyncPayload { user_profile_guids: string[] }`.
- Eliminar `OwnerItem`, `OwnerCreatePayload` (DEC-08).

#### `front/src/modules/clients/api/clients.api.ts`
- Agregar `syncEstablishmentStaffApi(vetGuid, clientGuid, estGuid, payload): Promise<EstablishmentItem>` (PUT tenant) y `adminSyncEstablishmentStaffApi(clientGuid, estGuid, payload)` (PUT admin).
- Eliminar `listOwnersApi` / `createOwnerApi` y sus imports.

#### `front/src/modules/clients/validators/client.validator.ts`
- Eliminar `ownerCreateSchema` / `OwnerCreateForm`. Agregar `establishmentStaffSchema = z.object({ user_profile_guids: z.array(z.string().uuid()) })`.

#### `front/src/core/constants/permissions.ts`
- Retirar las constantes `clients.owners.*` si no hay uso (comprobar con `rg`).

#### `front/src/modules/clients/components/modals/EstablishmentFormModal.vue` y `AdminEstablishmentFormModal.vue`
- Nuevo campo "Personal vinculado": `a-select mode="multiple"` (usar `BaseSelect` si soporta multiple; si no, extender el átomo antes de usarlo) con opciones de `useClientStaff(vetGuid, clientGuid)` (tenant) / `useAdminClientStaff(clientGuid)` (admin): label = `user.name`, con `RoleChip` del rol. Valor inicial en edición: `establishment.staff.map(s => s.guid)`.
- Texto de ayuda i18n: "Solo el personal vinculado verá este establecimiento y podrá recibir alertas de sus programas." Sin selección = permitido, con hint visible.
- Envolver el campo en `PermissionGuard` `establishments.update`.

#### `front/src/modules/clients/components/EstablishmentsSection.vue`
- Mostrar en cada fila los chips de staff vinculado (nombre + rol) y el badge "Sin personal vinculado" cuando `staff_count === 0`. Todo vía `$t` (agregar claves en `front/src/i18n/locales/es/`).

#### Staff list de cliente (tenant y admin)
- Componentes de tabla de staff en `components/tenant` y `components/admin`: agregar columna "Establecimientos" (chips de `establishments`); sin vínculos: "Sin establecimientos" (muted). Solo lectura en esta iteración.

#### Composables (uno por operación, con `invalidateQueries`)
- Crear `front/src/modules/clients/composables/useSyncEstablishmentStaff.ts` y `composables/admin/useAdminSyncEstablishmentStaff.ts`. `onSuccess`: invalidar `['client-establishments', vetGuid, clientGuid]`, `['client-staff', vetGuid, clientGuid]` (tenant) o `['admin-client-establishments', clientGuid]`, `['admin-client-staff', clientGuid]` (admin) y `['programs']` (los managers pueden haber cambiado).
- Los modales encadenan: crear/editar establecimiento y luego, en el `onSuccess`, `syncEstablishmentStaff` con el guid resultante (creación) o el existente (edición) solo si el set cambió. Si el segundo paso falla: notificación de error y el establecimiento queda creado; reintento desde edición.
- Eliminar composables/archivos relacionados con owners si los hubiera (`rg -i owner front/src/modules/clients`).

#### `front/src/modules/programs/pages/tenant/VetProgramFormPage.vue`
- Reemplazar `useClientStaff(vetGuid, clientId)` por derivación del establecimiento elegido:
```ts
const selectedEstablishment = computed(() => establishments.value?.find((e) => e.guid === establishmentId.value))
const clientStaffOptions = computed(() =>
  selectedEstablishment.value?.staff?.map((p) => ({ guid: p.guid, label: p.user.name, role: p.role.name })) ?? [])
const isLoadingClientStaff = isLoadingEstablishments
```
- `watch(establishmentId)`: al cambiar (fuera de `isResettingForm`), quitar de `managerProfileIds` los guids que ya no están en `clientStaffOptions` (los de vet se conservan).
- En modo edición, si el programa trae un manager de cliente que ya no está vinculado (caso raro tras cascada), se descarta del form al hidratar (el backend ya lo habrá desvinculado).

#### `front/src/modules/programs/components/tenant/form-sections/ProgramClientSection.vue`
- `emptyText` del bloque "Cliente": si no hay establecimiento elegido "Elegí un establecimiento para ver su personal."; si hay y la lista está vacía "Este establecimiento no tiene personal del cliente vinculado." (i18n). Sin cambios de props/estructura.

#### Manejo de errores
- `parseApiError` ya expone `fieldErrors`; el error `manager_profile_ids.{i}` del backend se muestra en el bloque de responsables (ya está el `errors.manager_profile_ids`); verificar que el mapeo por índice se colapse en un mensaje del campo.

### Tests (frontend-tester)
- `useSyncEstablishmentStaff` / admin: llama al API correcto e invalida las keys.
- `EstablishmentFormModal`: precarga staff en edición; envía `user_profile_guids`; `[]` permitido.
- `VetProgramFormPage`: las opciones de cliente se filtran por el establecimiento elegido; cambiar de establecimiento limpia los managers de cliente inválidos y conserva los de vet; texto vacío correcto.
- `EstablishmentsSection`: badge "Sin personal vinculado" con `staff_count = 0`.

---

## Orden de implementación

**Fase 1 — Backend base (bloquea todo lo demás)**
1. Migración de esquema `create_establishment_user_profile_table` + relaciones en `Establishment` y `UserProfile`.
2. Migración de backfill + su test unitario.
3. Repos (interface + eloquent): `EstablishmentRepository::syncStaff/hasStaff` + eager loads; `UserProfileRepository::findManyByGuidsForClient` + `listForClient` con `establishments`.
4. Excepciones, evento y listener de desvinculación.
5. `EstablishmentService::syncStaff` / `isProfileLinked` + tests unit.
6. Request `SyncEstablishmentStaffRequest`, `EstablishmentController@syncStaff`, `AdminClientController@establishmentSyncStaff`, rutas, ajustes de `EstablishmentResource` y `UserProfileResource`. Feature tests del endpoint.

**Fase 2 — Reglas en Programs y health plans**
7. `ProgramRepository::unlinkedClientManagerIds`, guard en `ProgramService`, catch en `ProgramController`.
8. `StoreProgramRequest` / `UpdateProgramRequest` (inyectar `EstablishmentService`, validación por índice). Actualizar fixtures/tests existentes de `ProgramControllerTest` y `ProgramServiceTest`; ampliar `ProgramCreatedAlertRecipientsTest`.
9. Guard en `EstablishmentHealthPlanService::confirmActivity` + tests.
10. Seeder `TestDataSeeder` (vínculos de demo).

**Fase 3 — Frontend**
11. Tipos, API, validators, composables de sync (tenant y admin).
12. Selector múltiple en `EstablishmentFormModal` y `AdminEstablishmentFormModal`; chips y badge en `EstablishmentsSection`; columna en listas de staff.
13. `VetProgramFormPage` + `ProgramClientSection` (filtrado de managers por establecimiento).
14. Tests FE.

**Fase 4 — Limpieza de owners (independiente, después de Fase 1-3 verdes)**
15. Retiro de `ClientOwnerController`, `StoreOwnerRequest`, rutas, métodos de service/repo, y su contraparte FE (DEC-08). Verificación por `rg` antes y después.

**Fase futura (fuera de alcance): portal de clientes** — ver Pendientes.

---

## Riesgos y consideraciones

1. **Corte de alertas al desplegar:** mitigado por el backfill (DEC-10). Si el backfill no corre antes de habilitar la validación de programas, los edits de programas existentes con managers de cliente fallarían con 422. Las dos migraciones van juntas en el mismo deploy; el orden de timestamps lo garantiza.
2. **Regenerar alertas al desvincular:** el listener re-dispara `ProgramTargetsChangedEvent`, que borra y recrea las `program.task_due` pendientes (comportamiento ya existente y confirmado por producto para edits). Una alerta `ProgramCreated` con `scheduled_at = now()` puede estar ya en cola: no se revoca (ventana de segundos, aceptado).
3. **Programas con 0 managers tras desvincular:** posible y aceptado a nivel persistencia. El FE del detalle debería tolerar `managers = []` (verificar `ProgramResource` y `ProgramInfoCards`).
4. **Dos pasos en el FE (crear establecimiento + sync):** no es atómico. Si falla el sync, el establecimiento queda sin personal (estado válido) y se reintenta desde edición. Si se quisiera atomicidad, la alternativa es aceptar `user_profile_guids` opcional en `StoreEstablishmentRequest`/`UpdateEstablishmentRequest`; no se hizo para mantener una sola vía de escritura del vínculo.
5. **Discrepancia con el brief (owner inicial):** ver hallazgo 3. No existe hoy creación de owner al crear cliente; el plan no agrega una. Si producto quiere "al crear cliente, pedir un responsable inicial", es una feature nueva (ver Pendientes).
6. **Multi-tenant (regla 4):** el establecimiento se resuelve siempre vía `findByGuidForClient` con el client ya validado contra `current_vet`; `syncStaff` re-valida el client de cada perfil. El listener opera por `establishment_id` sin cruzar vets (un establecimiento pertenece a un único client; el `vet_id` del programa no se altera). Un client puede estar vinculado a más de un vet: los programas de OTRO vet en el mismo establecimiento también se afectan al desvincular. Es correcto (el vínculo es del cliente, no del vet), pero puede sorprender: se documenta en el mensaje del FE al desvincular ("Se quitará como responsable de los programas activos de este establecimiento").
7. **Tests existentes:** los que crean managers de cliente sin vínculo van a romper por diseño (Programs). Se corrigen con un helper de test; no se relaja la regla.
8. **`syncWithoutDetaching` en seeder con `WithoutModelEvents`:** el pivot no usa eventos ni guid, no hay riesgo; los guid de `Establishment`/`UserProfile` ya se setean explícitos en el seeder existente.
9. **Multi-país (regla 5):** el vínculo es agnóstico de país; sin lógica hardcodeada.
10. **Roles de `ProtocolTaskAlert`:** sin cambios. Un protocolo con alerta a `client-owner` solo notificará a owners vinculados al establecimiento del programa (comportamiento deseado); si nadie coincide, el listener ya descarta la alerta silenciosamente (`$recipients->isEmpty()`).

## Pendientes / fuera de alcance

- **Portal/acceso de clientes y scope de visibilidad (DEC-11):** `Program::scopeVisibleToClientProfile`, `paginateForClientProfile`, análogo para health plans, y middleware que resuelva `current_profile` de tipo client. Regla: sin vínculos = nada.
- Vincular establecimientos desde el lado del staff (`UpdateClientStaffRequest` con `establishment_guids`, o vincular al crear staff con `assign`/`new-user`). Hoy el vínculo se gestiona solo desde el establecimiento.
- Alta de cliente con "responsable inicial" y vínculo automático (feature nueva, requiere decisión de producto).
- Borrar las filas huérfanas de permisos `clients.owners.*` de la DB (migración de limpieza) y sus asignaciones en `RoleSeeder`.
- Cuando el portal exista: revisar `CONFIRM_ROLES` y si `client-administrative` debe confirmar actividades.

## Preguntas abiertas (imprescindibles)

1. ¿Confirmás retirar `ClientOwnerController` y `clients.owners.*` (DEC-08)? Se asume sí porque no tiene consumidor en FE; es la única decisión con impacto de API pública.
2. Al desvincular a alguien, ¿se acepta que el cambio afecte también a programas de OTROS vets que atienden al mismo cliente (riesgo 6)? Se asume sí.
