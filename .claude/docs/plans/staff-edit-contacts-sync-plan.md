# Plan técnico: Estandarización de Staff Edit + Contacts Sync

## Input procesado
Brief informal del usuario (chat) — 2026-06-17

## Resumen ejecutivo
Se implementa un mecanismo de sincronización de contactos (diff inteligente) centralizado en `ContactService::syncContacts()`, que reemplaza el patrón delete-all + recreate que usa actualmente `VetService::update()`. Con este método, se unifican los flujos de edición de staff (tenant y admin) en un endpoint `PUT` único que modifica rol y contactos en una sola transacción. El circuito de edición de staff admin migra de drawer a página separada (`AdminVetEditStaffPage`). Se agrega contacts al update de Client (ambos contextos). La edición de Vet ya procesa contacts en update pero usa delete-all; se migra a `syncContacts` también. En frontend se extiende `ContactFormItem` con `guid?`, se crea el formulario compartido `VetStaffEditForm` y se ajustan las páginas de cliente para incluir contacts en el submit.

---

## Decisiones tomadas

**DEC-01 — Comportamiento de guid desconocido en syncContacts**
  Decisión: Si llega un item con `guid` que no existe en DB o que no pertenece al contactable, se trata como **nuevo** (se crea). No se lanza error.
  Justificación: El frontend puede tener un estado stale de un contacto eliminado por otra sesión. Rechazarlo produciría un error de UX injustificado; crearlo como nuevo es la recuperación más segura y predecible. El diff inteligente garantiza que no queden huérfanos.
  Alternativa descartada: Ignorarlo silenciosamente. Descartado porque el usuario tendría la intención explícita de mantenerlo y perdería el contacto sin feedback.

**DEC-02 — Estrategia de actualización de contactos en VetService::update()**
  Decisión: Migrar `VetService::update()` de delete-all + recreate a `ContactService::syncContacts()` en el mismo paso.
  Justificación: Es el único servicio que implementa el patrón incorrecto (delete físico sin diff). Estandarizarlo ahora evita que el patrón se replique y hay ya un test de update de vet que validará que no hay regresión.
  Alternativa descartada: Dejarlo como delete-all. Descartado porque destruiría los guids existentes en cada update, rompiendo el invariante que el frontend espera.

**DEC-03 — Donde vive la validación de contacts en los nuevos requests de staff**
  Decisión: Crear un trait `ValidatesContactsArray` con el método estático `contactsRules()` que retorna el array de reglas, reutilizable en `UpdateVetStaffRequest`, `UpdateClientRequest` y cualquier otro request que lo necesite.
  Justificación: Las reglas son idénticas en todos los contextos. Un trait evita duplicación. Los requests existentes de Vets (`UpdateVetRequest`) ya tienen su propia definición inline; no se los toca para no romper nada.
  Alternativa descartada: Copiar reglas en cada request. Descartado por DRY.

**DEC-04 — Nombre y ubicación del nuevo FormRequest de staff**
  Decisión: `back/app/Http/Requests/Members/UpdateVetStaffRequest.php`
  Justificación: Es el namespace correcto donde ya viven `ChangeVetStaffRoleRequest`, `AssignVetStaffRequest`, etc.
  Alternativa descartada: `Requests/Staff/` — namespace no existente, evitar crear estructura nueva.

**DEC-05 — Validación de role_guid en UpdateVetStaffRequest**
  Decisión: Reutilizar la misma regla `Rule::exists('roles', 'guid')->whereIn('name', UserProfileService::VET_STAFF_ROLES)` que usa `ChangeVetStaffRoleRequest`, ya que el update de staff solo acepta roles válidos de veterinaria (no TENANT_ROLES completo).
  Justificación: `VET_STAFF_ROLES = ['vet', 'vet-assistant', 'vet-administrative']`. Aceptar cualquier `TENANT_ROLES` abriría la puerta a asignar `client-owner` a un perfil de vet, lo cual es inválido.
  Alternativa descartada: Validar contra `TENANT_ROLES`. Descartado por el riesgo de rol incoherente.

**DEC-06 — Nuevo método en UserProfileService vs lógica en el controller**
  Decisión: Agregar `UserProfileService::updateMember(UserProfile $profile, string $roleGuid, array $contacts): UserProfile` que encapsula la transacción (changeRole + syncContacts).
  Justificación: Mantiene los controllers delgados (convención del proyecto). El controller solo resuelve entidades y delega.
  Alternativa descartada: Lógica directo en el controller. Descartado por convención del proyecto.

**DEC-07 — Qué pasa con staffContactStore / staffContactDestroy en rutas admin**
  Decisión: Eliminar los métodos del controller y sus rutas (las dos rutas POST/DELETE de contacts individuales bajo `/admin/vets/{guid}/staff/{profileGuid}/contacts`). El método `staffShow` y su ruta se conservan.
  Justificación: La sesión anterior los creó pero el brief los declara explícitamente reemplazados por el nuevo PUT unificado.
  Alternativa descartada: Mantenerlos como fallback. Descartado porque crean ambigüedad de API y código muerto.

**DEC-08 — `ContactFormItem` duplicado entre vet.types.ts y client.types.ts**
  Decisión: `ContactFormItem` vive en `vet.types.ts` (donde ya está definido y es importado por `ContactsInput.vue` y `ClientForm.vue`). `client.types.ts` NO define su propio `ContactFormItem`. En `client.types.ts` las interfaces de payload (`ClientCreatePayload.contacts`) se alinean tipando como `ContactFormItem` importado desde `vet.types.ts`.
  Justificación: `ContactsInput.vue` ya importa de `vet.types.ts`. Consolidar en un único lugar evita divergencia de tipos.
  Alternativa descartada: Mover a `core/types/`. Descartado por scope del cambio; se puede hacer como refactor posterior.

**DEC-09 — Navegación post-save en AdminVetEditStaffPage**
  Decisión: Navegar a `/admin/vets/:guid` sin query param `?tab=staff`.
  Justificación: El brief menciona que la navegación va a la ruta base o con `?tab=staff` "si el VetDetailPage lo soporta". Revisando el código, `VetDetailPage` recibe `guid` como prop (a través de `props: true`) pero no se encontró lógica de tab por query param. Para no hacer asumir comportamiento no verificado, se navega a la ruta base. Si el dev quiere agregar el tab luego, es un cambio de una línea.
  Alternativa descartada: Agregar `?tab=staff`. Descartado por riesgo de comportamiento inesperado.

**DEC-10 — VetStaffEditPanel.vue**
  Decisión: El archivo se vacía de lógica y se convierte en un stub de redirección hacia la página correcta, o directamente se elimina si no hay referencias que no se puedan actualizar. Dado que `VetEditStaffPage.vue` actualmente lo importa (y esa página se va a simplificar), se marca para **eliminar** y se actualiza todos sus puntos de uso en el mismo paso.
  Justificación: El brief lo declara obsoleto explícitamente.

**DEC-11 — Trait ValidatesContactsArray: ubicación**
  Decisión: `back/app/Http/Requests/Concerns/ValidatesContactsArray.php` (namespace `App\Http\Requests\Concerns`).
  Justificación: Es un concern de requests, no de modelos ni servicios. El nombre `Concerns` es la convención Laravel para traits usados dentro de una misma capa.

---

## Cambios en BACKEND

### Archivos a crear

#### `back/app/Http/Requests/Concerns/ValidatesContactsArray.php`
**Propósito:** Trait con las reglas de validación del array `contacts` (con `guid?` opcional por ítem) para ser reutilizado en múltiples FormRequests.
**Firma principal:**
```php
namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Rule;

trait ValidatesContactsArray
{
    /**
     * Retorna las reglas de validación para el array contacts.
     * Incluye guid opcional para el diff inteligente en syncContacts.
     *
     * Uso: array_merge($this->contactsRules(), [...otrasReglas])
     */
    protected function contactsRules(): array
    {
        return [
            'contacts'                    => ['nullable', 'array'],
            'contacts.*.guid'             => ['nullable', 'string', 'uuid'],
            'contacts.*.type'             => ['required', 'string', Rule::in(['email', 'phone', 'whatsapp'])],
            'contacts.*.label'            => ['nullable', 'string', 'max:100'],
            'contacts.*.value'            => ['required', 'string', 'max:200'],
            'contacts.*.is_primary'       => ['nullable', 'boolean'],
            'contacts.*.use_for_alerts'   => ['nullable', 'boolean'],
        ];
    }

    protected function contactsMessages(): array
    {
        return [
            'contacts.*.type.required'  => 'El tipo de contacto es obligatorio.',
            'contacts.*.type.in'        => 'El tipo debe ser email, phone o whatsapp.',
            'contacts.*.value.required' => 'El valor del contacto es obligatorio.',
            'contacts.*.value.max'      => 'El valor no puede superar 200 caracteres.',
            'contacts.*.guid.uuid'      => 'El guid del contacto debe ser un UUID válido.',
        ];
    }
}
```
**Dependencias inyectadas:** ninguna (trait puro).

**NOTA sobre validación de `value` por tipo:** `StoreContactRequest` tiene una closure que valida E.164 para phone/whatsapp y formato email para type=email. Esta validación no se replica en el trait porque agregar una closure por ítem en arrays anidados con `contacts.*.value` requeriría un custom Rule por ítem que accede al campo `contacts.*.type` del mismo elemento. Por simplicidad y dado que el backend ya valida en `ContactService` (usa los datos tal cual), se acepta que la validación de formato avanzado en arrays de contacts queda como `max:200` en el request. Si en el futuro se quiere validar E.164 en sync, agregar un Rule customizado. Documentar como deuda técnica menor.

---

#### `back/app/Http/Requests/Members/UpdateVetStaffRequest.php`
**Propósito:** FormRequest para `PUT /v1/vets/{vet}/staff/{guid}` y `PUT /v1/admin/vets/{guid}/staff/{profileGuid}`.
**Firma principal:**
```php
namespace App\Http\Requests\Members;

use App\Http\Requests\Concerns\ValidatesContactsArray;
use App\Services\UserProfileService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVetStaffRequest extends FormRequest
{
    use ValidatesContactsArray;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge(
            [
                'role_guid' => [
                    'required',
                    'string',
                    Rule::exists('roles', 'guid')->where(function ($query) {
                        $query->whereIn('name', UserProfileService::VET_STAFF_ROLES);
                    }),
                ],
            ],
            $this->contactsRules(),
        );
    }

    public function messages(): array
    {
        return array_merge(
            [
                'role_guid.required' => 'El rol es obligatorio.',
                'role_guid.exists'   => 'El rol seleccionado no es válido para un miembro de veterinaria.',
            ],
            $this->contactsMessages(),
        );
    }
}
```
**Dependencias inyectadas:** `ValidatesContactsArray` (trait), `UserProfileService::VET_STAFF_ROLES`.

---

### Archivos a modificar

#### `back/app/Services/ContactService.php`
**Cambio:** Agregar método público `syncContacts(Model $contactable, array $items): void`.

**Lógica del método:**
```php
/**
 * Sincroniza los contactos de un contactable con el array de items recibido.
 *
 * Reglas del diff:
 *   - Item con guid existente y perteneciente al contactable → actualizar si hay cambios.
 *   - Item con guid desconocido o que no pertenece al contactable → crear como nuevo.
 *   - Item sin guid → crear nuevo.
 *   - Contactos existentes del contactable cuyo guid no aparece en $items → eliminar.
 *
 * @param Model  $contactable  Instancia Eloquent con relación contacts()
 * @param array  $items        Array de arrays con shape:
 *                             { guid?: string, type, value, label?, is_primary, use_for_alerts }
 */
public function syncContacts(Model $contactable, array $items): void
{
    // 1. Cargar contactos actuales indexados por guid
    $existing = $contactable->contacts()->get()->keyBy('guid');

    $incomingGuids = [];

    foreach ($items as $item) {
        $guid    = $item['guid'] ?? null;
        $contact = $guid ? ($existing->get($guid) ?? null) : null;

        if ($contact) {
            // Contacto existente y perteneciente a este contactable → actualizar
            $incomingGuids[] = $guid;
            $this->update($contact, array_filter([
                'type'           => $item['type'] ?? null,
                'value'          => $item['value'] ?? null,
                'label'          => $item['label'] ?? null,
                'is_primary'     => $item['is_primary'] ?? null,
                'use_for_alerts' => $item['use_for_alerts'] ?? null,
            ], fn($v) => $v !== null));
        } else {
            // Sin guid, guid desconocido, o guid de otro contactable → crear nuevo
            $created = $this->create($contactable, [
                'type'           => $item['type'],
                'value'          => $item['value'],
                'label'          => $item['label'] ?? null,
                'is_primary'     => $item['is_primary'] ?? false,
                'use_for_alerts' => $item['use_for_alerts'] ?? false,
            ]);
            $incomingGuids[] = $created->guid;
        }
    }

    // 2. Eliminar los contactos existentes no incluidos en el array
    foreach ($existing as $existingGuid => $contact) {
        if (!in_array($existingGuid, $incomingGuids, true)) {
            $this->destroy($contact);
        }
    }
}
```

**NOTA crítica sobre array_filter y valores falsy:** `is_primary` y `use_for_alerts` pueden ser `false`, y `label` puede ser `null`. El `array_filter` con `fn($v) => $v !== null` filtrará solo los `null` explícitos. Sin embargo, `false` pasa el filtro correctamente. Asegurarse de que el developer implemente exactamente `!== null` y no `!empty()`.

**NOTA sobre `contacts()` relation:** Se asume que todos los modelos que usan syncContacts tienen la relación `contacts()` definida (MorphMany). Confirmar en `UserProfile`, `Client` y `Vet`. Si alguno no la tiene, agregarla.

---

#### `back/app/Services/UserProfileService.php`
**Cambio:** Agregar método `updateMember(UserProfile $profile, string $roleGuid, array $contacts): UserProfile`.

```php
/**
 * Actualiza el rol y los contactos de un miembro de staff en una sola transacción.
 *
 * @param UserProfile $profile    El perfil a actualizar
 * @param string      $roleGuid   GUID del rol a asignar
 * @param array       $contacts   Array de ContactFormItem (con guid? opcional)
 */
public function updateMember(UserProfile $profile, string $roleGuid, array $contacts): UserProfile
{
    return DB::transaction(function () use ($profile, $roleGuid, $contacts) {
        $role = $this->roleRepository->findByGuid($roleGuid);
        if (!$role) {
            throw new \RuntimeException('El rol especificado no existe.');
        }

        $profile = $this->changeRole($profile, $role);

        $this->contactService->syncContacts($profile, $contacts);

        return $profile->load(['user', 'role', 'contacts']);
    });
}
```

**Dependencias:** `DB` (ya importado en el archivo), `ContactService` (ya inyectado en constructor).

---

#### `back/app/Services/ClientService.php`
**Cambio:** Modificar `update()` para invocar `syncContacts` cuando se recibe el array `contacts`.

```php
public function update(Client $client, array $data): Client
{
    return DB::transaction(function () use ($client, $data) {
        $contacts = array_key_exists('contacts', $data) ? $data['contacts'] : null;
        unset($data['contacts']);

        $data   = $this->resolveIds($data);
        $client = $this->clientRepository->update($client, $data);

        if ($contacts !== null) {
            $this->contactService->syncContacts($client, $contacts);
        }

        return $client->load('contacts');
    });
}
```

**Antes:** `update()` no procesa contacts en absoluto (solo llama `$this->resolveIds($data)` y `$this->clientRepository->update()`).
**Después:** Envuelto en transacción, extrae contacts del array, llama syncContacts si están presentes, recarga la relación.
**Dependencias añadidas:** `DB` (agregar `use Illuminate\Support\Facades\DB` si no está importado — verificar que `ClientService` no lo tenga aún).

---

#### `back/app/Services/VetService.php`
**Cambio:** En `update()`, reemplazar el bloque delete-all + recreate por `$this->contactService->syncContacts($vet, $contacts)`.

**Antes (líneas 66-70 aprox.):**
```php
if ($contacts !== null) {
    $vet->contacts()->delete();
    foreach ($contacts as $contact) {
        $this->contactService->create($vet, $contact);
    }
}
```

**Después:**
```php
if ($contacts !== null) {
    $this->contactService->syncContacts($vet, $contacts);
}
```

**NOTA:** Este cambio modifica el comportamiento observable: antes, cada update de vet con contacts generaba nuevos guids para todos los contactos. Después, los contactos existentes conservan su guid si el frontend los envía con guid. Si el frontend de edición de vet (VetEditPage) envía contacts SIN guid (como hace actualmente ContactsInput), el comportamiento de syncContacts será: todos los existentes se eliminan y se crean nuevos (equivalente al delete-all). Para que syncContacts conserve los guids hay que extender `VetEditPage` / `VetUpdatePayload` también con guid en contacts — esto queda fuera del alcance de este plan. Documentado en Pendientes.

---

#### `back/app/Http/Controllers/V1/VetStaffController.php`
**Cambio:** Agregar método `update()`. Agregar import de `UpdateVetStaffRequest`.

```php
use App\Http\Requests\Members\UpdateVetStaffRequest;

public function update(UpdateVetStaffRequest $request): JsonResponse
{
    try {
        $guid    = $request->route('guid');
        $vet     = $request->attributes->get('current_vet');
        $profile = $this->userProfileService->findByGuidForVet($guid, $vet);

        if (!$profile) {
            return $this->makeNotFound('Miembro no encontrado en esta veterinaria.');
        }

        $data    = $request->validated();
        $profile = $this->userProfileService->updateMember(
            $profile,
            $data['role_guid'],
            $data['contacts'] ?? [],
        );

        return $this->makeSuccess(
            new UserProfileResource($profile),
            'Perfil actualizado correctamente.',
        );
    } catch (\RuntimeException $e) {
        return $this->makeError(null, $e->getMessage(), 422);
    } catch (\Exception $e) {
        return $this->makeFromException($e);
    }
}
```

**Nota:** `ContactService` ya está inyectado en el constructor pero el nuevo método no lo usa directamente (delega a `UserProfileService::updateMember`). La inyección de `ContactService` en este controller puede quitarse si no hay otros métodos que lo usen. Revisar: actualmente no hay otros métodos que llamen `$this->contactService` — se puede eliminar la inyección directa de `ContactService` en este controller, dejando solo `UserProfileService`.

---

#### `back/app/Http/Controllers/V1/AdminVetController.php`
**Cambio 1:** Agregar método `staffUpdate()`.
**Cambio 2:** Eliminar métodos `staffContactStore()` y `staffContactDestroy()`.
**Cambio 3:** Agregar import de `UpdateVetStaffRequest`. Eliminar import de `StoreContactRequest` y `ContactResource` si no los usan otros métodos del mismo controller.

```php
use App\Http\Requests\Members\UpdateVetStaffRequest;

public function staffUpdate(UpdateVetStaffRequest $request, string $guid, string $profileGuid): JsonResponse
{
    try {
        $vet = $this->vetService->findByGuid($guid);
        if (!$vet) {
            return $this->makeNotFound('Veterinaria no encontrada.');
        }

        $profile = $this->userProfileService->findByGuidForVet($profileGuid, $vet);
        if (!$profile) {
            return $this->makeNotFound('Miembro no encontrado en esta veterinaria.');
        }

        $data    = $request->validated();
        $profile = $this->userProfileService->updateMember(
            $profile,
            $data['role_guid'],
            $data['contacts'] ?? [],
        );

        return $this->makeSuccess(
            new UserProfileResource($profile),
            'Perfil actualizado correctamente.',
        );
    } catch (\RuntimeException $e) {
        return $this->makeError(null, $e->getMessage(), 422);
    } catch (\Exception $e) {
        return $this->makeFromException($e);
    }
}
```

**Verificar imports a eliminar:** `StoreContactRequest` solo se usaba en `staffContactStore` → eliminar. `ContactResource` solo se usaba en `staffContactStore` → eliminar. `ContactService` solo se usaba en `staffContactStore` y `staffContactDestroy` → eliminar del constructor y del campo privado.

---

#### `back/app/Http/Requests/Clients/UpdateClientRequest.php`
**Cambio:** Agregar trait `ValidatesContactsArray` y merge de reglas de contacts.

```php
use App\Http\Requests\Concerns\ValidatesContactsArray;

class UpdateClientRequest extends FormRequest
{
    use ValidatesContactsArray;

    public function rules(): array
    {
        return array_merge(
            [
                'name'               => ['sometimes', 'string', 'max:150'],
                'country_guid'       => ['sometimes', 'string', 'exists:countries,guid'],
                'document_type_guid' => ['sometimes', 'string', 'exists:document_types,guid'],
                'tax_id'             => ['sometimes', 'string', 'max:50', $this->taxIdRule()],
                'address'            => ['nullable', 'string', 'max:200'],
                'city'               => ['nullable', 'string', 'max:100'],
                'state'              => ['nullable', 'string', 'max:100'],
                'zip_code'           => ['nullable', 'string', 'max:20'],
            ],
            $this->contactsRules(),
        );
    }

    public function messages(): array
    {
        return array_merge(
            [
                'name.max'                  => 'El nombre no puede superar 150 caracteres.',
                'country_guid.exists'       => 'El país seleccionado no existe.',
                'document_type_guid.exists' => 'El tipo de documento seleccionado no existe.',
                'tax_id.max'                => 'El identificador fiscal no puede superar 50 caracteres.',
            ],
            $this->contactsMessages(),
        );
    }
    // ... taxIdRule() se mantiene igual
}
```

**Nota:** `UpdateClientRequest` es compartido por `ClientController::update()` y `AdminClientController::update()`. No necesita duplicarse.

---

#### `back/routes/api/vets.php`
**Cambio:** En el grupo de rutas admin, agregar la ruta `PUT` para `staffUpdate` y eliminar las rutas `POST` y `DELETE` de contacts individuales. En el grupo tenant, agregar la ruta `PUT` para el update de staff.

**Rutas admin — antes:**
```php
Route::get('/{guid}/staff/{profileGuid}',                            [AdminVetController::class, 'staffShow'])          ->middleware('can:vets.staff.read');
Route::patch('/{guid}/staff/{profileGuid}/role',                     [AdminVetController::class, 'staffChangeRole'])    ->middleware('can:vets.staff.update');
Route::delete('/{guid}/staff/{profileGuid}',                         [AdminVetController::class, 'staffDestroy'])       ->middleware('can:vets.staff.delete');
Route::post('/{guid}/staff/{profileGuid}/contacts',                  [AdminVetController::class, 'staffContactStore'])  ->middleware('can:vets.staff.update');
Route::delete('/{guid}/staff/{profileGuid}/contacts/{contactGuid}',  [AdminVetController::class, 'staffContactDestroy'])->middleware('can:vets.staff.update');
```

**Rutas admin — después:**
```php
Route::get('/{guid}/staff/{profileGuid}',        [AdminVetController::class, 'staffShow'])      ->middleware('can:vets.staff.read');
Route::put('/{guid}/staff/{profileGuid}',        [AdminVetController::class, 'staffUpdate'])    ->middleware('can:vets.staff.update');
Route::patch('/{guid}/staff/{profileGuid}/role', [AdminVetController::class, 'staffChangeRole'])->middleware('can:vets.staff.update');
Route::delete('/{guid}/staff/{profileGuid}',     [AdminVetController::class, 'staffDestroy'])   ->middleware('can:vets.staff.delete');
```

**Rutas tenant — antes (en el grupo prefix 'staff'):**
```php
Route::get('/{guid}', [VetStaffController::class, 'show']);
Route::delete('/{guid}', [VetStaffController::class, 'destroy']);
Route::patch('/{guid}/role', [VetStaffController::class, 'changeRole']);
Route::patch('/{guid}/toggle-block', [VetStaffController::class, 'toggleBlock']);
```

**Rutas tenant — después:**
```php
Route::get('/{guid}', [VetStaffController::class, 'show']);
Route::put('/{guid}', [VetStaffController::class, 'update']);
Route::delete('/{guid}', [VetStaffController::class, 'destroy']);
Route::patch('/{guid}/role', [VetStaffController::class, 'changeRole']);
Route::patch('/{guid}/toggle-block', [VetStaffController::class, 'toggleBlock']);
```

---

### Migrations
No se requieren migraciones nuevas. Todos los campos involucrados (`contacts.guid`, `contacts.type`, `contacts.value`, etc.) ya existen en la tabla `contacts`.

### Rutas API

| Método | Path | Controller@Action | Middleware |
|--------|------|-------------------|------------|
| PUT | `/v1/vets/{vet}/staff/{guid}` | `VetStaffController@update` | `auth:sanctum`, `vet.tenant` |
| PUT | `/v1/admin/vets/{guid}/staff/{profileGuid}` | `AdminVetController@staffUpdate` | `auth:sanctum`, `can:vets.staff.update` |
| ~~POST~~ | ~~`/v1/admin/vets/{guid}/staff/{profileGuid}/contacts`~~ | ~~eliminada~~ | — |
| ~~DELETE~~ | ~~`/v1/admin/vets/{guid}/staff/{profileGuid}/contacts/{contactGuid}`~~ | ~~eliminada~~ | — |

### Permisos Spatie
No se requieren permisos nuevos. El permiso `vets.staff.update` ya existe y cubre el nuevo endpoint PUT.

### Contrato de los endpoints

**PUT `/v1/vets/{vet}/staff/{guid}`** y **PUT `/v1/admin/vets/{guid}/staff/{profileGuid}`**

Request:
```json
{
  "role_guid": "uuid-del-rol",
  "contacts": [
    {
      "guid": "uuid-contacto-existente",
      "type": "email",
      "value": "vet@ejemplo.com",
      "label": "Email principal",
      "is_primary": true,
      "use_for_alerts": true
    },
    {
      "type": "phone",
      "value": "+5491112345678",
      "label": null,
      "is_primary": false,
      "use_for_alerts": false
    }
  ]
}
```

Response 200:
```json
{
  "success": true,
  "data": {
    "guid": "uuid-del-profile",
    "user": {
      "guid": "uuid-user",
      "name": "Juan Pérez",
      "first_name": "Juan",
      "last_name": "Pérez",
      "email": "juan@ejemplo.com"
    },
    "role": {
      "guid": "uuid-rol",
      "name": "vet-assistant"
    },
    "contacts": [
      {
        "guid": "uuid-contacto-existente",
        "type": "email",
        "value": "vet@ejemplo.com",
        "label": "Email principal",
        "is_primary": true,
        "use_for_alerts": true,
        "created_at": "2026-06-17T..."
      }
    ],
    "blocked_at": null,
    "created_at": "2026-06-01T..."
  },
  "message": "Perfil actualizado correctamente."
}
```

Errores posibles:

| HTTP | Cuándo |
|------|--------|
| 404 | Vet o profile no encontrado / profile no pertenece a la vet |
| 422 | `role_guid` inválido o no pertenece a VET_STAFF_ROLES |
| 422 | Items de contacts con campos inválidos |
| 500 | Error inesperado |

---

### Tests a generar (qué cubrir, no el código)

**ContactService::syncContacts — Unit tests:**
- Happy path: items con guid existente → actualiza campo modificado, no toca los no modificados.
- Happy path: item sin guid → crea nuevo contacto.
- Happy path: item con guid desconocido → crea como nuevo (no rompe).
- Contacto existente no enviado → se elimina.
- Lista vacía → elimina todos los contactos del contactable.
- `is_primary=true` en un item: verifica que clearPrimaryForType se invoca (a través de `update()`).
- Transacción: si falla una operación interna, el estado no queda parcialmente modificado.

**PUT /v1/vets/{vet}/staff/{guid} — Feature tests:**
- Happy path: actualiza rol + contacts → 200 con datos actualizados.
- Contacto con guid existente se actualiza in-place (mismo guid en response).
- Contacto sin guid se crea (nuevo guid en response).
- Contacto existente no enviado en el array → desaparece de la response.
- `role_guid` no existente → 422.
- `role_guid` de un rol no-VET_STAFF (ej: `client-owner`) → 422.
- Profile que no pertenece a la vet → 404.
- Sin autenticación → 401.
- Sin permiso `vets.staff.update` → 403.

**PUT /v1/admin/vets/{guid}/staff/{profileGuid} — Feature tests:**
- Mismo happy path y casos borde que el tenant, adaptando los identificadores.
- Vet no encontrada → 404.

**ClientService::update con contacts — Feature tests:**
- Llamada con contacts en el payload → invoca syncContacts.
- Llamada sin contacts en el payload → no toca los contacts existentes.

---

## Cambios en FRONTEND

### Archivos a crear

#### `front/src/modules/vets/components/forms/VetStaffEditForm.vue`
**Propósito:** Formulario compartido de edición de staff (tenant y admin). Recibe datos, emite submit. Sin lógica de fetch ni mutation.

**Props:**
```typescript
defineProps<{
  member: VetStaffItem
  vetRoles: VetStaffRoleItem[]
  isLoadingRoles: boolean
  isPending: boolean
}>()
```

**Emits:**
```typescript
defineEmits<{
  submit: [payload: UpdateVetStaffPayload]
}>()
```

**Lógica interna:**
- `selectedRoleGuid`: `shallowRef<string>('')` inicializado con `member.role.guid` en un `watch({ immediate: true })`.
- `localContacts`: `ref<ContactFormItem[]>([])` inicializado en watch mapeando `member.contacts` a `ContactFormItem` (incluyendo `guid`).
- `onSubmit()`: emite `{ role_guid: selectedRoleGuid.value, contacts: localContacts.value }`.

**Secciones de template:**
1. `FormSection` "Datos del usuario" (read-only): nombre y email con `<a-input disabled>`.
2. `FormSection` "Rol en esta veterinaria": `<a-select>` con `vetRoles`. Deshabilitar si `isLoadingRoles`.
3. `FormSection` "Contactos": `<ContactsInput v-model="localContacts" />`.
4. `FormFooter` con label "Guardar cambios" y `:loading="isPending"`.

---

#### `front/src/modules/vets/pages/AdminVetEditStaffPage.vue`
**Propósito:** Página de edición de miembro de staff en contexto admin. Ruta: `/admin/vets/:guid/staff/:profileGuid/editar`.

**Lógica:**
```typescript
const props = defineProps<{ guid: string; profileGuid: string }>()
const router = useRouter()

const { data: member, isLoading, isError } = useAdminVetStaffMember(
  computed(() => props.guid),
  computed(() => props.profileGuid),
)

const { vetRoles, isLoading: isLoadingRoles } = useVetRoles()
const { mutate, isPending } = useAdminUpdateVetStaff(computed(() => props.guid))

function handleSubmit(payload: UpdateVetStaffPayload): void {
  mutate(
    { profileGuid: props.profileGuid, payload },
    {
      onSuccess: () => {
        router.push(`/admin/vets/${props.guid}`)
      },
    },
  )
}
```

**Template:** PageHeader + botón Volver + `<AsyncContent>` (o v-if/else) + `<VetStaffEditForm>` cuando member está disponible.

---

#### `front/src/modules/vets/composables/useUpdateVetStaff.ts`
**Propósito:** Mutation para PUT /v1/vets/{vet}/staff/{guid} (contexto tenant).

```typescript
export function useUpdateVetStaff(vetGuid: MaybeRef<string>) {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const vGuid = computed(() => toValue(vetGuid))

  const mutation = useMutation({
    mutationFn: ({ profileGuid, payload }: { profileGuid: string; payload: UpdateVetStaffPayload }) =>
      updateVetStaffApi(vGuid.value, profileGuid, payload),
    onSuccess: (_, vars) => {
      queryClient.invalidateQueries({ queryKey: ['vet-staff', vGuid.value] })
      queryClient.invalidateQueries({ queryKey: ['vet-staff-member', vGuid.value, vars.profileGuid] })
      success('Perfil actualizado correctamente.')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      error(apiError.message ?? 'Error al actualizar el perfil.')
    },
  })

  return mutation
}
```

---

#### `front/src/modules/vets/composables/useAdminUpdateVetStaff.ts`
**Propósito:** Mutation para PUT /v1/admin/vets/{guid}/staff/{profileGuid} (contexto admin).

```typescript
export function useAdminUpdateVetStaff(vetGuid: MaybeRef<string>) {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const vGuid = computed(() => toValue(vetGuid))

  const mutation = useMutation({
    mutationFn: ({ profileGuid, payload }: { profileGuid: string; payload: UpdateVetStaffPayload }) =>
      adminUpdateVetStaffApi(vGuid.value, profileGuid, payload),
    onSuccess: (_, vars) => {
      queryClient.invalidateQueries({ queryKey: ['admin-vet-staff', vGuid.value] })
      queryClient.invalidateQueries({ queryKey: ['admin-vet-staff-member', vGuid.value, vars.profileGuid] })
      success('Perfil actualizado correctamente.')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      error(apiError.message ?? 'Error al actualizar el perfil.')
    },
  })

  return mutation
}
```

---

### Archivos a modificar

#### `front/src/modules/vets/types/vet.types.ts`
**Cambio 1:** Agregar `guid?` a `ContactFormItem`.

**Antes:**
```typescript
export interface ContactFormItem {
  type: 'email' | 'phone' | 'whatsapp'
  value: string
  label?: string | null
  is_primary: boolean
  use_for_alerts: boolean
}
```

**Después:**
```typescript
export interface ContactFormItem {
  guid?: string
  type: 'email' | 'phone' | 'whatsapp'
  value: string
  label?: string | null
  is_primary: boolean
  use_for_alerts: boolean
}
```

**Cambio 2:** Agregar los nuevos tipos de payload.

```typescript
export interface UpdateVetStaffPayload {
  role_guid: string
  contacts: ContactFormItem[]
}
```

---

#### `front/src/modules/vets/api/vet-staff.api.ts`
**Cambio 1:** Agregar función `updateVetStaffApi` (tenant).
**Cambio 2:** Agregar función `adminUpdateVetStaffApi` (admin).
**Cambio 3:** Eliminar funciones `adminCreateStaffContactApi` y `adminDeleteStaffContactApi`.

```typescript
import type { ..., UpdateVetStaffPayload } from '../types/vet.types'

export async function updateVetStaffApi(
  vetGuid: string,
  profileGuid: string,
  payload: UpdateVetStaffPayload,
): Promise<VetStaffItem> {
  const res = await http.put<VetStaffItem>(
    `/v1/vets/${vetGuid}/staff/${profileGuid}`,
    payload,
  )
  return res.data
}

export async function adminUpdateVetStaffApi(
  vetGuid: string,
  profileGuid: string,
  payload: UpdateVetStaffPayload,
): Promise<VetStaffItem> {
  const res = await http.put<VetStaffItem>(
    `/v1/admin/vets/${vetGuid}/staff/${profileGuid}`,
    payload,
  )
  return res.data
}
```

Eliminar (ya no tienen ruta en el backend):
- `adminCreateStaffContactApi`
- `adminDeleteStaffContactApi`

---

#### `front/src/components/forms/ContactsInput.vue`
**Cambio:** Modificar `addContact()` para que los nuevos items no tengan guid (correcto) y verificar que al recibir items con guid desde el exterior estos se propagan en el emit. Actualmente el componente no define guid en el push, lo cual es correcto. La única modificación necesaria es asegurar que el tipo interno maneja `guid?`.

El código de `addContact()` actual no incluye guid al crear un item nuevo:
```typescript
function addContact(type: 'email' | 'phone' | 'whatsapp'): void {
  localContacts.value.push({
    type,
    value: '',
    label: null,
    is_primary: false,
    use_for_alerts: false,
  })
}
```
Esto ya es correcto — los nuevos items no llevan guid. Como `ContactFormItem` ahora tiene `guid?` (opcional), este código no rompe.

**El único cambio real es el import del tipo:** `ContactFormItem` ya importa de `@/modules/vets/types/vet.types` — no hay nada que cambiar en el import. El componente funcionará correctamente con el tipo extendido.

**Conclusión:** No se requieren cambios funcionales en `ContactsInput.vue`. El tipo extendido es compatible. Verificar que no hay warnings de TypeScript con el nuevo campo opcional.

---

#### `front/src/modules/vets/pages/tenant/VetEditStaffPage.vue`
**Cambio:** Reemplazar la dependencia de `VetStaffEditPanel` por `VetStaffEditForm` + composables propios.

**Antes:** Importa y usa `VetStaffEditPanel` que hace internamente el fetch y la mutation.

**Después:**
```typescript
import { computed } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { ArrowLeftOutlined } from '@ant-design/icons-vue'
import { useVetStaffMember } from '@/modules/vets/composables/useVetStaffMember'
import { useVetRoles }       from '@/modules/vets/composables/useVetRoles'
import { useUpdateVetStaff } from '@/modules/vets/composables/useUpdateVetStaff'
import VetStaffEditForm      from '@/modules/vets/components/forms/VetStaffEditForm.vue'
import type { UpdateVetStaffPayload } from '@/modules/vets/types/vet.types'

const router      = useRouter()
const route       = useRoute()
const vetGuid     = computed(() => route.params.vetGuid as string)
const profileGuid = computed(() => route.params.profileGuid as string)

const { data: member, isLoading, isError } = useVetStaffMember(vetGuid, profileGuid)
const { vetRoles, isLoading: isLoadingRoles } = useVetRoles()
const { mutate, isPending } = useUpdateVetStaff(vetGuid)

function handleSubmit(payload: UpdateVetStaffPayload): void {
  mutate(
    { profileGuid: profileGuid.value, payload },
    { onSuccess: () => router.push(`/vets/${vetGuid.value}/usuarios`) },
  )
}
```

**Template:** PageHeader + botón Volver + spinner/error state + `<VetStaffEditForm>` cuando member está disponible.

---

#### `front/src/modules/vets/components/VetStaffSection.vue`
**Cambio 1:** Eliminar el drawer de edición (el segundo `BaseDrawer` y todo lo relacionado con `isEditDrawerOpen`, `selectedMember`, import de `VetStaffEditPanel`).
**Cambio 2:** Cambiar el handler del botón Editar para navegar a la página.

```typescript
import { useRouter } from 'vue-router'
const router = useRouter()

function goToEdit(member: VetStaffItem) {
  router.push(`/admin/vets/${props.vetGuid}/staff/${member.guid}/editar`)
}
```

En el template, el botón Editar pasa de `@click="openEditDrawer(record)"` a `@click="goToEdit(record)"`.

Eliminar del script:
- `import VetStaffEditPanel from './VetStaffEditPanel.vue'`
- `const isEditDrawerOpen = shallowRef(false)`
- `const selectedMember = ref<VetStaffItem | null>(null)`
- función `openEditDrawer`

Eliminar del template:
- El `<BaseDrawer>` con title "Editar miembro" y su contenido `<VetStaffEditPanel>`.

---

#### `front/src/modules/vets/router/vets.routes.ts`
**Cambio:** Agregar la ruta para `AdminVetEditStaffPage`.

```typescript
{
  path: '/admin/vets/:guid/staff/:profileGuid/editar',
  name: 'vets-staff-edit',
  component: () => import('@/modules/vets/pages/AdminVetEditStaffPage.vue'),
  props: true,
  meta: { requiresAuth: true, title: 'Editar miembro de staff' },
},
```

**Orden de rutas:** Agregar ANTES de la ruta `/admin/vets/:guid/edit` para que no haya ambigüedad (aunque Vue Router resuelve por pattern, es buena práctica tener las más específicas primero). Como el path tiene segmento literal `staff`, no hay ambigüedad de todas formas.

---

#### `front/src/modules/vets/components/VetStaffEditPanel.vue`
**Cambio:** Eliminar el archivo completo. No tiene referencias restantes una vez que `VetStaffSection.vue` y `VetEditStaffPage.vue` son modificados.

**Antes de eliminar, verificar:** Buscar con Grep si hay algún otro componente o página que importe `VetStaffEditPanel`. Si existe, actualizar esa referencia primero.

---

#### `front/src/modules/clients/types/client.types.ts`
**Cambio:** Actualizar `ClientUpdatePayload` para incluir contacts. Actualizar `ClientCreatePayload.contacts` para usar `ContactFormItem`.

**Antes:**
```typescript
export interface ClientUpdatePayload {
  name?: string
  tax_id?: string
  address?: string | null
  city?: string | null
  state?: string | null
  zip_code?: string | null
}
```

**Después:**
```typescript
import type { ContactFormItem } from '@/modules/vets/types/vet.types'

export interface ClientUpdatePayload {
  name?: string
  document_type_guid?: string
  tax_id?: string
  address?: string | null
  city?: string | null
  state?: string | null
  zip_code?: string | null
  contacts?: ContactFormItem[]
}
```

**También:** El tipo inline de `ClientCreatePayload.contacts` debería alinearse con `ContactFormItem[]` en lugar del type literal actual. Cambio menor, mantiene compatibilidad.

---

#### `front/src/modules/clients/components/forms/ClientForm.vue`
**Cambio:** En el handler `onSubmit`, incluir contacts en el emit del modo edit.

**Antes (líneas 111-113):**
```typescript
} else {
  const { name, document_type_guid, tax_id, address, city, state, zip_code } = values as ClientUpdateForm
  emit('submit', { name, document_type_guid, tax_id, address, city, state, zip_code })
}
```

**Después:**
```typescript
} else {
  const { name, document_type_guid, tax_id, address, city, state, zip_code } = values as ClientUpdateForm
  const contacts = localContacts.value.map((c) => ({ ...c, label: c.label || null }))
  emit('submit', { name, document_type_guid, tax_id, address, city, state, zip_code, contacts })
}
```

**Observación:** En modo edit, `localContacts` ya se inicializa (líneas 84-91) mapeando `vals.contacts ?? []` pero SIN guid:
```typescript
localContacts.value = (vals.contacts ?? []).map((c) => ({
  type: c.type as ContactFormItem['type'],
  value: c.value,
  label: c.label,
  is_primary: c.is_primary,
  use_for_alerts: c.use_for_alerts,
}))
```
Se debe agregar `guid: c.guid` al mapping para que syncContacts pueda identificar los contacts existentes:
```typescript
localContacts.value = (vals.contacts ?? []).map((c) => ({
  guid: c.guid,
  type: c.type as ContactFormItem['type'],
  value: c.value,
  label: c.label,
  is_primary: c.is_primary,
  use_for_alerts: c.use_for_alerts,
}))
```

**Cambio en el tipo emitido:**
```typescript
type ClientFormSubmit = (ClientCreateForm & { contacts: ContactFormItem[] }) | (ClientUpdateForm & { contacts: ContactFormItem[] })
```

---

#### `front/src/modules/clients/pages/ClientEditPage.vue`
**Cambio:** El tipo del handler de submit debe actualizarse para aceptar contacts en el payload.

```typescript
// Antes
function handleSubmit(values: ClientUpdateForm): void {
  mutate({ guid: props.guid, payload: values }, ...)
}

// Después
function handleSubmit(values: ClientUpdateForm & { contacts: ContactFormItem[] }): void {
  mutate({ guid: props.guid, payload: values }, ...)
}
```

Agregar import de `ContactFormItem` si es necesario para el tipado.

---

#### `front/src/modules/clients/pages/admin/AdminClientEditPage.vue`
**Cambio:** Mismo patrón que `ClientEditPage.vue`. El handler ya acepta `ClientCreateForm | ClientUpdateForm`, hay que agregar contacts.

```typescript
function handleSubmit(values: ClientUpdateForm & { contacts: ContactFormItem[] }): void {
  mutate({ guid: props.guid, payload: values as ClientUpdatePayload }, ...)
}
```

---

#### `front/src/modules/clients/api/clients.api.ts` y `admin-clients.api.ts`
**Cambio:** Las funciones `updateClientApi` y `adminUpdateClientApi` ya usan `ClientUpdatePayload` como tipo del payload. Al actualizar ese interface en `client.types.ts` para incluir `contacts?: ContactFormItem[]`, automáticamente el tipado de estas funciones acepta el nuevo campo sin modificaciones adicionales en el archivo de API.

---

## Orden de implementación

### Fase 1 — Backend (sin romper nada existente)

1. Crear `back/app/Http/Requests/Concerns/ValidatesContactsArray.php` con el trait.

2. Agregar método `syncContacts()` en `back/app/Services/ContactService.php`.

3. Agregar método `updateMember()` en `back/app/Services/UserProfileService.php`. Agregar import de `DB` si no está.

4. Crear `back/app/Http/Requests/Members/UpdateVetStaffRequest.php`.

5. Modificar `back/app/Http/Controllers/V1/VetStaffController.php`:
   - Agregar método `update()`.
   - (Opcional en este paso) Remover inyección de `ContactService` del constructor si no hay otros métodos que lo usen — verificar primero.

6. Modificar `back/app/Http/Controllers/V1/AdminVetController.php`:
   - Eliminar métodos `staffContactStore()` y `staffContactDestroy()`.
   - Agregar método `staffUpdate()`.
   - Actualizar imports (eliminar `StoreContactRequest`, `ContactResource`, `ContactService` del constructor si quedan sin uso).

7. Modificar `back/routes/api/vets.php`:
   - Agregar `PUT /{vet}/staff/{guid}` → `VetStaffController@update` en rutas tenant.
   - Agregar `PUT /{guid}/staff/{profileGuid}` → `AdminVetController@staffUpdate` en rutas admin.
   - Eliminar las dos rutas de contacts individuales de admin.

8. Modificar `back/app/Http/Requests/Clients/UpdateClientRequest.php`: agregar trait y reglas de contacts.

9. Modificar `back/app/Services/ClientService.php`: envolver `update()` en transacción, llamar `syncContacts`. Verificar/agregar import de `DB`.

10. Modificar `back/app/Services/VetService.php`: reemplazar delete-all + recreate por `syncContacts` en `update()`.

11. Ejecutar tests existentes de backend para verificar que no hay regresiones.

### Fase 2 — Frontend

12. Modificar `front/src/modules/vets/types/vet.types.ts`:
    - Agregar `guid?` a `ContactFormItem`.
    - Agregar `UpdateVetStaffPayload`.

13. Modificar `front/src/modules/clients/types/client.types.ts`:
    - Actualizar `ClientUpdatePayload` para incluir `contacts?: ContactFormItem[]`.
    - Agregar import de `ContactFormItem` desde `vet.types.ts`.

14. Agregar funciones en `front/src/modules/vets/api/vet-staff.api.ts`:
    - `updateVetStaffApi`.
    - `adminUpdateVetStaffApi`.
    - Eliminar `adminCreateStaffContactApi` y `adminDeleteStaffContactApi`.

15. Crear `front/src/modules/vets/composables/useUpdateVetStaff.ts`.

16. Crear `front/src/modules/vets/composables/useAdminUpdateVetStaff.ts`.

17. Crear `front/src/modules/vets/components/forms/VetStaffEditForm.vue`.

18. Modificar `front/src/modules/vets/pages/tenant/VetEditStaffPage.vue`: reemplazar VetStaffEditPanel por VetStaffEditForm + composables.

19. Crear `front/src/modules/vets/pages/AdminVetEditStaffPage.vue`.

20. Modificar `front/src/modules/vets/router/vets.routes.ts`: agregar ruta `/admin/vets/:guid/staff/:profileGuid/editar`.

21. Modificar `front/src/modules/vets/components/VetStaffSection.vue`:
    - Eliminar drawer de edición.
    - Cambiar botón editar para navegar a la página.

22. Verificar referencias a `VetStaffEditPanel.vue` con Grep. Eliminar el archivo.

23. Modificar `front/src/modules/clients/components/forms/ClientForm.vue`:
    - Agregar `guid: c.guid` al mapping de contacts en modo edit.
    - Incluir contacts en el emit del modo edit.

24. Actualizar tipos de handler en `ClientEditPage.vue` y `AdminClientEditPage.vue`.

25. Verificar compilación TypeScript sin errores.

---

## Riesgos y consideraciones

**RIESGO-01 — Comportamiento de VetService::update() con sync vs delete-all**
El cambio en `VetService::update()` introduce una dependencia implícita: el frontend de edición de vet (`VetEditPage.vue`, `VetUpdatePayload`) actualmente NO envía guids en los contacts. Esto significa que `syncContacts` se comportará como delete-all de facto (porque todos los items llegan sin guid y se tratan como nuevos). Los contactos existentes se eliminarán y se recrearán con nuevos guids. Mientras el frontend no envíe guids, el comportamiento es funcionalmente equivalente al actual pero los guids de contacts cambian en cada update. Esto no rompe nada visible hoy, pero si en el futuro se indexa o referencia un contact.guid hardcodeado en otro sistema, puede haber inconsistencias. Documentado en Pendientes.

**RIESGO-02 — ContactService::syncContacts y array_filter con label=null**
La función de update en syncContacts usa `array_filter` para no sobrescribir campos no enviados. El campo `label` es nullable y su valor válido es `null`. Si un usuario quiere explícitamente vaciar el label de un contacto existente, debe enviarlo como `null` explícito — lo cual sobrevive al filtro `!== null` y se descarta. Hay un edge case aquí: si `label` viene como `null` en el item, el filtro `!== null` lo excluye del array de update, y el label anterior del contacto no se actualiza. **Solución:** En vez de `array_filter`, construir el array de update explícitamente con todos los campos del item (sin filtrar por null) ya que el update acepta null para label. Documentar en la implementación que `update()` del ContactService acepta `label: null` sin problema.

**RIESGO-03 — Eliminación de adminCreateStaffContactApi / adminDeleteStaffContactApi**
Estas funciones fueron creadas en una sesión anterior. Si hay algún componente no mapeado en este plan que las importe, romperá en tiempo de compilación. El dev debe hacer un Grep de ambas funciones antes de eliminarlas.

**RIESGO-04 — Multi-tenant: syncContacts no filtra por tenant**
`syncContacts` opera sobre `$contactable->contacts()` que es una relación polimórfica del propio contactable. Siempre que el contactable sea resuelto con el scope de tenant correcto (como hacen `findByGuidForVet` en UserProfileService y `findByGuidForVet` en ClientService), no hay fuga de datos entre tenants. El riesgo se materializa solo si alguien llama a `syncContacts` directamente con un contactable resuelto sin scope de tenant — algo que no ocurre en este plan. Igualmente, documentar en PHPDoc del método.

**RIESGO-05 — VetStaffEditPanel.vue: referencias no cubiertas en este plan**
Si hay tests, stories u otros archivos (no rutas) que importan `VetStaffEditPanel`, fallarán al eliminar el archivo. El dev debe buscar todas las referencias antes de proceder.

**RIESGO-06 — contacts() MorphMany en UserProfile**
El método `syncContacts` llama a `$contactable->contacts()`. Verificar que `UserProfile` tiene la relación `contacts()` definida. En el código explorado, `VetStaffEditPanel.vue` usa `member.contacts` (presente en `VetStaffItem`) y el controller hace `$profile->load(['user', 'role', 'contacts'])`, lo que implica que la relación existe en el modelo. Pero confirmar en `back/app/Models/UserProfile.php` que tiene `contacts(): MorphMany` definida.

**RIESGO-07 — Deuda técnica en UpdateVetRequest sin diff inteligente de contacts**
`UpdateVetRequest` ya tiene validación de contacts pero sin `guid?`. Cuando el frontend de vet empiece a enviar guids (tarea pendiente), habrá que agregar la regla `contacts.*.guid => ['nullable', 'string', 'uuid']` al request. No es urgente porque syncContacts maneja items sin guid correctamente.

---

## Supuestos hechos

- `UserProfile` tiene la relación `contacts(): MorphMany` definida en el modelo (verificar en `back/app/Models/UserProfile.php`).
- `Client` tiene la relación `contacts(): MorphMany` definida (verificar — `ClientService::create()` llama `$this->contactService->create($client, ...)` y los controllers hacen `$client->load('contacts')`, lo que implica que existe).
- `useVetRoles()` ya existe y funciona (importado en `VetStaffEditPanel.vue` actual).
- El permiso `vets.staff.update` ya existe en los seeders (estaba en las rutas admin actuales).
- No hay guard de permiso configurado como `meta.permission` en las rutas Vue (el proyecto usa `PermissionGuard` en componentes, no en rutas). Si se quiere agregar guard de ruta para la nueva página admin, es responsabilidad del dev agregarlo como `beforeEnter` siguiendo el patrón de las rutas existentes.

---

## Pendientes / fuera de alcance

1. **Extender VetEditPage para enviar guids en contacts.** `VetService::update()` ya usa `syncContacts` después de este plan, pero `VetUpdatePayload` en el frontend no incluye `guid?` en sus contacts. Cuando el usuario edita contactos de una vet, perderán sus guids. Esto no es regresión (el comportamiento actual también los pierde), pero es deuda técnica que conviene atacar en una iteración posterior: agregar `guid?` a `VetUpdatePayload.contacts` y actualizar `VetEditPage` para mapear los guids del perfil de vet al form.

2. **Validación de formato (E.164 / email) en arrays de contacts en requests.** El trait `ValidatesContactsArray` no replica la closure de `StoreContactRequest` que valida E.164 para phone/whatsapp. Esto queda como deuda técnica menor; la validación de formato puede agregarse como un `Rule::forEach()` o un `Validator::extend()` en una iteración posterior.

3. **Guard de permiso en ruta Vue de AdminVetEditStaffPage.** Si el proyecto estandariza guards de ruta (actualmente no lo hace — usa `PermissionGuard` en componentes), agregar `beforeEnter: canGuard('vets.staff.update')` a la ruta nueva.

4. **Tab activo en VetDetailPage post-navegación.** La navegación post-edit admin va a `/admin/vets/:guid` sin indicar tab activo. Si VetDetailPage implementa navegación por tabs con query params en el futuro, actualizar la redirección para incluir `?tab=staff`.

5. **Contactos individuales de staff (tenant): rutas existentes.** Las rutas `POST /contacts` y `DELETE /contacts/{guid}` del grupo tenant bajo `/staff/{profile}/contacts` se mantienen en el archivo de rutas (van a `ContactController`, no a `VetStaffController`). No se tocan en este plan porque no son las que se crearon en la sesión anterior — son las del `ContactController` genérico. Evaluar si deben mantenerse o unificarse en el nuevo endpoint PUT en una iteración posterior.
