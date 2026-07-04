# Plan técnico: Gestión de Staff de Clientes (Client Staff)

## Input procesado
`C:\laragon\www\sav\.claude\docs\tickets\TKT-003-client-staff-gestion.md`

---

## Resumen ejecutivo

Se crea un módulo completo de gestión de staff para clientes, simétrico al módulo de vet-staff ya existente. El backend agrega cinco métodos a `UserProfileService`, cuatro Form Requests nuevos, un `ClientStaffController` completo para el panel tenant, métodos staff en `AdminClientController`, rutas en `clients.php` y cuatro permisos nuevos en `PermissionSeeder`. El frontend agrega tipos TypeScript en `client.types.ts`, un archivo de API `client-staff.api.ts`, dieciséis composables nuevos, cuatro componentes nuevos (incluyendo reemplazo de `OwnersSection`), tres páginas nuevas y modificaciones a dos páginas existentes y dos archivos de rutas. La resolución del modelo `Client` en el controlador tenant se hace directamente en el controller usando `ClientService::findByGuidForVet`. No se introduce ninguna abstracción compartida con vet-staff (DEC-NEG-02).

---

## Decisiones tomadas

**DEC-01 — Resolución del parámetro `{client}` en ClientStaffController**
- Decisión: resolverlo directamente en cada método del controller con `$this->clientService->findByGuidForVet($clientGuid, $vet)`, donde `$clientGuid` se obtiene de `$request->route('client')` y `$vet` de `$request->attributes->get('current_vet')`. Retornar 404 si no se encuentra.
- Justificación: es idéntico a cómo `AdminClientController` resuelve el client por guid. El middleware `vet.tenant` ya scopa el `$vet`; agregar un segundo middleware de resolución sería sobreingeniería para un solo controlador. La verificación `findByGuidForVet` garantiza el scope multi-tenant (regla dura 3).
- Alternativa descartada: crear un middleware `client.scope` dedicado — añade una capa con poca ganancia cuando el controlador ya tiene el patrón claro y uniforme.

**DEC-02 — Shape del response de lookupForClient**
- Decisión: `{ found: bool, already_linked: bool|null, user: UserItem|null }`, idéntico a `lookupForVet`.
- Justificación: el ticket instruyó explícitamente usar el mismo patrón. El frontend (`VetStaffLookupForm`) ya maneja este shape con tres ramas (`not-found`, `found-linkable`, `found-linked`). Mantener consistencia reduce la superficie de tipos en el frontend.
- Alternativa descartada: `{ status: 'not_found'|'available'|'already_member', profile? }` — el ticket la descartó explícitamente en el contexto de decisión.

**DEC-03 — Validación de role_guid en Form Requests**
- Decisión: usar `Rule::exists('roles', 'guid')->where(fn ($q) => $q->whereIn('name', UserProfileService::CLIENT_STAFF_ROLES))` en todos los Form Requests de client staff, idéntico al patrón de `AssignVetStaffRequest`.
- Justificación: la validación en el FormRequest da mensajes de error L422 estándar con campo localizado antes de que el Service ni siquiera se invoque. Consistente con el patrón de vet-staff.
- Alternativa descartada: validar en el Service — haría el FormRequest incompleto y requeriría capturar RuntimeException para 422 en el controller, que ya existe pero solo como fallback.

**DEC-04 — Constante CLIENT_STAFF_ROLES en UserProfileService**
- Decisión: agregar `public const CLIENT_STAFF_ROLES = ['client-owner', 'client-manager', 'client-administrative'];` en `UserProfileService`, al lado de `VET_STAFF_ROLES`.
- Justificación: los Form Requests y el Service ya referencian `UserProfileService::VET_STAFF_ROLES`. Mantener el mismo patrón de constante pública evita duplicación y facilita la referencia cruzada en Form Requests.
- Alternativa descartada: definirla en los Form Requests — duplicación, no hay single source of truth.

**DEC-05 — Namespace de los nuevos Form Requests**
- Decisión: `App\Http\Requests\Members\Client\` (subcarpeta `Client` dentro de `Members`).
- Justificación: instrucción explícita en el contexto de decisión del ticket. Mantiene los requests de vet en `Members\` y los de client en `Members\Client\` sin mezclarlos. Los `AdminAssignClientStaffRequest` y `AdminChangeClientStaffRoleRequest` también van en `Members\Client\` por consistencia.
- Alternativa descartada: mismo namespace `Members\` — colisionaría en naming (ej: `AssignStaffRequest` sería ambiguo).

**DEC-06 — Job de invitación para client staff nuevo**
- Decisión: reutilizar `SendClientOwnerInvitationJob` (que ya existe y acepta `$userId` y `$clientName`) en `createAndAssignClientStaff`. El job envía la misma invitación independientemente del rol específico dentro de client.
- Justificación: el ticket dice "solo la invitación por email que ya dispara el mecanismo existente". `SendClientOwnerInvitationJob` es la implementación existente para client invitations. Crear un job nuevo es fuera de alcance.
- Alternativa descartada: crear `SendClientStaffInvitationJob` — fuera de alcance según restricciones del ticket.

**DEC-07 — `addMemberToClient` vs `addOwnerToClient`: convivencia**
- Decisión: agregar un nuevo método `addMemberToClient(Client $client, User $user, Role $role): UserProfile` que usa `findForUserAndClient` para verificar duplicado y crea el `UserProfile`. No modifica `addOwnerToClient`.
- Justificación: `addOwnerToClient` hardcodea el rol `client-owner` y tiene lógica diferente (busca User por email y crea si no existe). El nuevo método solo asigna un User ya resuelto con un Role ya resuelto, espejando `addMember(Vet)`.
- Alternativa descartada: reutilizar `addOwnerToClient` — lógica diferente, viola DEC-NEG-02.

**DEC-08 — Repository: sin métodos nuevos necesarios**
- Decisión: `UserProfileRepositoryInterface` y `UserProfileRepositoryEloquent` NO se modifican. Los métodos necesarios (`findForUserAndClient`, `listOwnersForClient`) ya existen. Solo se agrega `listForClient` directamente en el repositorio.
- Justificación: al revisar el código, `findForUserAndClient` ya existe en el repositorio. Falta solo `listForClient` (que lista todos los perfiles del client, no solo owners). Se agrega tanto a la Interface como al Eloquent.
- Alternativa descartada: reutilizar `listOwnersForClient` con filtro posterior — pierde performance y no es el patrón correcto.

**DEC-09 — Query keys Vue Query para client staff**
- Decisión: admin: `['admin-client-staff', clientGuid]` y `['admin-client-staff-member', clientGuid, profileGuid]`. Tenant: `['client-staff', vetGuid, clientGuid]` y `['client-staff-member', vetGuid, clientGuid, profileGuid]`.
- Justificación: instrucción explícita del contexto de decisión. El `vetGuid` en las tenant-keys garantiza que dos vets con el mismo `clientGuid` no compartan caché (aunque un client solo puede aparecer en un vet a la vez, la key correcta incluye el scope completo).
- Alternativa descartada: solo `['client-staff', clientGuid]` — no incluye el scope de vet, podría generar cache hit incorrecto si el mismo client aparece en contextos de navegación diferentes.

**DEC-10 — Tipos TypeScript: `ClientStaffItem` reemplaza `OwnerItem` o conviven**
- Decisión: se mantiene `OwnerItem` en `client.types.ts` SIN modificarlo. Se agrega `ClientStaffItem` como tipo nuevo. Los composables y componentes nuevos usan `ClientStaffItem`. `OwnersSection.vue` y `OwnerFormModal.vue` se eliminan; ningún componente nuevo referencia `OwnerItem`.
- Justificación: eliminar `OwnerItem` rompería `OwnerCreatePayload` y `clients.api.ts` (`listOwnersApi`, `createOwnerApi`) que se mantienen para no romper `ClientOwnerController` (que el ticket prohíbe eliminar). `OwnerItem` queda deprecated-en-código pero sin romper nada.
- Alternativa descartada: eliminar `OwnerItem` ahora — rompe `clients.api.ts` y los composables relacionados.

**DEC-11 — URL de ClientStaffCreatePage**
- Decisión: `/vets/:vetGuid/clients/:clientGuid/staff/new`
- Justificación: instrucción explícita del contexto de decisión. No colisiona con rutas existentes (la ruta más cercana es `/vets/:vetGuid/clients/:guid` y la nueva tiene `:clientGuid/staff/new` como sufijo adicional). La ruta `/new` va ANTES que cualquier ruta dinámica con `:profileGuid` para evitar colisión.
- Alternativa descartada: usar `/:guid/staff/add` — menos consistente con la convención `/new` del proyecto.

---

## Cambios en BACKEND

### Archivos a crear

#### `back/app/Http/Requests/Members/Client/AssignClientStaffRequest.php`
**Propósito:** Validar el payload de asignación de un usuario existente a un client (store en tenant).
**Firma principal:**
```php
namespace App\Http\Requests\Members\Client;

use App\Services\UserProfileService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignClientStaffRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'user_guid' => ['required', 'string', 'exists:users,guid'],
            'role_guid' => [
                'required',
                'string',
                Rule::exists('roles', 'guid')->where(function ($query) {
                    $query->whereIn('name', UserProfileService::CLIENT_STAFF_ROLES);
                }),
            ],
            'contacts'                  => ['nullable', 'array'],
            'contacts.*.type'           => ['required', 'string', Rule::in(['email', 'phone', 'whatsapp'])],
            'contacts.*.value'          => ['required', 'string', 'max:200'],
            'contacts.*.label'          => ['nullable', 'string', 'max:100'],
            'contacts.*.is_primary'     => ['nullable', 'boolean'],
            'contacts.*.use_for_alerts' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'user_guid.required' => 'El usuario es obligatorio.',
            'user_guid.exists'   => 'El usuario seleccionado no existe.',
            'role_guid.required' => 'El rol es obligatorio.',
            'role_guid.exists'   => 'El rol seleccionado no es válido para staff de cliente.',
        ];
    }
}
```
**Dependencias inyectadas:** ninguna (FormRequest puro).

---

#### `back/app/Http/Requests/Members/Client/CreateClientStaffRequest.php`
**Propósito:** Validar el payload para crear un usuario nuevo y asignarlo como staff del client.
**Firma principal:**
```php
namespace App\Http\Requests\Members\Client;

use App\Services\UserProfileService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateClientStaffRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:50'],
            'last_name'  => ['required', 'string', 'max:50'],
            'email'      => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'role_guid'  => [
                'required',
                'string',
                Rule::exists('roles', 'guid')->where(function ($query) {
                    $query->whereIn('name', UserProfileService::CLIENT_STAFF_ROLES);
                }),
            ],
            'contacts'                  => ['nullable', 'array'],
            'contacts.*.type'           => ['required', 'string', Rule::in(['email', 'phone', 'whatsapp'])],
            'contacts.*.value'          => ['required', 'string', 'max:200'],
            'contacts.*.label'          => ['nullable', 'string', 'max:100'],
            'contacts.*.is_primary'     => ['nullable', 'boolean'],
            'contacts.*.use_for_alerts' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.required' => 'El nombre es obligatorio.',
            'last_name.required'  => 'El apellido es obligatorio.',
            'email.required'      => 'El email es obligatorio.',
            'email.email'         => 'El email no tiene un formato válido.',
            'email.unique'        => 'Este email ya está registrado en el sistema. Usá el flujo de búsqueda.',
            'role_guid.required'  => 'El rol es obligatorio.',
            'role_guid.exists'    => 'El rol seleccionado no es válido para personal de un cliente.',
        ];
    }
}
```

---

#### `back/app/Http/Requests/Members/Client/UpdateClientStaffRequest.php`
**Propósito:** Validar el payload de actualización de un miembro del staff de cliente (rol + contactos).
**Firma principal:**
```php
namespace App\Http\Requests\Members\Client;

use App\Http\Requests\Concerns\ValidatesContactsArray;
use App\Services\UserProfileService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClientStaffRequest extends FormRequest
{
    use ValidatesContactsArray;

    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return array_merge(
            [
                'role_guid' => [
                    'required',
                    'string',
                    Rule::exists('roles', 'guid')->where(function ($query) {
                        $query->whereIn('name', UserProfileService::CLIENT_STAFF_ROLES);
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
                'role_guid.exists'   => 'El rol seleccionado no es válido para un miembro de cliente.',
            ],
            $this->contactsMessages(),
        );
    }
}
```

---

#### `back/app/Http/Requests/Members/Client/ChangeClientStaffRoleRequest.php`
**Propósito:** Validar el payload del PATCH de cambio de rol de un miembro del staff de cliente.
**Firma principal:**
```php
namespace App\Http\Requests\Members\Client;

use App\Services\UserProfileService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeClientStaffRoleRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'role_guid' => [
                'required',
                'string',
                Rule::exists('roles', 'guid')->where(function ($query) {
                    $query->whereIn('name', UserProfileService::CLIENT_STAFF_ROLES);
                }),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'role_guid.required' => 'El rol es obligatorio.',
            'role_guid.exists'   => 'El rol seleccionado no es válido para un miembro de cliente.',
        ];
    }
}
```

---

#### `back/app/Http/Requests/Members/Client/LookupClientStaffRequest.php`
**Propósito:** Validar el query param `email` del lookup de client staff.
**Firma principal:**
```php
namespace App\Http\Requests\Members\Client;

use Illuminate\Foundation\Http\FormRequest;

class LookupClientStaffRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'El email es obligatorio.',
            'email.email'    => 'El email no tiene un formato válido.',
        ];
    }
}
```

---

#### `back/app/Http/Requests/Members/Client/AdminAssignClientStaffRequest.php`
**Propósito:** Validar asignación de staff desde el panel admin (solo user_guid + role_guid, sin contacts).
**Firma principal:**
```php
namespace App\Http\Requests\Members\Client;

use App\Services\UserProfileService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminAssignClientStaffRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'user_guid' => ['required', 'string', 'exists:users,guid'],
            'role_guid' => [
                'required',
                'string',
                Rule::exists('roles', 'guid')->where(function ($query) {
                    $query->whereIn('name', UserProfileService::CLIENT_STAFF_ROLES);
                }),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'user_guid.required' => 'El usuario es obligatorio.',
            'user_guid.exists'   => 'El usuario seleccionado no existe.',
            'role_guid.required' => 'El rol es obligatorio.',
            'role_guid.exists'   => 'El rol seleccionado no es válido para staff de cliente.',
        ];
    }
}
```

---

#### `back/app/Http/Requests/Members/Client/AdminChangeClientStaffRoleRequest.php`
**Propósito:** Validar cambio de rol de staff desde el panel admin.
**Firma principal:** idéntico a `ChangeClientStaffRoleRequest` con el mismo namespace `Members\Client\`.

---

#### `back/app/Http/Controllers/V1/ClientStaffController.php`
**Propósito:** Controller tenant para gestión completa del staff de un client. Espejo de `VetStaffController` adaptado a `Client`.
**Firma principal:**
```php
namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Members\Client\AssignClientStaffRequest;
use App\Http\Requests\Members\Client\ChangeClientStaffRoleRequest;
use App\Http\Requests\Members\Client\CreateClientStaffRequest;
use App\Http\Requests\Members\Client\LookupClientStaffRequest;
use App\Http\Requests\Members\Client\UpdateClientStaffRequest;
use App\Http\Resources\V1\UserProfileResource;
use App\Services\ClientService;
use App\Services\ContactService;
use App\Services\UserProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientStaffController extends Controller
{
    public function __construct(
        private UserProfileService $userProfileService,
        private ClientService      $clientService,
        private ContactService     $contactService,
    ) {}

    // Método privado helper para resolver el Client scoped al vet tenant
    private function resolveClient(Request $request): ?\App\Models\Client
    {
        $vet        = $request->attributes->get('current_vet');
        $clientGuid = $request->route('client');
        return $this->clientService->findByGuidForVet($clientGuid, $vet);
    }

    public function index(Request $request): JsonResponse
    {
        // $client = resolveClient() → 404 si no encontrado
        // $members = $this->userProfileService->listForClient($client)
        // return $this->makeSuccess(UserProfileResource::collection($members))
    }

    public function store(AssignClientStaffRequest $request): JsonResponse
    {
        // $client = resolveClient() → 404 si no encontrado
        // $data = $request->validated()
        // $user = resolveUser($data['user_guid']) → 404 si no encontrado
        // $role = resolveRole($data['role_guid']) → 404 si no encontrado
        // $profile = $this->userProfileService->addMemberToClient($client, $user, $role)
        // foreach ($data['contacts'] ?? []) crear contacto
        // return 201 con UserProfileResource cargado con ['user', 'role', 'contacts']
    }

    public function destroy(Request $request): JsonResponse
    {
        // $client = resolveClient() → 404
        // $guid = $request->route('guid')
        // $profile = findByGuidForClient($guid, $client) → 404
        // $currentProfile = $request->attributes->get('current_profile')
        // if ($currentProfile && $profile->id === $currentProfile->id) → 403
        // $this->userProfileService->removeMember($profile)
        // return makeSuccess(null, 'Miembro eliminado correctamente.')
    }

    public function changeRole(ChangeClientStaffRoleRequest $request): JsonResponse
    {
        // $client = resolveClient() → 404
        // $guid = $request->route('guid')
        // $profile = findByGuidForClient($guid, $client) → 404
        // $role = resolveRole($request->validated()['role_guid']) → 404
        // $profile = changeRole($profile, $role)
        // return makeSuccess(new UserProfileResource($profile), 'Rol actualizado correctamente.')
    }

    public function lookup(LookupClientStaffRequest $request): JsonResponse
    {
        // $client = resolveClient() → 404
        // $result = lookupForClient($request->validated()['email'], $client)
        // return makeSuccess($result)
    }

    public function createAndAssign(CreateClientStaffRequest $request): JsonResponse
    {
        // $client = resolveClient() → 404
        // $profile = createAndAssignClientStaff($client, $request->validated())
        // return 201 con UserProfileResource cargado
    }

    public function update(UpdateClientStaffRequest $request): JsonResponse
    {
        // $client = resolveClient() → 404
        // $guid = $request->route('guid')
        // $profile = findByGuidForClient($guid, $client) → 404
        // $data = $request->validated()
        // $profile = updateMember($profile, $data['role_guid'], $data['contacts'] ?? [])
        // return makeSuccess(new UserProfileResource($profile), 'Perfil actualizado correctamente.')
    }

    public function show(Request $request): JsonResponse
    {
        // $client = resolveClient() → 404
        // $guid = $request->route('guid')
        // $profile = findByGuidForClient($guid, $client) → 404
        // $profile->load(['user', 'role', 'contacts'])
        // return makeSuccess(new UserProfileResource($profile))
    }

    public function toggleBlock(Request $request): JsonResponse
    {
        // $client = resolveClient() → 404
        // $guid = $request->route('guid')
        // $currentProfile = $request->attributes->get('current_profile')
        // $profile = findByGuidForClient($guid, $client) → 404
        // if self → 403
        // $profile = toggleBlock($profile)
        // $msg = $profile->blocked_at ? 'Acceso bloqueado para este cliente.' : 'Acceso desbloqueado correctamente.'
        // return makeSuccess(new UserProfileResource($profile->load(['user','role','contacts'])), $msg)
    }
}
```
**Dependencias inyectadas:** `UserProfileService`, `ClientService`, `ContactService`.

---

### Archivos a modificar

#### `back/app/Services/UserProfileService.php`
**Cambio:** agregar constante `CLIENT_STAFF_ROLES` y cinco métodos nuevos con scope `Client`.

**Antes (resumido):** tiene `TENANT_ROLES`, `VET_STAFF_ROLES`, métodos `list(Vet)`, `findByGuidForVet`, `addMember(Vet)`, `addOwnerToClient`, `lookupForVet`, `createAndAssignVetStaff`, `updateMember`, `listOwnersForClient`.

**Después — agregar al final de la clase:**
```php
/** Roles válidos para personal de cliente (subset de TENANT_ROLES). */
public const CLIENT_STAFF_ROLES = ['client-owner', 'client-manager', 'client-administrative'];

/**
 * Lista todos los UserProfiles de un Client (todos los roles client-*).
 */
public function listForClient(Client $client): Collection
{
    return $this->userProfileRepository->listForClient($client);
}

/**
 * Busca un UserProfile por guid verificando que pertenece al client dado.
 * Retorna null si no existe o si pertenece a otro authenticatable.
 */
public function findByGuidForClient(string $guid, Client $client): ?UserProfile
{
    $profile = $this->userProfileRepository->findByGuid($guid);

    if (!$profile) {
        return null;
    }

    if ($profile->authenticatable_type !== 'client' || $profile->authenticatable_id !== $client->id) {
        return null;
    }

    return $profile;
}

/**
 * Busca un User por email y verifica si ya es miembro del client dado.
 *
 * Retorna:
 *   ['found' => false, 'already_linked' => null, 'user' => null]
 *   ['found' => true, 'already_linked' => false, 'user' => [...]]
 *   ['found' => true, 'already_linked' => true,  'user' => [...]]
 */
public function lookupForClient(string $email, Client $client): array
{
    $user = $this->userRepository->findByEmail($email);

    if (!$user) {
        return ['found' => false, 'already_linked' => null, 'user' => null];
    }

    $existing = $this->userProfileRepository->findForUserAndClient($user, $client);

    return [
        'found'          => true,
        'already_linked' => $existing !== null,
        'user'           => [
            'guid'       => $user->guid,
            'first_name' => $user->first_name,
            'last_name'  => $user->last_name,
            'email'      => $user->email,
        ],
    ];
}

/**
 * Crea un User nuevo (con invitación) y lo asigna como staff del Client.
 *
 * Flujo (dentro de DB::transaction):
 *   1. Crear User con password temporal + token de verificación.
 *   2. Resolver el rol por guid (validado en CreateClientStaffRequest).
 *   3. Crear UserProfile(authenticatable_type='client', authenticatable_id=$client->id).
 *   4. Crear contactos opcionales.
 *   5. Encolar SendClientOwnerInvitationJob.
 *
 * @param Client $client  Client al que se agrega el personal
 * @param array  $data    Datos validados de CreateClientStaffRequest
 */
public function createAndAssignClientStaff(Client $client, array $data): UserProfile
{
    return DB::transaction(function () use ($client, $data) {
        $expirationHours = (int) config('auth.invitation_link_expiration_hours', 72);

        $user = $this->userRepository->create([
            'first_name'                   => $data['first_name'],
            'last_name'                    => $data['last_name'],
            'name'                         => $data['first_name'] . ' ' . $data['last_name'],
            'email'                        => $data['email'],
            'password'                     => Hash::make(Str::random(32)),
            'email_verified_at'            => null,
            'verification_link_token'      => Str::random(64),
            'verification_link_expires_at' => now()->addHours($expirationHours),
        ]);

        $role = $this->roleRepository->findByGuid($data['role_guid']);
        if (!$role) {
            throw new \RuntimeException('El rol especificado no existe.');
        }

        $profile = $this->userProfileRepository->create([
            'user_id'              => $user->id,
            'authenticatable_type' => 'client',
            'authenticatable_id'   => $client->id,
            'role_id'              => $role->id,
        ]);

        foreach ($data['contacts'] ?? [] as $contactData) {
            $this->contactService->create($profile, $contactData);
        }

        SendClientOwnerInvitationJob::dispatch($user->id, $client->name);

        return $profile;
    });
}

/**
 * Agrega un usuario ya existente como miembro de un Client con un rol específico.
 * Lanza RuntimeException si el usuario ya es miembro del Client.
 */
public function addMemberToClient(Client $client, User $user, Role $role): UserProfile
{
    $existing = $this->userProfileRepository->findForUserAndClient($user, $client);

    if ($existing) {
        throw new \RuntimeException('El usuario ya es miembro de este cliente.');
    }

    return $this->userProfileRepository->create([
        'user_id'              => $user->id,
        'authenticatable_type' => 'client',
        'authenticatable_id'   => $client->id,
        'role_id'              => $role->id,
    ]);
}
```

---

#### `back/app/Contracts/Repositories/UserProfileRepositoryInterface.php`
**Cambio:** agregar declaración del método `listForClient`.

**Antes:** tiene `listOwnersForClient(Client $client): Collection`.

**Después — agregar:**
```php
/**
 * Lista todos los UserProfiles de un Client (todos los roles client-*).
 */
public function listForClient(Client $client): Collection;
```

---

#### `back/app/Repositories/UserProfileRepositoryEloquent.php`
**Cambio:** implementar `listForClient`.

**Después — agregar:**
```php
public function listForClient(Client $client): Collection
{
    return $this->newQuery()
        ->with(['user', 'role'])
        ->where('authenticatable_type', 'client')
        ->where('authenticatable_id', $client->id)
        ->get();
}
```

---

#### `back/app/Http/Controllers/V1/AdminClientController.php`
**Cambio:** agregar `UserProfileService` al constructor e inyectarlo, y agregar seis métodos de staff.

**Antes:** constructor recibe solo `ClientService` y `VetService`. Sin métodos de staff.

**Después — constructor:**
```php
public function __construct(
    private ClientService      $clientService,
    private VetService         $vetService,
    private UserProfileService $userProfileService,
) {}
```

**Después — agregar imports:**
```php
use App\Http\Requests\Members\Client\AdminAssignClientStaffRequest;
use App\Http\Requests\Members\Client\AdminChangeClientStaffRoleRequest;
use App\Http\Requests\Members\Client\UpdateClientStaffRequest;
use App\Http\Resources\V1\UserProfileResource;
use App\Services\UserProfileService;
```

**Después — métodos a agregar:**
```php
public function staffIndex(string $guid): JsonResponse
{
    // Resolver client por guid → 404 si no existe
    // $members = $this->userProfileService->listForClient($client)
    // Filtrar por CLIENT_STAFF_ROLES (idéntico a cómo staffIndex de AdminVetController filtra por VET_STAFF_ROLES)
    // return makeSuccess(UserProfileResource::collection($staff))
}

public function staffStore(AdminAssignClientStaffRequest $request, string $guid): JsonResponse
{
    // Resolver client por guid → 404
    // Resolver user por user_guid → 404
    // Resolver role por role_guid → 404
    // $profile = addMemberToClient($client, $user, $role)
    // return 201 con UserProfileResource
    // capturar RuntimeException → 422
}

public function staffShow(string $guid, string $profileGuid): JsonResponse
{
    // Resolver client por guid → 404
    // $profile = findByGuidForClient($profileGuid, $client) → 404
    // $profile->load(['user', 'role', 'contacts'])
    // return makeSuccess(new UserProfileResource($profile))
}

public function staffUpdate(UpdateClientStaffRequest $request, string $guid, string $profileGuid): JsonResponse
{
    // Resolver client por guid → 404
    // $profile = findByGuidForClient($profileGuid, $client) → 404
    // $data = $request->validated()
    // $profile = updateMember($profile, $data['role_guid'], $data['contacts'] ?? [])
    // return makeSuccess(new UserProfileResource($profile), 'Perfil actualizado correctamente.')
    // capturar RuntimeException → 422
}

public function staffChangeRole(AdminChangeClientStaffRoleRequest $request, string $guid, string $profileGuid): JsonResponse
{
    // Resolver client por guid → 404
    // $profile = findByGuidForClient($profileGuid, $client) → 404
    // Resolver role → 404
    // $profile = changeRole($profile, $role)
    // return makeSuccess(new UserProfileResource($profile), 'Rol actualizado correctamente.')
}

public function staffDestroy(string $guid, string $profileGuid): JsonResponse
{
    // Resolver client por guid → 404
    // $profile = findByGuidForClient($profileGuid, $client) → 404
    // removeMember($profile)
    // return makeSuccess(null, 'Miembro eliminado correctamente.')
}
```

---

#### `back/database/seeders/PermissionSeeder.php`
**Cambio:** agregar los cuatro permisos `clients.staff.*` al array `$permissions`.

**Antes:** el array termina con `'clients.owners.read'` y `'clients.owners.create'`.

**Después — agregar al array:**
```php
'clients.staff.read',
'clients.staff.create',
'clients.staff.update',
'clients.staff.delete',
```

**Nota de asignación a roles:** `superadmin` ya recibe todos los permisos con `$superAdmin->syncPermissions(Permission::all())` en `RoleSeeder`. Los roles tenant `vet`, `vet-assistant`, `vet-administrative` NO reciben estos permisos automáticamente via seeder; los permisos `clients.staff.*` son para el panel admin. El rol `vet` en el panel tenant opera con los middlewares `auth:sanctum` + `vet.tenant` + `can:clients.staff.read/create/update/delete`. Se debe verificar que `RoleSeeder` asigne `clients.staff.*` al rol `vet` (y `vet-assistant`, `vet-administrative` con scope reducido). Si el proyecto no asigna permisos a roles tenant (operan solo por middleware), esto NO hace falta. Revisión requerida antes de implementar — ver sección Riesgos.

---

#### `back/routes/api/clients.php`
**Cambio:** agregar rutas del panel admin para staff de client y rutas del panel tenant para `ClientStaffController`.

**Antes (resumido):** grupo admin con CRUD de client + linkVet/unlinkVet. Grupo tenant con clients CRUD + owners + contacts + establishments.

**Después — agregar en el grupo `v1/admin/clients`:**
```php
use App\Http\Controllers\V1\ClientStaffController;

// Staff de client (panel admin)
Route::get('/{guid}/staff',                                             [AdminClientController::class, 'staffIndex'])    ->middleware('can:clients.staff.read');
Route::post('/{guid}/staff',                                            [AdminClientController::class, 'staffStore'])    ->middleware('can:clients.staff.create');
Route::get('/{guid}/staff/{profileGuid}',         [AdminClientController::class, 'staffShow'])     ->middleware('can:clients.staff.read');
Route::put('/{guid}/staff/{profileGuid}',         [AdminClientController::class, 'staffUpdate'])   ->middleware('can:clients.staff.update');
Route::patch('/{guid}/staff/{profileGuid}/role',  [AdminClientController::class, 'staffChangeRole'])->middleware('can:clients.staff.update');
Route::delete('/{guid}/staff/{profileGuid}',      [AdminClientController::class, 'staffDestroy'])  ->middleware('can:clients.staff.delete');
```

**Después — agregar en el grupo `v1/vets/{vet}/clients` (dentro del grupo tenant), a continuación del bloque de owners:**
```php
// Staff de un client (panel tenant)
// IMPORTANTE: rutas estáticas ANTES de las dinámicas (igual que vets.php)
Route::prefix('/{client}/staff')->group(function () {
    Route::get('/',              [ClientStaffController::class, 'index'])          ->middleware('can:clients.staff.read');
    Route::post('/',             [ClientStaffController::class, 'store'])          ->middleware('can:clients.staff.create');
    Route::get('/lookup',        [ClientStaffController::class, 'lookup'])         ->middleware('can:clients.staff.read');
    Route::post('/new-user',     [ClientStaffController::class, 'createAndAssign'])->middleware('can:clients.staff.create');
    Route::get('/{guid}',        [ClientStaffController::class, 'show'])           ->middleware('can:clients.staff.read');
    Route::put('/{guid}',        [ClientStaffController::class, 'update'])         ->middleware('can:clients.staff.update');
    Route::delete('/{guid}',     [ClientStaffController::class, 'destroy'])        ->middleware('can:clients.staff.delete');
    Route::patch('/{guid}/role', [ClientStaffController::class, 'changeRole'])     ->middleware('can:clients.staff.update');
    Route::patch('/{guid}/toggle-block', [ClientStaffController::class, 'toggleBlock'])->middleware('can:clients.staff.update');
});
```

**Nota:** el parámetro de ruta es `{client}` (no `{guid}`) para consistencia con `contacts` y `establishments` existentes en el mismo archivo.

---

### Migrations
No se requieren migraciones. La tabla `user_profiles` ya tiene `authenticatable_type` y `authenticatable_id` polimórficos. Los roles `client-owner`, `client-manager`, `client-administrative` ya existen en la DB (confirmado en `RoleSeeder`).

---

### Rutas API

| Método | Path | Controller@Action | Middleware |
|--------|------|-------------------|------------|
| GET | `/v1/admin/clients/{guid}/staff` | `AdminClientController@staffIndex` | `auth:sanctum`, `can:clients.staff.read` |
| POST | `/v1/admin/clients/{guid}/staff` | `AdminClientController@staffStore` | `auth:sanctum`, `can:clients.staff.create` |
| GET | `/v1/admin/clients/{guid}/staff/{profileGuid}` | `AdminClientController@staffShow` | `auth:sanctum`, `can:clients.staff.read` |
| PUT | `/v1/admin/clients/{guid}/staff/{profileGuid}` | `AdminClientController@staffUpdate` | `auth:sanctum`, `can:clients.staff.update` |
| PATCH | `/v1/admin/clients/{guid}/staff/{profileGuid}/role` | `AdminClientController@staffChangeRole` | `auth:sanctum`, `can:clients.staff.update` |
| DELETE | `/v1/admin/clients/{guid}/staff/{profileGuid}` | `AdminClientController@staffDestroy` | `auth:sanctum`, `can:clients.staff.delete` |
| GET | `/v1/vets/{vet}/clients/{client}/staff` | `ClientStaffController@index` | `auth:sanctum`, `vet.tenant`, `can:clients.staff.read` |
| POST | `/v1/vets/{vet}/clients/{client}/staff` | `ClientStaffController@store` | `auth:sanctum`, `vet.tenant`, `can:clients.staff.create` |
| GET | `/v1/vets/{vet}/clients/{client}/staff/lookup` | `ClientStaffController@lookup` | `auth:sanctum`, `vet.tenant`, `can:clients.staff.read` |
| POST | `/v1/vets/{vet}/clients/{client}/staff/new-user` | `ClientStaffController@createAndAssign` | `auth:sanctum`, `vet.tenant`, `can:clients.staff.create` |
| GET | `/v1/vets/{vet}/clients/{client}/staff/{guid}` | `ClientStaffController@show` | `auth:sanctum`, `vet.tenant`, `can:clients.staff.read` |
| PUT | `/v1/vets/{vet}/clients/{client}/staff/{guid}` | `ClientStaffController@update` | `auth:sanctum`, `vet.tenant`, `can:clients.staff.update` |
| DELETE | `/v1/vets/{vet}/clients/{client}/staff/{guid}` | `ClientStaffController@destroy` | `auth:sanctum`, `vet.tenant`, `can:clients.staff.delete` |
| PATCH | `/v1/vets/{vet}/clients/{client}/staff/{guid}/role` | `ClientStaffController@changeRole` | `auth:sanctum`, `vet.tenant`, `can:clients.staff.update` |
| PATCH | `/v1/vets/{vet}/clients/{client}/staff/{guid}/toggle-block` | `ClientStaffController@toggleBlock` | `auth:sanctum`, `vet.tenant`, `can:clients.staff.update` |

---

### Permisos Spatie

| Nombre | Seeder | Roles que lo reciben |
|--------|--------|---------------------|
| `clients.staff.read` | `PermissionSeeder` | `super-admin` (automático por `syncPermissions(Permission::all())`) + roles tenant (ver Riesgos) |
| `clients.staff.create` | `PermissionSeeder` | idem |
| `clients.staff.update` | `PermissionSeeder` | idem |
| `clients.staff.delete` | `PermissionSeeder` | idem |

---

### Contrato del endpoint (ejemplos clave)

**GET `/v1/vets/{vet}/clients/{client}/staff/lookup?email=x@y.com`**

Response 200 — usuario no encontrado:
```json
{
  "success": true,
  "data": {
    "found": false,
    "already_linked": null,
    "user": null
  }
}
```

Response 200 — usuario encontrado, no vinculado:
```json
{
  "success": true,
  "data": {
    "found": true,
    "already_linked": false,
    "user": {
      "guid": "uuid",
      "first_name": "Juan",
      "last_name": "Pérez",
      "email": "juan@ejemplo.com"
    }
  }
}
```

Response 200 — usuario ya miembro:
```json
{
  "success": true,
  "data": {
    "found": true,
    "already_linked": true,
    "user": { ... }
  }
}
```

**POST `/v1/vets/{vet}/clients/{client}/staff/new-user`**

Request:
```json
{
  "first_name": "Ana",
  "last_name": "García",
  "email": "ana@ejemplo.com",
  "role_guid": "uuid-del-rol-client-owner",
  "contacts": [
    {
      "type": "phone",
      "value": "1134567890",
      "label": "Cel",
      "is_primary": true,
      "use_for_alerts": false
    }
  ]
}
```

Response 201:
```json
{
  "success": true,
  "data": {
    "guid": "uuid",
    "user": {
      "guid": "uuid",
      "name": "Ana García",
      "first_name": "Ana",
      "last_name": "García",
      "email": "ana@ejemplo.com"
    },
    "role": { "guid": "uuid", "name": "client-owner" },
    "contacts": [...],
    "blocked_at": null,
    "created_at": "2026-06-17T00:00:00.000Z"
  },
  "message": "Usuario creado e incorporado al equipo correctamente."
}
```

Errores posibles:

| HTTP | Cuándo |
|------|--------|
| 404 | Client no existe o no pertenece al vet |
| 404 | Usuario o rol no encontrado |
| 422 | Email ya registrado en el sistema (usar flujo de búsqueda) |
| 422 | El usuario ya es miembro del cliente |
| 422 | Validación de campos (role no válido, email inválido, etc.) |
| 403 | Auto-bloqueo / auto-eliminación |

---

### Tests a generar (qué cubrir, no el código)

**Feature tests — `ClientStaffController`:**
- `index` retorna solo staff del client scoped al vet autenticado.
- `index` retorna 404 si el client no pertenece al vet.
- `store` asigna usuario existente correctamente (201).
- `store` retorna 422 si usuario ya es miembro.
- `store` retorna 422 si role_guid no es CLIENT_STAFF_ROLES.
- `createAndAssign` crea usuario nuevo, crea UserProfile, encola job.
- `createAndAssign` retorna 422 si email ya existe.
- `lookup` — caso not-found, found-linkable, found-linked.
- `destroy` elimina miembro correcto; 403 al auto-eliminarse.
- `toggleBlock` bloquea y desbloquea; 403 al auto-bloquearse.
- `changeRole` cambia rol a rol válido; 422 con rol de otro scope (vet-staff).
- `update` actualiza rol + contactos en transacción.
- Cualquier acción con un `{client}` que pertenece a otra vet retorna 404.

**Feature tests — `AdminClientController` (métodos staff):**
- `staffIndex` lista staff del client (sin scope de vet).
- `staffStore` asigna usuario existente.
- `staffStore` retorna 422 si ya es miembro.
- `staffChangeRole` cambia rol.
- `staffDestroy` elimina miembro.
- `staffUpdate` actualiza con transacción.
- Todos retornan 404 si el client no existe.

**Unit tests — `UserProfileService`:**
- `listForClient` retorna todos los UserProfiles client (no solo owners).
- `findByGuidForClient` retorna null si el profile pertenece a otro client.
- `findByGuidForClient` retorna null si el profile es de tipo 'vet'.
- `lookupForClient` — tres casos de return.
- `createAndAssignClientStaff` — happy path: crea user, crea profile, encola job.
- `addMemberToClient` — happy path y RuntimeException si duplicado.

---

## Cambios en FRONTEND

### Archivos a crear

#### `front/src/modules/clients/api/client-staff.api.ts`
**Propósito:** Todas las llamadas HTTP del módulo client-staff, espejo de `vet-staff.api.ts`.

```typescript
import { http } from '@/core/api/http'
import type {
  ClientStaffItem,
  ClientStaffAssignPayload,
  ClientStaffCreatePayload,
  ClientStaffLookupResult,
  UpdateClientStaffPayload,
  ChangeClientStaffRolePayload,
} from '../types/client.types'

// --- Panel Admin ---

export async function adminListClientStaffApi(clientGuid: string): Promise<ClientStaffItem[]> {
  const res = await http.get<ClientStaffItem[]>(`/v1/admin/clients/${clientGuid}/staff`)
  return res.data
}

export async function adminGetClientStaffMemberApi(clientGuid: string, profileGuid: string): Promise<ClientStaffItem> {
  const res = await http.get<ClientStaffItem>(`/v1/admin/clients/${clientGuid}/staff/${profileGuid}`)
  return res.data
}

export async function adminAssignClientStaffApi(clientGuid: string, payload: ClientStaffAssignPayload): Promise<ClientStaffItem> {
  const res = await http.post<ClientStaffItem>(`/v1/admin/clients/${clientGuid}/staff`, payload)
  return res.data
}

export async function adminChangeClientStaffRoleApi(
  clientGuid: string,
  profileGuid: string,
  payload: ChangeClientStaffRolePayload,
): Promise<ClientStaffItem> {
  const res = await http.patch<ClientStaffItem>(`/v1/admin/clients/${clientGuid}/staff/${profileGuid}/role`, payload)
  return res.data
}

export async function adminUpdateClientStaffApi(
  clientGuid: string,
  profileGuid: string,
  payload: UpdateClientStaffPayload,
): Promise<ClientStaffItem> {
  const res = await http.put<ClientStaffItem>(`/v1/admin/clients/${clientGuid}/staff/${profileGuid}`, payload)
  return res.data
}

export async function adminRemoveClientStaffApi(clientGuid: string, profileGuid: string): Promise<void> {
  await http.delete(`/v1/admin/clients/${clientGuid}/staff/${profileGuid}`)
}

// --- Panel Tenant ---

export async function listClientStaffApi(vetGuid: string, clientGuid: string): Promise<ClientStaffItem[]> {
  const res = await http.get<ClientStaffItem[]>(`/v1/vets/${vetGuid}/clients/${clientGuid}/staff`)
  return res.data
}

export async function getClientStaffMemberApi(vetGuid: string, clientGuid: string, profileGuid: string): Promise<ClientStaffItem> {
  const res = await http.get<ClientStaffItem>(`/v1/vets/${vetGuid}/clients/${clientGuid}/staff/${profileGuid}`)
  return res.data
}

export async function lookupClientStaffApi(
  vetGuid: string,
  clientGuid: string,
  email: string,
): Promise<ClientStaffLookupResult> {
  const res = await http.get<ClientStaffLookupResult>(
    `/v1/vets/${vetGuid}/clients/${clientGuid}/staff/lookup`,
    { params: { email } },
  )
  return res.data
}

export async function createClientStaffApi(
  vetGuid: string,
  clientGuid: string,
  payload: ClientStaffCreatePayload,
): Promise<ClientStaffItem> {
  const res = await http.post<ClientStaffItem>(
    `/v1/vets/${vetGuid}/clients/${clientGuid}/staff/new-user`,
    payload,
  )
  return res.data
}

export async function assignClientStaffApi(
  vetGuid: string,
  clientGuid: string,
  payload: ClientStaffAssignPayload,
): Promise<ClientStaffItem> {
  const res = await http.post<ClientStaffItem>(
    `/v1/vets/${vetGuid}/clients/${clientGuid}/staff`,
    payload,
  )
  return res.data
}

export async function removeClientStaffApi(vetGuid: string, clientGuid: string, profileGuid: string): Promise<void> {
  await http.delete(`/v1/vets/${vetGuid}/clients/${clientGuid}/staff/${profileGuid}`)
}

export async function toggleBlockClientStaffApi(vetGuid: string, clientGuid: string, profileGuid: string): Promise<ClientStaffItem> {
  const res = await http.patch<ClientStaffItem>(
    `/v1/vets/${vetGuid}/clients/${clientGuid}/staff/${profileGuid}/toggle-block`,
  )
  return res.data
}

export async function changeClientStaffRoleApi(
  vetGuid: string,
  clientGuid: string,
  profileGuid: string,
  roleGuid: string,
): Promise<ClientStaffItem> {
  const res = await http.patch<ClientStaffItem>(
    `/v1/vets/${vetGuid}/clients/${clientGuid}/staff/${profileGuid}/role`,
    { role_guid: roleGuid },
  )
  return res.data
}

export async function updateClientStaffApi(
  vetGuid: string,
  clientGuid: string,
  profileGuid: string,
  payload: UpdateClientStaffPayload,
): Promise<ClientStaffItem> {
  const res = await http.put<ClientStaffItem>(
    `/v1/vets/${vetGuid}/clients/${clientGuid}/staff/${profileGuid}`,
    payload,
  )
  return res.data
}
```

---

#### Composables admin (en `front/src/modules/clients/composables/admin/`)

**`useAdminClientStaff.ts`**
```typescript
// Espejo de useAdminVetStaff.ts
// queryKey: ['admin-client-staff', clientGuid]
// queryFn: adminListClientStaffApi(clientGuid)
export function useAdminClientStaff(clientGuid: MaybeRef<string>) { ... }
```

**`useAdminClientStaffMember.ts`**
```typescript
// queryKey: ['admin-client-staff-member', clientGuid, profileGuid]
// queryFn: adminGetClientStaffMemberApi(clientGuid, profileGuid)
export function useAdminClientStaffMember(clientGuid: MaybeRef<string>, profileGuid: MaybeRef<string>, enabled?: MaybeRef<boolean>) { ... }
```

**`useAdminAssignClientStaff.ts`**
```typescript
// mutationFn: adminAssignClientStaffApi(clientGuid, payload)
// onSuccess: invalidateQueries(['admin-client-staff', clientGuid])
export function useAdminAssignClientStaff(clientGuid: string) { ... }
```

**`useAdminChangeClientStaffRole.ts`**
```typescript
// mutationFn: adminChangeClientStaffRoleApi(clientGuid, profileGuid, payload)
// onSuccess: invalidateQueries(['admin-client-staff', clientGuid])
export function useAdminChangeClientStaffRole(clientGuid: string) { ... }
```

**`useAdminRemoveClientStaff.ts`**
```typescript
// mutationFn: adminRemoveClientStaffApi(clientGuid, profileGuid)
// onSuccess: invalidateQueries(['admin-client-staff', clientGuid])
// incluye confirm() dialog igual que useAdminRemoveStaff
export function useAdminRemoveClientStaff(clientGuid: string) { ... }
```

**`useAdminUpdateClientStaff.ts`**
```typescript
// mutationFn: adminUpdateClientStaffApi(clientGuid, profileGuid, payload)
// onSuccess: invalidateQueries(['admin-client-staff', clientGuid]) + ['admin-client-staff-member', clientGuid, profileGuid]
export function useAdminUpdateClientStaff(clientGuid: MaybeRef<string>) { ... }
```

---

#### Composables tenant (en `front/src/modules/clients/composables/`)

**`useClientStaff.ts`**
```typescript
// queryKey: ['client-staff', vetGuid, clientGuid]
// queryFn: listClientStaffApi(vetGuid, clientGuid)
// enabled: vetGuid && clientGuid ambos truthy
export function useClientStaff(vetGuid: MaybeRef<string>, clientGuid: MaybeRef<string>) { ... }
```

**`useClientStaffMember.ts`**
```typescript
// queryKey: ['client-staff-member', vetGuid, clientGuid, profileGuid]
// queryFn: getClientStaffMemberApi(vetGuid, clientGuid, profileGuid)
export function useClientStaffMember(vetGuid: MaybeRef<string>, clientGuid: MaybeRef<string>, profileGuid: MaybeRef<string>, enabled?: MaybeRef<boolean>) { ... }
```

**`useLookupClientStaff.ts`**
```typescript
// Espejo de useLookupVetStaff (si existe) o patrón manual con useQuery lazy
// Recibe vetGuid y clientGuid del contexto de ruta
// queryKey: ['client-staff-lookup', vetGuid, clientGuid, email]
// Expone { data, isLoading, isError, search(email), reset }
export function useLookupClientStaff() { ... }
```

**`useCreateClientStaff.ts`**
```typescript
// mutationFn: createClientStaffApi(vetGuid, clientGuid, payload)
// onSuccess: invalidateQueries(['client-staff', vetGuid, clientGuid])
export function useCreateClientStaff() { ... }
```

**`useAssignClientStaff.ts`**
```typescript
// mutationFn: assignClientStaffApi(vetGuid, clientGuid, payload)
// onSuccess: invalidateQueries(['client-staff', vetGuid, clientGuid])
export function useAssignClientStaff() { ... }
```

**`useChangeClientStaffRole.ts`**
```typescript
// mutationFn: changeClientStaffRoleApi(vetGuid, clientGuid, profileGuid, roleGuid)
// onSuccess: invalidateQueries(['client-staff', vetGuid, clientGuid]) + ['client-staff-member', ...]
export function useChangeClientStaffRole(vetGuid: string, clientGuid: string) { ... }
```

**`useToggleClientStaffBlock.ts`**
```typescript
// Espejo de useToggleVetStaffBlock
// mutationFn: toggleBlockClientStaffApi(vetGuid, clientGuid, profileGuid)
// onSuccess: invalidateQueries(['client-staff', vetGuid, clientGuid])
// incluye confirm() dialog
export function useToggleClientStaffBlock(vetGuid: string, clientGuid: string) { ... }
```

**`useRemoveClientStaff.ts`**
```typescript
// Espejo de useUnlinkVetStaff
// mutationFn: removeClientStaffApi(vetGuid, clientGuid, profileGuid)
// onSuccess: invalidateQueries(['client-staff', vetGuid, clientGuid])
// incluye confirm() dialog
export function useRemoveClientStaff(vetGuid: string, clientGuid: string) { ... }
```

**`useUpdateClientStaff.ts`**
```typescript
// mutationFn: updateClientStaffApi(vetGuid, clientGuid, profileGuid, payload)
// onSuccess: invalidateQueries(['client-staff', vetGuid, clientGuid]) + ['client-staff-member', ...]
export function useUpdateClientStaff(vetGuid: MaybeRef<string>, clientGuid: MaybeRef<string>) { ... }
```

---

#### `front/src/modules/clients/components/ClientStaffTable.vue`
**Propósito:** Tabla de staff de cliente con acciones: editar, bloquear/desbloquear, eliminar. Espejo de `VetStaffTable.vue`.

**Props:**
```typescript
defineProps<{
  staff: ClientStaffItem[]
  loading: boolean
  columns?: TableColumnDef[]
}>()
```

**Emits:**
```typescript
defineEmits<{
  edit:           [member: ClientStaffItem]
  'toggle-block': [member: ClientStaffItem]
  unlink:         [member: ClientStaffItem]
}>()
```

**Columnas por defecto:** Nombre/Email, Rol, Estado (bloqueado/activo), Alta, Acciones (con edit, toggle-block, unlink).
El estado muestra `<a-tag color="error">Bloqueado (client)</a-tag>` vs `<a-tag color="success">Activo</a-tag>`.

---

#### `front/src/modules/clients/components/ClientStaffSection.vue`
**Propósito:** Sección completa de staff para `ClientDetailPage.vue` (panel tenant). Reemplaza `OwnersSection.vue`. Incluye botón "Agregar miembro" que navega a `ClientStaffCreatePage`.

**Props:** `{ vetGuid: string, clientGuid: string }`

**Lógica:**
- Usa `useClientStaff(vetGuid, clientGuid)` para listar.
- Usa `useRemoveClientStaff(vetGuid, clientGuid)` para eliminar (con confirm).
- Usa `useToggleClientStaffBlock(vetGuid, clientGuid)` para bloquear/desbloquear.
- Botón "Agregar miembro" → `router.push(`/vets/${vetGuid}/clients/${clientGuid}/staff/new`)`.
- Botón "Editar" por fila → `router.push(`/vets/${vetGuid}/clients/${clientGuid}/staff/${member.guid}/edit`)`.
- Envuelve el botón "Agregar" en `<PermissionGuard permission="clients.staff.create">`.

---

#### `front/src/modules/clients/components/admin/AdminClientStaffSection.vue`
**Propósito:** Sección de staff para `AdminClientDetailPage.vue`. Sin botón "Agregar" (DEC-NEG-08). Solo lista + editar rol + eliminar.

**Props:** `{ clientGuid: string }`

**Lógica:**
- Usa `useAdminClientStaff(clientGuid)` para listar.
- Usa `useAdminRemoveClientStaff(clientGuid)` para eliminar.
- Botón "Editar" → `router.push(`/admin/clients/${clientGuid}/staff/${member.guid}/editar`)`.
- Sin botón de agregar (DEC-NEG-08).
- Tabla: columnas Nombre/Email, Rol, Alta, Acciones (editar, eliminar).

---

#### `front/src/modules/clients/components/ClientStaffEditForm.vue`
**Propósito:** Formulario de edición de perfil de staff de cliente. Espejo de `VetStaffEditForm.vue`.

**Props:**
```typescript
defineProps<{
  member: ClientStaffItem
  clientRoles: ClientStaffRoleItem[]
  isLoadingRoles: boolean
  isPending: boolean
}>()
```

**Emits:** `{ submit: [payload: UpdateClientStaffPayload] }`

**Lógica:** inicializa `selectedRoleGuid` y `localContacts` desde `member`, igual que `VetStaffEditForm`. Usa `<ContactsInput>`. Emite `submit` con `{ role_guid, contacts }`.

---

#### `front/src/modules/clients/pages/ClientStaffCreatePage.vue`
**Propósito:** Página del flujo lookup → create/assign. Espejo de `VetStaffCreatePage.vue`. Obtiene `vetGuid` y `clientGuid` de la ruta.

**Estructura:**
```typescript
const route       = useRoute()
const vetGuid     = computed(() => route.params.vetGuid as string)
const clientGuid  = computed(() => route.params.clientGuid as string)

function handleSuccess(): void {
  router.push(`/vets/${vetGuid.value}/clients/${clientGuid.value}`)
}
```

Renderiza un componente `ClientStaffLookupForm` (nuevo, en `front/src/modules/clients/components/forms/ClientStaffLookupForm.vue`) que encapsula el flujo de búsqueda + create/assign, análogo a `VetStaffLookupForm.vue` pero usando los composables y la API de client-staff.

**Nota sobre `ClientStaffLookupForm`:** el ticket no lo lista como artefacto separado, pero el patrón en `VetStaffCreatePage` referencia `VetStaffLookupForm`. Se debe crear `ClientStaffLookupForm.vue` en `front/src/modules/clients/components/forms/` con la misma lógica de estados (`idle`, `searching`, `found-linkable`, `found-linked`, `not-found`, `creating`, `done`). Este componente se agrega al inventario de artefactos como consecuencia del espejo.

---

#### `front/src/modules/clients/pages/ClientEditStaffPage.vue`
**Propósito:** Página de edición de un miembro del staff de cliente (panel tenant). Espejo de `VetEditStaffPage.vue`.

**Params de ruta:** `vetGuid`, `clientGuid`, `profileGuid` (todos via `useRoute()`).

**Lógica:**
```typescript
const vetGuid     = computed(() => route.params.vetGuid as string)
const clientGuid  = computed(() => route.params.clientGuid as string)
const profileGuid = computed(() => route.params.profileGuid as string)

const { data: member, isLoading, isError } = useClientStaffMember(vetGuid, clientGuid, profileGuid)
const { clientRoles, isLoading: isLoadingRoles } = useClientRoles()  // ver nota abajo
const { mutate, isPending } = useUpdateClientStaff(vetGuid, clientGuid)

function handleSubmit(payload: UpdateClientStaffPayload): void {
  mutate(
    { profileGuid: profileGuid.value, payload },
    { onSuccess: () => router.push(`/vets/${vetGuid.value}/clients/${clientGuid.value}`) },
  )
}
```

**Nota sobre `useClientRoles`:** es el equivalente de `useVetRoles` pero filtrado por `CLIENT_STAFF_ROLES`. Se debe crear `front/src/modules/clients/composables/useClientRoles.ts` que filtre los roles de la API de roles por los nombres de `CLIENT_STAFF_ROLES`. Este composable se agrega al inventario.

---

#### `front/src/modules/clients/pages/admin/AdminClientEditStaffPage.vue`
**Propósito:** Página de edición de staff desde panel admin. Espejo de `AdminVetEditStaffPage.vue`.

**Params de ruta:** `guid` (clientGuid), `profileGuid` (via props).

**Lógica:**
```typescript
// Usa useAdminClientStaffMember(computed(() => props.guid), computed(() => props.profileGuid))
// Usa useAdminUpdateClientStaff(computed(() => props.guid))
// useClientRoles() para el selector de rol
// onSuccess: router.push(`/admin/clients/${props.guid}`)
```

---

#### `front/src/modules/clients/composables/useClientRoles.ts` (artefacto adicional deducido)
**Propósito:** Equivalente de `useVetRoles` para roles de client staff.

```typescript
import { useQuery } from '@tanstack/vue-query'
import { computed } from 'vue'
import { listRolesApi } from '@/modules/roles/api/roles.api'
import type { RoleItem } from '@/modules/roles/types/role.types'
import { CLIENT_STAFF_ROLES } from '../types/client.types'

export function useClientRoles() {
  const query = useQuery({
    queryKey: ['roles-client-scope'],
    queryFn: () => listRolesApi({ per_page: 50 }),
    staleTime: 1000 * 60 * 5,
  })

  const clientRoles = computed<Pick<RoleItem, 'guid' | 'name'>[]>(() => {
    const items = query.data.value?.data ?? []
    return items.filter(r => (CLIENT_STAFF_ROLES as readonly string[]).includes(r.name))
  })

  return { ...query, clientRoles }
}
```

---

#### `front/src/modules/clients/components/forms/ClientStaffLookupForm.vue` (artefacto adicional deducido)
**Propósito:** Formulario de búsqueda + creación/asignación de staff de cliente. Espejo de `VetStaffLookupForm.vue`.

**Props:** ninguna. Obtiene `vetGuid` y `clientGuid` de la ruta.

**Emits:** `{ success: [] }`

**Lógica de estados:** idéntica a `VetStaffLookupForm`. Usa `useLookupClientStaff`, `useCreateClientStaff`, `useAssignClientStaff`. Los sub-formularios `ClientStaffAssignForm.vue` y `ClientStaffNewForm.vue` pueden implementarse inline o como componentes separados en `forms/`.

---

### Archivos a modificar

#### `front/src/modules/clients/types/client.types.ts`
**Cambio:** agregar tipos de client staff. Mantener `OwnerItem` y todos los tipos existentes.

**Agregar:**
```typescript
// --- Constante de roles válidos ---
export const CLIENT_STAFF_ROLES = ['client-owner', 'client-manager', 'client-administrative'] as const
export type ClientStaffRoleName = typeof CLIENT_STAFF_ROLES[number]

// --- Tipos de Client Staff ---

export interface ClientStaffUserItem {
  guid: string
  name: string
  first_name: string
  last_name: string
  email: string
}

export interface ClientStaffRoleItem {
  guid: string
  name: ClientStaffRoleName
}

export interface ClientStaffItem {
  guid: string
  user: ClientStaffUserItem
  role: ClientStaffRoleItem
  contacts: ContactItem[]
  blocked_at: string | null
  created_at: string
}

export interface ClientStaffAssignPayload {
  user_guid: string
  role_guid: string
  contacts?: Array<{
    type: string
    value: string
    label?: string | null
    is_primary?: boolean
    use_for_alerts?: boolean
  }>
}

export interface ClientStaffCreatePayload {
  first_name: string
  last_name: string
  email: string
  role_guid: string
  contacts?: Array<{
    type: string
    value: string
    label?: string | null
    is_primary?: boolean
    use_for_alerts?: boolean
  }>
}

export interface UpdateClientStaffPayload {
  role_guid: string
  contacts: import('@/modules/vets/types/vet.types').ContactFormItem[]
}

export interface ChangeClientStaffRolePayload {
  role_guid: string
}

export interface ClientStaffLookupResult {
  found: boolean
  already_linked: boolean | null
  user: {
    guid: string
    first_name: string
    last_name: string
    email: string
  } | null
}
```

**Nota sobre `ContactFormItem` en `UpdateClientStaffPayload`:** el tipo `ContactFormItem` está definido en `vets/types/vet.types`. Para evitar dependencia cruzada entre módulos, se puede duplicar la interface en `client.types.ts` o importarla desde un módulo core. Decisión: duplicar como `ClientStaffContactFormItem` localmente en `client.types.ts` con la misma estructura `{ guid?, type, value, label?, is_primary, use_for_alerts }`.

---

#### `front/src/modules/clients/pages/ClientDetailPage.vue`
**Cambio:** reemplazar `OwnersSection` por `ClientStaffSection`; renombrar tab "Owners" a "Staff"; pasar `vetGuid` y `clientGuid` como props a `ClientStaffSection`.

**Antes:**
```vue
import OwnersSection from '../components/OwnersSection.vue'
...
<a-tab-pane key="owners" tab="Owners">
  <OwnersSection :client-guid="guid" />
</a-tab-pane>
```

**Después:**
```vue
import ClientStaffSection from '../components/ClientStaffSection.vue'
...
<a-tab-pane key="staff" tab="Staff">
  <ClientStaffSection :vet-guid="vetGuid" :client-guid="guid" />
</a-tab-pane>
```

---

#### `front/src/modules/clients/pages/admin/AdminClientDetailPage.vue`
**Cambio:** agregar tab "Staff" con `AdminClientStaffSection`.

**Antes:** tiene solo `<a-tab-pane key="vets" tab="Veterinarias vinculadas">` con el comentario R-01 sobre iteración futura.

**Después — agregar import y tab:**
```vue
import AdminClientStaffSection from '../../components/admin/AdminClientStaffSection.vue'
...
<a-tabs class="acdp-tabs">
  <a-tab-pane key="vets" tab="Veterinarias vinculadas">
    <ClientVetsSection ... />
  </a-tab-pane>
  <a-tab-pane key="staff" tab="Staff">
    <AdminClientStaffSection :client-guid="props.guid" />
  </a-tab-pane>
</a-tabs>
```

Eliminar el comentario R-01 (ya implementado).

---

#### `front/src/modules/clients/router/clients.routes.ts`
**Cambio:** agregar rutas para `ClientStaffCreatePage` y `ClientEditStaffPage`.

**Agregar ANTES de `clients-detail` (que tiene `/:guid`):**
```typescript
{
  // /staff/new debe estar antes que las rutas con :profileGuid para evitar colisiones
  path: '/vets/:vetGuid/clients/:clientGuid/staff/new',
  name: 'clients-staff-create',
  component: () => import('@/modules/clients/pages/ClientStaffCreatePage.vue'),
  meta: { requiresAuth: true, title: 'Agregar staff al cliente' },
},
{
  path: '/vets/:vetGuid/clients/:clientGuid/staff/:profileGuid/edit',
  name: 'clients-staff-edit',
  component: () => import('@/modules/clients/pages/ClientEditStaffPage.vue'),
  meta: { requiresAuth: true, title: 'Editar staff del cliente' },
},
```

**Nota de orden:** las rutas nuevas van antes de `clients-detail` (path: `/vets/:vetGuid/clients/:guid`) para evitar que Vue Router capture `clientGuid` y `staff` como valores del param `:guid`.

---

#### `front/src/modules/clients/router/admin-clients.routes.ts`
**Cambio:** agregar ruta para `AdminClientEditStaffPage`.

**Agregar antes de `admin-clients-detail`:**
```typescript
{
  path: '/admin/clients/:guid/staff/:profileGuid/editar',
  name: 'admin-clients-staff-edit',
  component: () => import('@/modules/clients/pages/admin/AdminClientEditStaffPage.vue'),
  props: true,
  meta: { requiresAuth: true, title: 'Editar staff del cliente' },
},
```

---

### Archivos a eliminar

1. `front/src/modules/clients/components/OwnersSection.vue`
   - Consumidores actuales: `ClientDetailPage.vue` (se modifica en este ticket para usar `ClientStaffSection`).
   - Confirmación: ningún otro componente o página importa `OwnersSection`. Verificar con grep antes de eliminar.

2. `front/src/modules/clients/components/modals/OwnerFormModal.vue`
   - Consumidores actuales: solo `OwnersSection.vue` (que se elimina).
   - Importa: `useCreateOwner`, `ownerCreateSchema` (ambos quedan en el proyecto para el endpoint legacy `ClientOwnerController`).
   - Confirmación: verificar que ningún otro consumidor importe `OwnerFormModal` antes de eliminar.

**Instrucción de verificación para el dev:** antes de borrar cualquiera de los dos archivos, ejecutar:
```
grep -r "OwnersSection" front/src --include="*.vue" --include="*.ts"
grep -r "OwnerFormModal" front/src --include="*.vue" --include="*.ts"
```
Si hay hits fuera de los archivos esperados, reportar y no borrar hasta analizar.

---

## Orden de implementación

### Backend (ejecutar en orden)

1. Agregar los cuatro permisos `clients.staff.*` al array en `back/database/seeders/PermissionSeeder.php`. Correr `php artisan db:seed --class=PermissionSeeder`.

2. Agregar método `listForClient` a `back/app/Contracts/Repositories/UserProfileRepositoryInterface.php`.

3. Implementar método `listForClient` en `back/app/Repositories/UserProfileRepositoryEloquent.php`.

4. Agregar constante `CLIENT_STAFF_ROLES` y métodos `listForClient`, `findByGuidForClient`, `lookupForClient`, `createAndAssignClientStaff`, `addMemberToClient` a `back/app/Services/UserProfileService.php`.

5. Crear carpeta `back/app/Http/Requests/Members/Client/` y los siete Form Requests: `LookupClientStaffRequest`, `AssignClientStaffRequest`, `CreateClientStaffRequest`, `UpdateClientStaffRequest`, `ChangeClientStaffRoleRequest`, `AdminAssignClientStaffRequest`, `AdminChangeClientStaffRoleRequest`.

6. Crear `back/app/Http/Controllers/V1/ClientStaffController.php` con todos los métodos implementados.

7. Agregar `UserProfileService` al constructor de `back/app/Http/Controllers/V1/AdminClientController.php` y los seis métodos staff (`staffIndex`, `staffStore`, `staffShow`, `staffUpdate`, `staffChangeRole`, `staffDestroy`).

8. Agregar rutas admin y tenant en `back/routes/api/clients.php`.

9. Correr `php artisan route:list | grep client.*staff` para verificar que todas las rutas se registraron correctamente.

10. Correr los tests de backend: `php artisan test --filter=ClientStaff`.

### Frontend (ejecutar en orden)

11. Agregar tipos `CLIENT_STAFF_ROLES`, `ClientStaffItem`, `ClientStaffRoleItem`, `ClientStaffUserItem`, `ClientStaffAssignPayload`, `ClientStaffCreatePayload`, `UpdateClientStaffPayload`, `ChangeClientStaffRolePayload`, `ClientStaffLookupResult`, `ClientStaffContactFormItem` a `front/src/modules/clients/types/client.types.ts`.

12. Crear `front/src/modules/clients/api/client-staff.api.ts` con todas las funciones API.

13. Crear composable `front/src/modules/clients/composables/useClientRoles.ts`.

14. Crear los seis composables admin en `front/src/modules/clients/composables/admin/`: `useAdminClientStaff`, `useAdminClientStaffMember`, `useAdminAssignClientStaff`, `useAdminChangeClientStaffRole`, `useAdminRemoveClientStaff`, `useAdminUpdateClientStaff`.

15. Crear los diez composables tenant en `front/src/modules/clients/composables/`: `useClientStaff`, `useClientStaffMember`, `useLookupClientStaff`, `useCreateClientStaff`, `useAssignClientStaff`, `useChangeClientStaffRole`, `useToggleClientStaffBlock`, `useRemoveClientStaff`, `useUpdateClientStaff`.

16. Crear `front/src/modules/clients/components/ClientStaffTable.vue`.

17. Crear `front/src/modules/clients/components/ClientStaffEditForm.vue`.

18. Crear `front/src/modules/clients/components/forms/ClientStaffLookupForm.vue` (y sub-formularios `ClientStaffAssignForm.vue`, `ClientStaffNewForm.vue` si se extraen).

19. Crear `front/src/modules/clients/components/ClientStaffSection.vue`.

20. Crear `front/src/modules/clients/components/admin/AdminClientStaffSection.vue`.

21. Crear `front/src/modules/clients/pages/ClientStaffCreatePage.vue`.

22. Crear `front/src/modules/clients/pages/ClientEditStaffPage.vue`.

23. Crear `front/src/modules/clients/pages/admin/AdminClientEditStaffPage.vue`.

24. Modificar `front/src/modules/clients/router/clients.routes.ts` para agregar rutas `clients-staff-create` y `clients-staff-edit`.

25. Modificar `front/src/modules/clients/router/admin-clients.routes.ts` para agregar ruta `admin-clients-staff-edit`.

26. Verificar grep de consumidores de `OwnersSection` y `OwnerFormModal`, luego eliminar ambos archivos.

27. Modificar `front/src/modules/clients/pages/ClientDetailPage.vue`: reemplazar `OwnersSection` por `ClientStaffSection` con las props correctas.

28. Modificar `front/src/modules/clients/pages/admin/AdminClientDetailPage.vue`: agregar tab "Staff" con `AdminClientStaffSection` y eliminar comentario R-01.

29. Smoke test manual: navegar a un cliente en el panel tenant → tab Staff → agregar miembro → editar → bloquear → eliminar.

---

## Riesgos y consideraciones

**Riesgo 1 — Asignación de permisos `clients.staff.*` a roles tenant (crítico antes de producción).**
El `RoleSeeder` asigna permisos específicos solo a `super-admin`, `admin` y `operador`. Los roles tenant (`vet`, `vet-assistant`, `vet-administrative`) no reciben permisos explícitamente en el seeder. Si el proyecto usa los middlewares `can:*` como guards de acceso para los roles tenant, se debe verificar si el rol `vet` tiene `clients.staff.*` asignado o si opera sin restricción de permiso en ese nivel. Si el permiso es requerido por el middleware `can:`, las rutas del panel tenant (que ya usan `can:clients.*`) se comportarán igual — el rol `vet` debe tener esos permisos. Se recomienda agregar explícitamente en `RoleSeeder` o en un seeder separado. Esto es un gap en la spec que puede hacer que el panel tenant no funcione hasta resolverlo.

**Riesgo 2 — Parámetro de ruta `{client}` vs `{guid}` en el grupo tenant.**
Las rutas de owners usan `/{guid}/owners` mientras que contacts y establishments usan `/{client}/contacts` y `/{client}/establishments`. La nueva ruta de staff usa `/{client}/staff` (consistente con contacts/establishments). El dev debe asegurarse que en el bloque de owners existente el parámetro es `{guid}` y en el nuevo de staff es `{client}`, para no romper la resolución de parámetros en `ClientOwnerController` (que usa `$request->route('guid')` posiblemente).

**Riesgo 3 — `ClientOwnerController` y resolución del `{guid}` de client en rutas de owners.**
El archivo `clients.php` tiene `/{guid}/owners`. Si `ClientOwnerController` resuelve el client via `$request->route('guid')`, la ruta de staff nueva no interfiere. Solo hay riesgo si el dev mezcla nombres de parámetros en el mismo grupo. Verificar antes de testear.

**Riesgo 4 — `UpdateClientStaffPayload` importa `ContactFormItem` de módulo vets.**
Se decidió duplicar la interface como `ClientStaffContactFormItem` para independencia de módulos. El dev debe asegurarse que el tipo local es estructuralmente idéntico al de `vet.types.ts` para que la serialización HTTP sea correcta.

**Riesgo 5 — Orden de rutas en `clients.routes.ts`.**
Las rutas nuevas deben quedar en este orden estricto dentro del array:
```
/vets/:vetGuid/clients/new          (clients-create)
/vets/:vetGuid/clients/:clientGuid/staff/new  (clients-staff-create) ← NUEVO, antes de :guid
/vets/:vetGuid/clients/:clientGuid/staff/:profileGuid/edit  (clients-staff-edit) ← NUEVO
/vets/:vetGuid/clients/:guid        (clients-detail)
/vets/:vetGuid/clients/:guid/edit   (clients-edit)
```
Si `clients-staff-create` queda después de `clients-detail`, Vue Router puede intentar resolver `/clients/:clientGuid/staff/new` como `guid=clientGuid` + subruta, lo que causaría error de carga.

**Riesgo 6 — Componente `ClientStaffLookupForm` no listado en el ticket.**
El ticket lista `ClientStaffCreatePage.vue` pero no lista el componente de formulario de lookup. El patrón existente en `VetStaffCreatePage` delega todo el flujo a `VetStaffLookupForm`. Este plan agrega `ClientStaffLookupForm.vue` como artefacto deducido. Si el equipo prefiere implementar el flujo inline en la page, es aceptable — pero el patrón con componente dedicado es más testeable.

**Riesgo 7 — `SendClientOwnerInvitationJob` nombrado como "owner" pero usado para todos los roles client.**
El nombre del job es misleading para roles `client-manager` y `client-administrative`. Es deuda técnica nominal; en este ticket se reutiliza según la restricción del ticket. Un ticket posterior puede renombrar a `SendClientStaffInvitationJob` cuando haya un template de email diferenciado por rol.

**Riesgo 8 — `ChangeVetStaffRoleRequest` usa `TENANT_ROLES` en lugar de `VET_STAFF_ROLES`.**
Discrepancia encontrada en el código real: `ChangeVetStaffRoleRequest` valida contra `TENANT_ROLES` (que incluye roles de client) mientras que los otros requests de vet-staff validan contra `VET_STAFF_ROLES`. Los nuevos requests de client-staff usan `CLIENT_STAFF_ROLES` en todos los casos (incluyendo changeRole), que es el comportamiento correcto. No se modifica la inconsistencia existente en vet-staff (fuera de alcance).

---

## Pendientes / fuera de alcance

- **Paginación del staff:** la lista se retorna completa (sin paginar), igual que vet-staff hoy. Si el staff de un cliente crece, esto deberá revisarse.
- **Notificaciones diferenciadas por rol:** `SendClientOwnerInvitationJob` se reutiliza para todos los roles. Un ticket futuro puede crear `SendClientStaffInvitationJob` con template adaptado.
- **Eliminación de `ClientOwnerController`:** el controlador legacy queda funcional. Un ticket posterior puede decidir si consolidar endpoints o mantener la retrocompatibilidad.
- **Eliminación de `clients.owners.*` permissions:** los permisos legacy se mantienen. Se deprecan en nomenclatura pero no se eliminan.
- **Contactos del staff de cliente:** el módulo de contactos (similar al de `VetStaffController` que tiene `/{profile}/contacts`) no está incluido en el ticket. Si se necesita, es una iteración futura.
- **Tests E2E del flujo de lookup-create:** el flujo de tres estados en `ClientStaffLookupForm` requiere tests de integración que están fuera del alcance de los feature tests backend.
