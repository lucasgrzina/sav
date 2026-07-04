# Plan técnico: Separación de creación de usuarios de plataforma y tenant

## Input procesado
Brief informal del usuario (chat directo) — sin archivo de spec/ticket preexistente.

## Resumen ejecutivo
Se agrega un endpoint `POST /v1/users/tenant` que crea un `User` con N `UserProfile`s en una sola operación atómica, sin enviar emails (flujo diferente al `addOwnerToClient`). En el `GET /v1/roles` se agrega soporte para filtro `?type=platform|tenant` mediante un nuevo `RoleTypeCriteria`. En el frontend, el botón "Nuevo usuario" se reemplaza por un `ADropdown` de Ant Design con dos opciones: "Usuario de plataforma" (abre el modal existente, con roles filtrados a `type=platform`) y "Usuario de tenant" (abre un nuevo modal `CreateTenantUserModal` con sección de perfiles dinámica). Los módulos afectados son: `users`, `roles`, `vets` (API lookup), `clients` (API lookup) y el backend de gestión de usuarios.

---

## Decisiones tomadas

**DEC-01 — `RoleTypeCriteria` en lugar de modificar `SearchCriteria`**
Decisión: Crear `back/app/Criteria/Roles/RoleTypeCriteria.php` como criterio independiente.
Justificación: `SearchCriteria` es genérico y reutilizado en múltiples módulos. Crear un criterio específico para roles mantiene la separación de responsabilidades y sigue el patrón ya establecido (ver `UserSearchCriteria`, `UserStatusCriteria`). La instancia se pasa solo cuando hay un valor de `type` en los filtros.
Alternativa descartada: Agregar `type` como segundo parámetro constructor de `SearchCriteria` — viola el principio de responsabilidad única; `SearchCriteria` hace búsqueda por `like`, no filtrado exacto.

**DEC-02 — El `UserService.createTenantUser()` resuelve tenants por GUID, no crea entidades nuevas**
Decisión: El endpoint recibe `vet_guid` o `client_guid` por perfil. El service los resuelve con `VetRepositoryInterface::findByGuid()` y `ClientRepositoryInterface::findByGuid()`. Si no existe, lanza `\RuntimeException` que el controller convierte en 422.
Justificación: Es el patrón establecido en `addMember()` y `staffStore()`. No se crean vets ni clients desde este endpoint; solo se asocian existentes.
Alternativa descartada: Pasar `authenticatable_id` (integer) directamente — viola la regla dura de no exponer IDs internos en payloads.

**DEC-03 — No usar `UserProfileService::addOwnerToClient()` ni `addMember()` en el nuevo método**
Decisión: `UserService::createTenantUser()` llama directamente a `userProfileRepository->create()` para cada perfil.
Justificación: `addOwnerToClient()` envía un job de email de invitación al crear usuario nuevo, comportamiento no deseado aquí (el admin está creando el usuario con password explícito, ya verificado). `addMember()` requiere que el usuario ya exista y valida duplicados por vet — lógica parcialmente útil pero mezclada con concerns del dominio vet. Es más limpio y explícito hacerlo directo en el service de usuarios con la transacción que envuelve todo.
Alternativa descartada: Reutilizar `addMember()` en un loop — mezcla contextos, no maneja el caso client, y no está dentro de la misma transacción.

**DEC-04 — Validar unicidad de perfil (user+authenticatable) en el Service, no en la Request**
Decisión: El service valida que no exista ya un `UserProfile` con la misma combinación `[user_id, authenticatable_type, authenticatable_id]` antes de crear. Como el usuario es nuevo, esta validación es un safety net para el caso de que haya dos perfiles al mismo tenant en el array de entrada.
Justificación: La tabla ya tiene un `UNIQUE` constraint (`up_user_auth_unique`), así que una inserción duplicada lanzaría una `QueryException`. Pero la validación explícita en el service permite retornar un mensaje de error legible. La Request no puede hacer esta validación porque el usuario aún no existe al momento de validar.
Alternativa descartada: Dejar que la DB lance la excepción y capturarla en el controller — genera un mensaje genérico de error de DB, no útil para el frontend.

**DEC-05 — Validar en `CreateTenantUserRequest` que cada perfil tiene `role_guid` + exactamente uno de `vet_guid`/`client_guid`**
Decisión: La request valida que `profiles` es array con mínimo 1 elemento, que cada elemento tiene `role_guid` (exists:roles,guid) y exactamente uno de `vet_guid`/`client_guid`. La coherencia entre el tipo de rol y el tipo de tenant (vet vs client) se valida en el Service, no en la Request.
Justificación: La request puede validar estructura/existencia. La semántica "si rol es vet* entonces debe venir vet_guid" requiere resolver el rol desde DB dentro de la request, lo cual añade una query por perfil en la capa de validación y mezcla concerns. El service tiene acceso al modelo de rol y puede validarlo con un mensaje claro.
Alternativa descartada: Validación completa en `CreateTenantUserRequest` — agrega N queries a la capa de validación (N = cantidad de perfiles) y mezcla lógica de negocio con validación de estructura.

**DEC-06 — `UserForm.vue` recibe `roleType` prop para filtrar roles**
Decisión: Agregar prop opcional `roleType?: 'platform' | 'tenant'` a `UserForm.vue`. Cuando está presente, se pasa como filtro al `useRoles()`. Cuando no está presente, comportamiento actual (carga todos los roles).
Justificación: El `CreateUserModal` existente usa `UserForm` en modo `create` y necesita mostrar solo roles `platform`. Agregar la prop es el cambio mínimo que no rompe el `EditUserModal` (que no la usará, ya que editar usuario no cambia el contexto de roles por ahora).
Alternativa descartada: Filtrar los roles en el componente padre y pasarlos como prop al form — requeriría cambiar la interfaz del componente más drásticamente y duplicar la lógica de carga en cada caller.

**DEC-07 — `CreateTenantUserModal` es un componente autónomo, no reutiliza `UserForm`**
Decisión: El nuevo modal no reutiliza `UserForm.vue`. Tiene su propio form interno con los campos de usuario + la sección dinámica de perfiles.
Justificación: `UserForm` está diseñado para el caso usuario+roles Spatie (múltiples roles). El nuevo form necesita usuario+perfiles (rol+tenant), que es un shape diferente. Intentar parametrizar `UserForm` para ambos casos lo haría demasiado complejo. La duplicación de los 5 campos básicos (nombre, apellido, email, password, confirm) es aceptable dado el contexto diferente.
Alternativa descartada: Parametrizar `UserForm` con slot para la sección de roles — haría el componente difícil de mantener y violaría el principio de responsabilidad única.

**DEC-08 — Los lookups de vet/client en el frontend usan los endpoints de admin existentes con búsqueda**
Decisión: El `CreateTenantUserModal` llama a `listVetsApi({ search, per_page: 20 })` y `adminListClientsApi({ search, per_page: 20 })` conforme el usuario escribe. Se usa `useQuery` con `enabled` dinámico (solo cuando el rol seleccionado define el tipo de tenant).
Justificación: Los endpoints ya existen, ya soportan `?search=`, ya están paginados. No se necesita ningún endpoint nuevo para los lookups.
Alternativa descartada: Cargar todos los vets/clients al abrir el modal — inviable en producción con cientos de entidades.

**DEC-09 — No se crea un `useAdminClients` nuevo; se usa `useQuery` directo en el modal o un composable inline**
Decisión: En `CreateTenantUserModal`, para cada perfil se usa `useQuery` parametrizado con el search input del perfil, llamando a `adminListClientsApi`. No se crea un composable genérico de admin-clients porque el único consumidor hoy es este modal, y el patrón de `useVets` ya está disponible para copiar.
Justificación: Crear un composable para un único uso agrega indirección innecesaria. Si en el futuro se necesita en otro contexto, se extrae entonces.
Alternativa descartada: Crear `useAdminClients` composable — overhead innecesario para un único consumidor actual.

**DEC-10 — `RoleFilters` type se extiende con `type?: 'platform' | 'tenant'`**
Decisión: Agregar `type` a la interface `RoleFilters` en `front/src/modules/roles/types/role.types.ts`. También agregar a `RoleListParams`.
Justificación: `listRolesApi` pasa los params directo al query string. Con `type` en `RoleListParams` el filtro llega al backend sin cambios en la función de API.
Alternativa descartada: Filtrar en el frontend después de cargar todos los roles — con `per_page: 100` funciona hoy pero no escala si se agregan muchos roles tenant en el futuro.

---

## Cambios en BACKEND

### Archivos a crear

#### `back/app/Criteria/Roles/RoleTypeCriteria.php`
**Propósito:** Filtrar roles por `type` exacto (`platform` o `tenant`) en queries del repositorio.
**Firma principal:**
```php
namespace App\Criteria\Roles;

use App\Contracts\QueryCriterion;
use Illuminate\Database\Eloquent\Builder;

class RoleTypeCriteria implements QueryCriterion
{
    public function __construct(
        private ?string $type,
    ) {}

    public function apply(Builder $query): Builder
    {
        if ($this->type !== null) {
            $query->where('type', $this->type);
        }

        return $query;
    }
}
```
**Dependencias inyectadas:** ninguna.

---

#### `back/app/Http/Requests/Users/CreateTenantUserRequest.php`
**Propósito:** Validar el payload de creación de usuario de tenant con N perfiles.
**Firma principal:**
```php
namespace App\Http\Requests\Users;

use Illuminate\Foundation\Http\FormRequest;

class CreateTenantUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name'            => ['required', 'string', 'max:50', 'regex:/^[a-zA-ZñÑáéíóúÁÉÍÓÚ\s]+$/'],
            'last_name'             => ['required', 'string', 'max:50', 'regex:/^[a-zA-ZñÑáéíóúÁÉÍÓÚ\s]+$/'],
            'email'                 => ['required', 'email', 'unique:users,email'],
            'password'              => [
                'required',
                'string',
                'between:8,12',
                'confirmed',
                'regex:/[A-Z]/',
                'regex:/[0-9]/',
                'regex:/[!@#$%&]/',
            ],
            'password_confirmation' => ['required', 'string'],
            'profiles'              => ['required', 'array', 'min:1'],
            'profiles.*.role_guid'  => ['required', 'string', 'exists:roles,guid'],
            'profiles.*.vet_guid'   => ['nullable', 'string', 'exists:vets,guid'],
            'profiles.*.client_guid'=> ['nullable', 'string', 'exists:clients,guid'],
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.required'          => 'El nombre es requerido.',
            'first_name.max'               => 'El nombre no puede superar 50 caracteres.',
            'first_name.regex'             => 'El nombre solo puede contener letras.',
            'last_name.required'           => 'El apellido es requerido.',
            'last_name.max'                => 'El apellido no puede superar 50 caracteres.',
            'last_name.regex'              => 'El apellido solo puede contener letras.',
            'email.required'               => 'El email es requerido.',
            'email.email'                  => 'El email no tiene un formato válido.',
            'email.unique'                 => 'Ya existe un usuario con ese email.',
            'password.required'            => 'La contraseña es requerida.',
            'password.between'             => 'La contraseña debe tener entre 8 y 12 caracteres.',
            'password.confirmed'           => 'Las contraseñas no coinciden.',
            'password.regex'               => 'La contraseña debe contener al menos una mayúscula, un número y un símbolo (!@#$%&).',
            'password_confirmation.required' => 'La confirmación de contraseña es requerida.',
            'profiles.required'            => 'Debe agregar al menos un perfil de acceso.',
            'profiles.min'                 => 'Debe agregar al menos un perfil de acceso.',
            'profiles.*.role_guid.required'=> 'Cada perfil debe tener un rol seleccionado.',
            'profiles.*.role_guid.exists'  => 'El rol seleccionado no es válido.',
            'profiles.*.vet_guid.exists'   => 'La veterinaria seleccionada no es válida.',
            'profiles.*.client_guid.exists'=> 'El cliente seleccionado no es válido.',
        ];
    }
}
```
**Dependencias:** ninguna extra. La validación `exists:vets,guid` y `exists:clients,guid` usa las tablas directamente.

**Nota:** La validación de coherencia semántica (si rol es `vet*` entonces `vet_guid` es requerido, si es `client*` entonces `client_guid` es requerido) se realiza en el Service, no aquí. La Request valida estructura y existencia de entidades.

---

### Archivos a modificar

#### `back/app/Services/RoleService.php`
**Cambio:** Agregar `RoleTypeCriteria` al método `list()`.

**Antes:**
```php
public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
{
    return $this->roleRepository->list(
        $perPage,
        new SearchCriteria($filters['search'] ?? null),
        new DateRangeCriteria($filters['date_from'] ?? null, $filters['date_to'] ?? null),
    );
}
```

**Después:**
```php
use App\Criteria\Roles\RoleTypeCriteria;

public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
{
    return $this->roleRepository->list(
        $perPage,
        new SearchCriteria($filters['search'] ?? null),
        new DateRangeCriteria($filters['date_from'] ?? null, $filters['date_to'] ?? null),
        new RoleTypeCriteria($filters['type'] ?? null),
    );
}
```

**Import a agregar:** `use App\Criteria\Roles\RoleTypeCriteria;`

---

#### `back/app/Http/Controllers/V1/RoleController.php`
**Cambio:** Agregar `'type'` al array de filtros extraídos del request.

**Antes:**
```php
$filters = $request->only(['search']);
```

**Después:**
```php
$filters = $request->only(['search', 'type']);
```

Solo esta línea cambia. El resto del método `index()` permanece igual.

---

#### `back/app/Services/UserService.php`
**Cambio:** Agregar método `createTenantUser()`, inyectar `UserProfileRepositoryInterface`, `VetRepositoryInterface` y `ClientRepositoryInterface`.

**Constructor actualizado:**
```php
public function __construct(
    private UserRepositoryInterface        $userRepository,
    private RoleRepositoryInterface        $roleRepository,
    private UserProfileRepositoryInterface $userProfileRepository,
    private VetRepositoryInterface         $vetRepository,
    private ClientRepositoryInterface      $clientRepository,
) {}
```

**Nuevo método a agregar al final de la clase:**
```php
/**
 * Crea un usuario de plataforma con N perfiles de tenant en una sola transacción.
 * No envía emails. El usuario queda verificado (email_verified_at = now()).
 *
 * @param  array $data  Datos validados de CreateTenantUserRequest
 * @return User         Usuario creado con relación 'roles' cargada (vacía, sin roles Spatie)
 * @throws \RuntimeException  Si un rol no es de tipo tenant,
 *                            si falta el tenant correspondiente al tipo de rol,
 *                            o si el tenant no existe.
 */
public function createTenantUser(array $data): User
{
    return DB::transaction(function () use ($data) {
        // 1. Crear el User (mismo patrón que create(), sin syncRoles)
        $user = $this->userRepository->create([
            'first_name'        => $data['first_name'],
            'last_name'         => $data['last_name'],
            'name'              => $data['first_name'] . ' ' . $data['last_name'],
            'email'             => $data['email'],
            'password'          => Hash::make($data['password']),
            'email_verified_at' => now(),
        ]);

        // 2. Para cada perfil: resolver rol, resolver tenant, crear UserProfile
        foreach ($data['profiles'] as $index => $profileData) {
            $role = $this->roleRepository->findByGuid($profileData['role_guid']);

            if (!$role) {
                throw new \RuntimeException("Perfil #{$index}: rol no encontrado.");
            }

            if (!$role->isTenant()) {
                throw new \RuntimeException("Perfil #{$index}: el rol '{$role->name}' no es un rol de tenant.");
            }

            // Determinar tipo de tenant según el nombre del rol
            $isTenantVet    = str_starts_with($role->name, 'vet');
            $isTenantClient = str_starts_with($role->name, 'client');

            if ($isTenantVet) {
                if (empty($profileData['vet_guid'])) {
                    throw new \RuntimeException("Perfil #{$index}: el rol '{$role->name}' requiere seleccionar una veterinaria.");
                }
                $tenant = $this->vetRepository->findByGuid($profileData['vet_guid']);
                if (!$tenant) {
                    throw new \RuntimeException("Perfil #{$index}: veterinaria no encontrada.");
                }
                $authenticatableType = 'vet';
            } elseif ($isTenantClient) {
                if (empty($profileData['client_guid'])) {
                    throw new \RuntimeException("Perfil #{$index}: el rol '{$role->name}' requiere seleccionar un cliente.");
                }
                $tenant = $this->clientRepository->findByGuid($profileData['client_guid']);
                if (!$tenant) {
                    throw new \RuntimeException("Perfil #{$index}: cliente no encontrado.");
                }
                $authenticatableType = 'client';
            } else {
                throw new \RuntimeException("Perfil #{$index}: no se puede determinar el tipo de tenant para el rol '{$role->name}'.");
            }

            $this->userProfileRepository->create([
                'user_id'              => $user->id,
                'authenticatable_type' => $authenticatableType,
                'authenticatable_id'   => $tenant->id,
                'role_id'              => $role->id,
            ]);
        }

        return $user->load('roles');
    });
}
```

**Imports a agregar al encabezado del archivo:**
```php
use App\Contracts\Repositories\ClientRepositoryInterface;
use App\Contracts\Repositories\UserProfileRepositoryInterface;
use App\Contracts\Repositories\VetRepositoryInterface;
use Illuminate\Support\Facades\DB;
```

**Nota sobre `DB`:** verificar si ya está importado en el archivo (actualmente no lo está — el `UserService` actual no usa transacciones). Agregar el import.

---

#### `back/app/Http/Controllers/V1/UserController.php`
**Cambio:** Agregar método `storeTenant()` e inyectar `CreateTenantUserRequest`.

**Nuevo método a agregar (después de `store()`):**
```php
public function storeTenant(CreateTenantUserRequest $request): JsonResponse
{
    try {
        $user = $this->userService->createTenantUser($request->validated());

        return $this->makeSuccess(new UserResource($user), 'Usuario de tenant creado correctamente.', 201);
    } catch (\RuntimeException $e) {
        return $this->makeError(null, $e->getMessage(), 422);
    } catch (\Exception $e) {
        return $this->makeFromException($e);
    }
}
```

**Import a agregar:**
```php
use App\Http\Requests\Users\CreateTenantUserRequest;
```

El constructor no cambia (`UserService` es la única dependencia inyectada).

---

#### `back/routes/api/users.php`
**Cambio:** Agregar ruta `POST /v1/users/tenant` ANTES de `/{guid}` para evitar que el parámetro dinámico capture la literal `tenant`.

**Antes:**
```php
Route::prefix('v1/users')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [UserController::class, 'index']);
    Route::post('/', [UserController::class, 'store']);
    Route::get('/{guid}', [UserController::class, 'show']);
    ...
});
```

**Después:**
```php
Route::prefix('v1/users')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [UserController::class, 'index']);
    Route::post('/', [UserController::class, 'store']);
    Route::post('/tenant', [UserController::class, 'storeTenant']); // ANTES de /{guid}
    Route::get('/{guid}', [UserController::class, 'show']);
    Route::put('/{guid}', [UserController::class, 'update']);
    Route::delete('/{guid}', [UserController::class, 'destroy']);
    Route::patch('/{guid}/toggle-lock', [UserController::class, 'toggleLock']);
    Route::patch('/{guid}/change-password', [UserController::class, 'changePassword']);
    Route::post('/{guid}/reset-password', [UserController::class, 'resetPassword']);
});
```

**Middleware:** `auth:sanctum` heredado del group, sin cambios. No se agrega permiso Spatie en esta iteración (mismo nivel de acceso que `store`).

---

### Migrations
No se requieren migrations nuevas. La tabla `user_profiles` ya tiene todos los campos necesarios (`user_id`, `authenticatable_type`, `authenticatable_id`, `role_id`) y el constraint de unicidad.

---

### Rutas API

| Método | Path | Controller@action | Middleware |
|--------|------|-------------------|------------|
| `POST` | `/v1/users/tenant` | `UserController@storeTenant` | `auth:sanctum` |
| `GET` | `/v1/roles` | `RoleController@index` *(modificado)* | `auth:sanctum` |

El endpoint de roles ya existe; se modifica su comportamiento para aceptar `?type=platform|tenant`.

---

### Permisos Spatie
No se agregan permisos nuevos en esta iteración. El endpoint `POST /v1/users/tenant` queda bajo el mismo nivel de acceso que `POST /v1/users` (autenticado con Sanctum). Si en el futuro se diferencia el permiso, se agrega `users.tenant.create` al seeder.

---

### Contrato de endpoints

#### `POST /v1/users/tenant`

Request:
```json
{
  "first_name": "Juan",
  "last_name": "García",
  "email": "juan@vetarg.com",
  "password": "Secret1!",
  "password_confirmation": "Secret1!",
  "profiles": [
    {
      "role_guid": "uuid-del-rol-vet",
      "vet_guid": "uuid-de-la-veterinaria"
    },
    {
      "role_guid": "uuid-del-rol-client-owner",
      "client_guid": "uuid-del-cliente"
    }
  ]
}
```

Response 201:
```json
{
  "success": true,
  "data": {
    "id": 42,
    "guid": "uuid-del-usuario",
    "first_name": "Juan",
    "last_name": "García",
    "name": "Juan García",
    "email": "juan@vetarg.com",
    "email_verified_at": "2026-06-12T00:00:00.000000Z",
    "locked_at": null,
    "last_login_at": null,
    "created_at": "2026-06-12T00:00:00.000000Z",
    "status": "verified",
    "roles": []
  },
  "message": "Usuario de tenant creado correctamente."
}
```

Response 422 — validación fallida:
```json
{
  "success": false,
  "data": null,
  "message": "Los datos proporcionados no son válidos.",
  "errors": {
    "profiles.0.role_guid": ["El rol seleccionado no es válido."],
    "email": ["Ya existe un usuario con ese email."]
  }
}
```

Response 422 — error de negocio (RuntimeException del service):
```json
{
  "success": false,
  "data": null,
  "message": "Perfil #0: el rol 'admin' no es un rol de tenant.",
  "errors": null
}
```

#### `GET /v1/roles?type=platform` (modificado)

Response 200 (sin cambios estructurales, ahora soporta filtro):
```json
{
  "success": true,
  "data": {
    "data": [
      { "guid": "...", "name": "admin", "type": "platform", "permissions": [...], "created_at": "..." }
    ],
    "current_page": 1,
    "last_page": 1,
    "per_page": 100,
    "total": 3
  }
}
```

---

### Tests a generar (qué cubrir, no el código)

**Feature tests — `UserController` (nuevo endpoint):**

1. `test_store_tenant_user_creates_user_with_vet_profile` — happy path: usuario con un perfil vet. Verificar que se crea `User` y `UserProfile` con `authenticatable_type = 'vet'`.
2. `test_store_tenant_user_creates_user_with_client_profile` — happy path: usuario con un perfil client-owner.
3. `test_store_tenant_user_creates_user_with_multiple_profiles` — usuario con 2 perfiles (un vet y un client). Verificar que se crean dos `UserProfile`.
4. `test_store_tenant_user_fails_with_platform_role` — enviar un rol platform → 422 con mensaje de error del service.
5. `test_store_tenant_user_fails_with_vet_role_but_no_vet_guid` — rol vet sin `vet_guid` → 422.
6. `test_store_tenant_user_fails_with_client_role_but_no_client_guid` — rol client-owner sin `client_guid` → 422.
7. `test_store_tenant_user_fails_with_duplicate_email` — email ya existente → 422 con `errors.email`.
8. `test_store_tenant_user_fails_with_empty_profiles` — `profiles: []` → 422 con `errors.profiles`.
9. `test_store_tenant_user_requires_authentication` — sin token → 401.
10. `test_store_tenant_user_transaction_rollback_on_failure` — si falla la creación del segundo perfil (ej: vet no existe), no debe existir el `User` creado.

**Feature tests — `RoleController` (filtro por type):**

11. `test_roles_index_filtered_by_platform_type` — `GET /v1/roles?type=platform` retorna solo roles platform.
12. `test_roles_index_filtered_by_tenant_type` — `GET /v1/roles?type=tenant` retorna solo roles tenant.
13. `test_roles_index_without_type_filter_returns_all` — sin `?type` retorna todos los roles (comportamiento existente).

**Unit tests — `UserService::createTenantUser()`:**

14. `test_create_tenant_user_with_valid_vet_profile_calls_repository` — mock de repositorios, verificar que `userProfileRepository->create()` se llama con `authenticatable_type = 'vet'`.
15. `test_create_tenant_user_throws_on_platform_role` — mock de roleRepository retornando un rol con `isTenant() = false`.
16. `test_create_tenant_user_throws_when_vet_guid_missing_for_vet_role`.
17. `test_create_tenant_user_throws_when_client_guid_missing_for_client_role`.

---

## Cambios en FRONTEND

### Archivos a modificar

#### `front/src/modules/roles/types/role.types.ts`
**Cambio:** Agregar `type` a `RoleFilters` y a `RoleListParams`.

**Antes:**
```typescript
export interface RoleFilters {
  search?: string
  page?: number
  per_page?: number
}

export interface RoleListParams {
  search?: string
  page?: number
  per_page?: number
}
```

**Después:**
```typescript
export interface RoleFilters {
  search?: string
  page?: number
  per_page?: number
  type?: RoleType
}

export interface RoleListParams {
  search?: string
  page?: number
  per_page?: number
  type?: RoleType
}
```

`RoleType` ya está definido en este mismo archivo como `'platform' | 'tenant'`.

---

#### `front/src/modules/users/components/forms/UserForm.vue`
**Cambio:** Agregar prop `roleType?: 'platform' | 'tenant'` y pasarla como filtro a `useRoles`.

**Cambio en `<script setup>`:**

Sección de props — antes:
```typescript
const props = withDefaults(
  defineProps<{
    mode: 'create' | 'edit'
    initialValues?: Partial<UserUpdatePayload>
    loading?: boolean
    fieldErrors?: Record<string, string> | null
  }>(),
  { loading: false },
)
```

Después:
```typescript
import type { RoleType } from '@/modules/roles/types/role.types'

const props = withDefaults(
  defineProps<{
    mode: 'create' | 'edit'
    initialValues?: Partial<UserUpdatePayload>
    loading?: boolean
    fieldErrors?: Record<string, string> | null
    roleType?: RoleType
  }>(),
  { loading: false },
)
```

Sección de carga de roles — antes:
```typescript
const { data: rolesData, isLoading: isLoadingRoles } = useRoles({ per_page: 100 })
```

Después:
```typescript
const rolesFilters = computed(() => ({
  per_page: 100,
  ...(props.roleType ? { type: props.roleType } : {}),
}))
const { data: rolesData, isLoading: isLoadingRoles } = useRoles(rolesFilters)
```

Import a agregar: `import { computed } from 'vue'` (ya existe en el archivo), `import type { RoleType } from '@/modules/roles/types/role.types'`.

**Sin cambios en el template.** El filtro actúa sobre los datos devueltos por `useRoles`.

---

#### `front/src/modules/users/components/modals/CreateUserModal.vue`
**Cambio:** Pasar `role-type="platform"` al `UserForm`.

**Antes:**
```html
<UserForm
  mode="create"
  :loading="isPending"
  :field-errors="fieldErrors"
  @submit="handleSubmit"
>
```

**Después:**
```html
<UserForm
  mode="create"
  role-type="platform"
  :loading="isPending"
  :field-errors="fieldErrors"
  @submit="handleSubmit"
>
```

Solo se agrega el atributo `role-type="platform"`.

---

#### `front/src/modules/users/stores/users.store.ts`
**Cambio:** Agregar `'createTenant'` a `ModalType`.

**Antes:**
```typescript
type ModalType = 'create' | 'edit' | 'delete' | 'changePassword' | 'resetPassword' | null
```

**Después:**
```typescript
type ModalType = 'create' | 'createTenant' | 'edit' | 'delete' | 'changePassword' | 'resetPassword' | null
```

Solo esta línea cambia. El resto de la store (funciones `openModal`, `closeModal`) funciona sin cambios gracias al tipado de `NonNullable<ModalType>`.

---

#### `front/src/modules/users/types/user.types.ts`
**Cambio:** Agregar `TenantUserProfilePayload` y `TenantUserCreatePayload`.

Agregar al final del archivo:
```typescript
export interface TenantUserProfilePayload {
  role_guid: string
  vet_guid?: string
  client_guid?: string
}

export interface TenantUserCreatePayload {
  first_name: string
  last_name: string
  email: string
  password: string
  password_confirmation: string
  profiles: TenantUserProfilePayload[]
}
```

---

#### `front/src/modules/users/validators/user.validator.ts`
**Cambio:** Agregar `tenantUserCreateSchema` al final del archivo.

```typescript
const tenantUserProfileSchema = z.object({
  role_guid: z.string().min(1, 'Seleccioná un rol'),
  vet_guid:    z.string().optional(),
  client_guid: z.string().optional(),
})

export const tenantUserCreateSchema = z
  .object({
    first_name: z
      .string()
      .min(1, 'El nombre es requerido')
      .max(50, 'Máximo 50 caracteres')
      .regex(/^[a-zA-ZñÑáéíóúÁÉÍÓÚ\s]+$/, 'Solo letras'),
    last_name: z
      .string()
      .min(1, 'El apellido es requerido')
      .max(50, 'Máximo 50 caracteres')
      .regex(/^[a-zA-ZñÑáéíóúÁÉÍÓÚ\s]+$/, 'Solo letras'),
    email: z.string().email('Email inválido'),
    password: passwordSchema,
    password_confirmation: z.string(),
    profiles: z
      .array(tenantUserProfileSchema)
      .min(1, 'Debe agregar al menos un perfil de acceso'),
  })
  .refine((d) => d.password === d.password_confirmation, {
    message: 'Las contraseñas no coinciden',
    path: ['password_confirmation'],
  })

export type TenantUserCreateForm = z.infer<typeof tenantUserCreateSchema>
```

**Nota:** `passwordSchema` ya está definido en el archivo como constante local, lo puede reutilizar directamente.

---

#### `front/src/modules/users/api/users.api.ts`
**Cambio:** Agregar `createTenantUserApi`.

```typescript
import type {
  // ...imports existentes...
  TenantUserCreatePayload,
} from '../types/user.types'

export async function createTenantUserApi(payload: TenantUserCreatePayload): Promise<UserItem> {
  const response = await http.post<UserItem>('/v1/users/tenant', payload)
  return response.data
}
```

Agregar el import de `TenantUserCreatePayload` a la línea de imports existentes.

---

#### `front/src/modules/users/pages/UsersPage.vue`
**Cambio:** Reemplazar `BaseButton` por un `ADropdown` de Ant Design con dos opciones, agregar `showCreateTenant` computed, importar `CreateTenantUserModal`.

**Cambios en `<script setup>`:**

Agregar imports:
```typescript
import { DownOutlined } from '@ant-design/icons-vue'
import CreateTenantUserModal from '../components/modals/CreateTenantUserModal.vue'
```

Agregar `showCreateTenant` computed (junto a `showCreate`):
```typescript
const showCreateTenant = computed({
  get: () => usersUiStore.activeModal === 'createTenant',
  set: (v) => { if (!v) usersUiStore.closeModal() },
})
```

**Cambios en template — reemplazar el `BaseButton`:**

Antes:
```html
<BaseButton :size="buttonSize" @click="usersUiStore.openModal('create')">
  <template #icon><PlusOutlined /></template>
  Nuevo usuario
</BaseButton>
```

Después:
```html
<a-dropdown>
  <a-button type="primary" :size="buttonSize">
    Nuevo usuario
    <template #icon><DownOutlined /></template>
  </a-button>
  <template #overlay>
    <a-menu>
      <a-menu-item key="platform" @click="usersUiStore.openModal('create')">
        Usuario de plataforma
      </a-menu-item>
      <a-menu-item key="tenant" @click="usersUiStore.openModal('createTenant')">
        Usuario de tenant
      </a-menu-item>
    </a-menu>
  </template>
</a-dropdown>
```

**Agregar modal al template** (junto a `CreateUserModal`):
```html
<CreateTenantUserModal v-model="showCreateTenant" />
```

---

### Archivos a crear (frontend)

#### `front/src/modules/users/composables/useCreateTenantUser.ts`
**Propósito:** Composable de mutación para crear usuario de tenant, patrón idéntico a `useCreateUser`.
```typescript
import { ref } from 'vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { createTenantUserApi } from '@/modules/users/api/users.api'
import { useNotification } from '@/core/composables/useNotification'
import { parseApiError } from '@/core/composables/parseApiError'
import type { TenantUserCreatePayload } from '../types/user.types'

export function useCreateTenantUser() {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const fieldErrors = ref<Record<string, string> | null>(null)
  const generalError = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: (payload: TenantUserCreatePayload) => createTenantUserApi(payload),
    onMutate: () => {
      fieldErrors.value = null
      generalError.value = null
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['users'] })
      success('Usuario de tenant creado correctamente')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      fieldErrors.value = apiError.fieldErrors
      generalError.value = apiError.message ?? 'Error al crear el usuario.'
      if (apiError.message) {
        error('Error al crear el usuario de tenant')
      }
    },
  })

  function resetErrors() {
    fieldErrors.value = null
    generalError.value = null
    mutation.reset()
  }

  return { ...mutation, fieldErrors, generalError, resetErrors }
}
```

---

#### `front/src/modules/users/components/modals/CreateTenantUserModal.vue`
**Propósito:** Modal para crear usuario con N perfiles de acceso a tenants.

**Estructura del componente:**

```typescript
// <script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useForm, useFieldArray } from 'vee-validate'
import { toTypedSchema } from '@vee-validate/zod'
import { PlusOutlined, DeleteOutlined } from '@ant-design/icons-vue'
import { useQuery } from '@tanstack/vue-query'
import BaseModal from '@/components/atoms/overlays/BaseModal.vue'
import { useCreateTenantUser } from '../../composables/useCreateTenantUser'
import { useRoles } from '@/modules/roles/composables/useRoles'
import { listVetsApi } from '@/modules/vets/api/vets.api'
import { adminListClientsApi } from '@/modules/clients/api/admin-clients.api'
import { tenantUserCreateSchema } from '@/modules/users/validators/user.validator'
import type { TenantUserCreatePayload } from '../../types/user.types'
```

**Lógica del componente:**

```typescript
const isOpen = defineModel<boolean>({ default: false })
const { mutate, isPending, fieldErrors, generalError, resetErrors } = useCreateTenantUser()

watch(isOpen, (open) => { if (open) { resetErrors(); reset() } })

// Carga de roles tenant (per_page:100 — hay 6 roles tenant)
const { data: rolesData, isLoading: isLoadingRoles } = useRoles({ type: 'tenant', per_page: 100 })
const tenantRoles = computed(() =>
  (rolesData.value?.data ?? []).map((r) => ({ value: r.guid, label: r.name, name: r.name }))
)

// Formulario con vee-validate
const { errors, defineField, handleSubmit, reset, setErrors } = useForm({
  validationSchema: toTypedSchema(tenantUserCreateSchema),
  initialValues: { profiles: [{ role_guid: '', vet_guid: undefined, client_guid: undefined }] },
})

// Campos básicos
const [first_name, firstNameAttrs] = defineField('first_name')
const [last_name, lastNameAttrs] = defineField('last_name')
const [email, emailAttrs] = defineField('email')
const [password, passwordAttrs] = defineField('password')
const [password_confirmation, passwordConfirmationAttrs] = defineField('password_confirmation')

// Array dinámico de perfiles con useFieldArray de vee-validate
const { fields: profileFields, push: addProfile, remove: removeProfile } = useFieldArray('profiles')

// Para cada perfil: búsqueda de vet/client
// Cada perfil mantiene su propio estado de búsqueda
const vetSearches = ref<Record<number, string>>({})
const clientSearches = ref<Record<number, string>>({})

// Determinar tipo de tenant según nombre del rol seleccionado
function getTenantType(roleGuid: string): 'vet' | 'client' | null {
  const role = tenantRoles.value.find((r) => r.value === roleGuid)
  if (!role) return null
  if (role.name.startsWith('vet')) return 'vet'
  if (role.name.startsWith('client')) return 'client'
  return null
}

// useQuery para vets (por perfil — se instancia un useQuery por perfil activo)
// DECISIÓN: dado que el número de perfiles es pequeño (1-N, típicamente 1-3),
// se usan refs reactivas de búsqueda por índice y se llama listVetsApi en el queryFn.
// Esta técnica evita crear un composable dinámico.

function useVetSearch(index: number) {
  const search = computed(() => vetSearches.value[index] ?? '')
  return useQuery({
    queryKey: ['vets-search', index, search],
    queryFn: ({ signal }) => listVetsApi({ search: search.value, per_page: 20 }, signal),
    enabled: computed(() => Boolean(search.value)),
    staleTime: 1000 * 30,
  })
}

function useClientSearch(index: number) {
  const search = computed(() => clientSearches.value[index] ?? '')
  return useQuery({
    queryKey: ['clients-search', index, search],
    queryFn: ({ signal }) => adminListClientsApi({ search: search.value, per_page: 20 }, signal),
    enabled: computed(() => Boolean(search.value)),
    staleTime: 1000 * 30,
  })
}
```

**Nota importante sobre el patrón de `useQuery` por perfil:** Vue Query no tiene soporte nativo para arrays dinámicos de queries. La solución recomendada es renderizar las búsquedas dentro de un componente hijo por perfil (ver estructura del template abajo). Este es el patrón correcto.

**Estructura del template:**
```html
<BaseModal v-model="isOpen" title="Nuevo usuario de tenant" :width="700">
  <a-alert v-if="generalError && !fieldErrors" ... />

  <a-form layout="vertical" @submit.prevent="onSubmit">
    <!-- Datos básicos del usuario -->
    <a-row :gutter="12">
      <a-col :xs="24" :sm="12">
        <!-- first_name -->
      </a-col>
      <a-col :xs="24" :sm="12">
        <!-- last_name -->
      </a-col>
    </a-row>
    <!-- email, password, password_confirmation — mismos campos que UserForm -->

    <a-divider>Perfiles de acceso</a-divider>

    <!-- Lista dinámica de perfiles -->
    <div v-for="(field, index) in profileFields" :key="field.key" style="margin-bottom: 16px">
      <TenantProfileRow
        :index="index"
        :roles="tenantRoles"
        :loading-roles="isLoadingRoles"
        :field-errors="fieldErrors"
        @remove="removeProfile(index)"
        @update:role="(guid) => setProfileRole(index, guid)"
        @update:vet="(guid) => setProfileVet(index, guid)"
        @update:client="(guid) => setProfileClient(index, guid)"
      />
    </div>

    <a-button type="dashed" block @click="addProfile({ role_guid: '', vet_guid: undefined, client_guid: undefined })">
      <template #icon><PlusOutlined /></template>
      Agregar perfil
    </a-button>

    <!-- Footer -->
    <a-form-item style="margin-top: 16px; margin-bottom: 0; text-align: right">
      <a-space>
        <a-button @click="isOpen = false">Cancelar</a-button>
        <a-button type="primary" html-type="submit" :loading="isPending">
          Crear usuario
        </a-button>
      </a-space>
    </a-form-item>
  </a-form>
</BaseModal>
```

**Decisión sobre `TenantProfileRow`:** Se extrae como subcomponente `TenantProfileRow.vue` dentro de `front/src/modules/users/components/forms/TenantProfileRow.vue`. Esto permite usar `useQuery` una vez por instancia del componente, resolviendo el problema de queries dinámicas por perfil.

---

#### `front/src/modules/users/components/forms/TenantProfileRow.vue`
**Propósito:** Fila de un perfil de acceso dentro del `CreateTenantUserModal`. Contiene selector de rol + selector condicional de vet o client con búsqueda.

**Props:**
```typescript
defineProps<{
  index: number
  roles: Array<{ value: string; label: string; name: string }>
  loadingRoles: boolean
  fieldErrors: Record<string, string> | null
}>()

defineEmits<{
  remove: []
  'update:role': [guid: string]
  'update:vet': [guid: string]
  'update:client': [guid: string]
}>()
```

**Estado interno:**
```typescript
const selectedRoleGuid = ref<string>('')
const selectedRoleName = computed(() =>
  props.roles.find((r) => r.value === selectedRoleGuid.value)?.name ?? ''
)
const tenantType = computed<'vet' | 'client' | null>(() => {
  if (selectedRoleName.value.startsWith('vet')) return 'vet'
  if (selectedRoleName.value.startsWith('client')) return 'client'
  return null
})

const vetSearch = ref('')
const clientSearch = ref('')

// Query para vets (solo activo si tenantType === 'vet')
const { data: vetsData, isLoading: isLoadingVets } = useQuery({
  queryKey: computed(() => ['vets-search', props.index, vetSearch.value]),
  queryFn: ({ signal }) => listVetsApi({ search: vetSearch.value, per_page: 20 }, signal),
  enabled: computed(() => tenantType.value === 'vet'),
  staleTime: 1000 * 30,
})

// Query para clients (solo activo si tenantType === 'client')
const { data: clientsData, isLoading: isLoadingClients } = useQuery({
  queryKey: computed(() => ['clients-search', props.index, clientSearch.value]),
  queryFn: ({ signal }) => adminListClientsApi({ search: clientSearch.value, per_page: 20 }, signal),
  enabled: computed(() => tenantType.value === 'client'),
  staleTime: 1000 * 30,
})

const vetOptions = computed(() =>
  (vetsData.value?.data ?? []).map((v) => ({ value: v.guid, label: v.name }))
)
const clientOptions = computed(() =>
  (clientsData.value?.data ?? []).map((c) => ({ value: c.guid, label: c.name }))
)

function onRoleChange(guid: string) {
  selectedRoleGuid.value = guid
  emit('update:role', guid)
  emit('update:vet', '')
  emit('update:client', '')
}
```

**Template (estructura):**
```html
<a-card size="small" style="margin-bottom: 8px">
  <template #extra>
    <a-button type="text" danger :icon="h(DeleteOutlined)" @click="emit('remove')" />
  </template>

  <a-row :gutter="12">
    <a-col :xs="24" :sm="8">
      <!-- Selector de rol -->
      <a-form-item label="Rol" :validate-status="fieldErrors?.[`profiles.${index}.role_guid`] ? 'error' : ''" ...>
        <a-select
          v-model:value="selectedRoleGuid"
          :loading="loadingRoles"
          :options="roles"
          placeholder="Seleccioná un rol"
          @change="onRoleChange"
        />
      </a-form-item>
    </a-col>

    <a-col v-if="tenantType === 'vet'" :xs="24" :sm="16">
      <!-- Autocomplete de vet -->
      <a-form-item label="Veterinaria" ...>
        <a-select
          show-search
          :options="vetOptions"
          :loading="isLoadingVets"
          :filter-option="false"
          placeholder="Buscá una veterinaria..."
          @search="(val: string) => { vetSearch = val }"
          @change="(guid: string) => emit('update:vet', guid)"
        />
      </a-form-item>
    </a-col>

    <a-col v-if="tenantType === 'client'" :xs="24" :sm="16">
      <!-- Autocomplete de client -->
      <a-form-item label="Cliente" ...>
        <a-select
          show-search
          :options="clientOptions"
          :loading="isLoadingClients"
          :filter-option="false"
          placeholder="Buscá un cliente..."
          @search="(val: string) => { clientSearch = val }"
          @change="(guid: string) => emit('update:client', guid)"
        />
      </a-form-item>
    </a-col>
  </a-row>
</a-card>
```

---

## Orden de implementación

### Backend

1. Crear `back/app/Criteria/Roles/RoleTypeCriteria.php` (archivo nuevo, sin dependencias).

2. Modificar `back/app/Services/RoleService.php`: agregar `use App\Criteria\Roles\RoleTypeCriteria;` e instanciar `RoleTypeCriteria` en `list()`.

3. Modificar `back/app/Http/Controllers/V1/RoleController.php`: agregar `'type'` al `$request->only()` en `index()`.

4. Verificar manualmente que `GET /v1/roles?type=platform` retorna solo roles platform y `?type=tenant` retorna solo roles tenant. Correr los tests de roles existentes para confirmar que no se rompe nada.

5. Crear `back/app/Http/Requests/Users/CreateTenantUserRequest.php` con las reglas detalladas arriba.

6. Modificar `back/app/Services/UserService.php`:
   - Agregar los tres nuevos imports de repositorios y `DB`.
   - Actualizar el constructor para inyectar `UserProfileRepositoryInterface`, `VetRepositoryInterface` y `ClientRepositoryInterface`.
   - Agregar el método `createTenantUser()` al final de la clase.

7. Modificar `back/app/Http/Controllers/V1/UserController.php`:
   - Agregar `use App\Http\Requests\Users\CreateTenantUserRequest;`
   - Agregar el método `storeTenant()` después de `store()`.

8. Modificar `back/routes/api/users.php`: agregar la ruta `Route::post('/tenant', ...)` en la posición correcta (antes de `/{guid}`).

9. Correr los feature tests del backend: `php artisan test --filter UserController` y `php artisan test --filter RoleController`.

### Frontend

10. Modificar `front/src/modules/roles/types/role.types.ts`: agregar `type?: RoleType` a `RoleFilters` y `RoleListParams`.

11. Modificar `front/src/modules/users/types/user.types.ts`: agregar `TenantUserProfilePayload` y `TenantUserCreatePayload` al final.

12. Modificar `front/src/modules/users/validators/user.validator.ts`: agregar `tenantUserProfileSchema`, `tenantUserCreateSchema` y `TenantUserCreateForm` al final.

13. Modificar `front/src/modules/users/api/users.api.ts`: agregar `createTenantUserApi`.

14. Modificar `front/src/modules/users/components/forms/UserForm.vue`: agregar prop `roleType`, computar `rolesFilters` y pasarlos a `useRoles`.

15. Modificar `front/src/modules/users/components/modals/CreateUserModal.vue`: agregar `role-type="platform"` al `UserForm`.

16. Modificar `front/src/modules/users/stores/users.store.ts`: agregar `'createTenant'` a `ModalType`.

17. Crear `front/src/modules/users/composables/useCreateTenantUser.ts`.

18. Crear `front/src/modules/users/components/forms/TenantProfileRow.vue`.

19. Crear `front/src/modules/users/components/modals/CreateTenantUserModal.vue`.

20. Modificar `front/src/modules/users/pages/UsersPage.vue`:
    - Importar `DownOutlined`, `CreateTenantUserModal`.
    - Agregar computed `showCreateTenant`.
    - Reemplazar `BaseButton` por `ADropdown`.
    - Agregar `<CreateTenantUserModal v-model="showCreateTenant" />` al template.

21. Smoke test manual:
    - Verificar que el dropdown aparece con dos opciones.
    - Verificar que "Usuario de plataforma" abre el modal existente mostrando solo roles platform.
    - Verificar que "Usuario de tenant" abre el nuevo modal.
    - Crear un usuario de tenant con un perfil vet, verificar que aparece en el listado.
    - Crear un usuario de tenant con dos perfiles (vet + client-owner), verificar en DB que existen dos `user_profiles`.
    - Intentar crear sin perfiles → ver error de validación.
    - Intentar crear con rol vet sin seleccionar vet → ver error de validación del backend.

---

## Riesgos y consideraciones

**R-01 — `useFieldArray` de vee-validate y sincronización con el estado de perfiles**
`useFieldArray` de vee-validate maneja el array internamente. Cuando `TenantProfileRow` emite `update:role`, `update:vet`, `update:client`, el padre debe actualizar los valores del array en el form. El patrón correcto es usar `fields[index].value.role_guid = guid` a través del método `setValue` de vee-validate para el path `profiles.${index}.role_guid`. El dev debe verificar el patrón exacto con la versión de `vee-validate` instalada en el proyecto antes de implementar. Una implementación incorrecta puede causar que los valores no lleguen al submit.

**R-02 — Queries de vets/clients en `TenantProfileRow` con `enabled: false` al inicio**
Las queries de vets y clients están `enabled: false` hasta que se seleccione un rol y se escriba algo en la búsqueda. Esto es correcto. El riesgo es que si el usuario selecciona un rol y no escribe nada en el search, no ve opciones. Una UX alternativa sería cargar las primeras 20 opciones cuando el tenant-type se determine (sin `enabled` condicional, siempre activas). Decisión delegada al dev según UX deseada — el plan contempla ambas opciones; la variante con `enabled` condicional es la descrita.

**R-03 — Rol que no empieza con 'vet' ni 'client' en el service**
Si en el futuro se agrega un rol tenant que no sigue el prefijo `vet*`/`client*` (ej: `admin-vet`), la lógica `str_starts_with` en el service fallará con una excepción genérica. La lógica de determinar el tipo de tenant debería estar en el modelo `Role` como método `getTenantEntityType(): ?string`. Marcado como deuda técnica.

**R-04 — Multi-tenant: el endpoint `POST /v1/users/tenant` no tiene scope de tenant**
Este endpoint es de administración de plataforma (permite crear usuarios y asignarlos a cualquier vet/client). Si en el futuro el sistema soporta que un admin de vet cree usuarios para su propio tenant, este endpoint necesitará scope. Por ahora, el middleware `auth:sanctum` es suficiente para el contexto de plataforma. No es una violación de multi-tenant porque el caller es un usuario de plataforma, no un tenant.

**R-05 — `UserService` ahora tiene 5 dependencias inyectadas**
El constructor del `UserService` pasa de 2 a 5 parámetros. Esto es aceptable pero es una señal de que el service está creciendo. Si en el futuro se agregan más operaciones de tenant, conviene extraer la lógica de tenant a un servicio separado (ej: `TenantUserService`). Marcado como deuda técnica a considerar.

**R-06 — El `EditUserModal` usa `UserForm` sin `roleType` prop**
Al editar un usuario existente de plataforma, el `UserForm` sin `roleType` prop cargará todos los roles (platform + tenant). Esto es un comportamiento inconsistente: no deberían poder asignarse roles tenant via el formulario de edición de usuarios de plataforma. Esta inconsistencia queda pendiente para una iteración posterior; el fix es pasar `roleType="platform"` al `UserForm` del `EditUserModal`, pero requiere verificar si hay usuarios que ya tienen roles tenant asignados via Spatie (edge case histórico).

**R-07 — `RoleListParams` y `RoleFilters` son tipos separados con el mismo shape**
El archivo `role.types.ts` tiene `RoleListParams` y `RoleFilters` como interfaces separadas con el mismo contenido. En esta modificación se agrega `type` a ambas. Si en el futuro divergen, puede haber bugs. Considerar consolidarlas en una sola type.

**R-08 — Impacto en multi-país**
El campo `authenticatable_type` usa los valores `'vet'` y `'client'` que están hardcodeados en el `morphMap` de `AppServiceProvider`. El plan no introduce hardcoding de lógica argentina; la diferenciación vet/client es válida en todos los países soportados (MX, PE, CO, CL, BR). Sin riesgo de arquitectura multi-país.

---

## Pendientes / fuera de alcance

- **Permisos Spatie diferenciados**: agregar `users.tenant.create` como permiso separado de `users.create` para poder controlar quién puede crear usuarios de tenant vs. plataforma. Requiere ticket separado.
- **Edición de usuario de tenant**: el `EditUserModal` actual no muestra ni edita perfiles (`UserProfile`). Si se quiere editar los perfiles de un usuario de tenant, requiere un `EditTenantUserModal` con lógica adicional.
- **Rol como prefijo en el Service**: refactorizar la lógica `str_starts_with` a un método `getTenantEntityType()` en el modelo `Role` para que sea reutilizable y no hardcodee strings en el service.
- **Feedback de errores por índice de perfil en el frontend**: el backend retorna mensajes como `"Perfil #0: ..."`. El frontend actualmente mostraría esto como `generalError`. Si se desea mapearlos a errores por campo en el formulario, se necesita un parser especial en `parseApiError` o una convención de respuesta diferente.
- **`useAdminClients` composable**: si en el futuro `adminListClientsApi` se usa en más lugares del módulo de usuarios, extraer el composable.
