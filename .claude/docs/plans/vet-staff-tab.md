# Plan técnico: Tab "Staff" en el detalle de una Vet (Panel Admin)

## Input procesado

Brief informal del usuario (texto en chat). Feature: agregar solapa "Staff" en `VetDetailPage.vue` con CRUD completo de staff de veterinaria desde el panel superadmin.

---

## Resumen ejecutivo

Se agrega gestión de staff de una veterinaria al panel superadmin. En el backend, se añaden 4 métodos al `AdminVetController` existente (`staffIndex`, `staffStore`, `staffChangeRole`, `staffDestroy`) reutilizando `UserProfileService` sin modificarlo. Se crean 4 permisos nuevos (`vets.staff.*`) en `PermissionSeeder` y se los asigna al rol `super-admin` en `RoleSeeder`. En el frontend, se crea el componente `VetStaffSection.vue` siguiendo el patrón de `VetClientsSection.vue`, con 4 composables dedicados y las funciones API correspondientes en `vets.api.ts`. Se añade el tab "Staff" a `VetDetailPage.vue`. No hay nuevas migraciones (la tabla `user_profiles` ya existe). El filtrado de roles a los de scope `vet` se hace en el frontend filtrando la respuesta de `GET /v1/roles`.

---

## Decisiones tomadas

**DEC-01 — Validación de roles en los nuevos FormRequests: reutilizar los existentes vs. crear nuevos**
  Decisión: Crear dos nuevos FormRequests en el mismo namespace `App\Http\Requests\Members\` — `AdminAssignStaffRequest` y `AdminChangeStaffRoleRequest` — que restringen el `role_guid` exclusivamente a roles de scope `vet` (`vet`, `vet-assistant`, `vet-administrative`), en lugar de reutilizar los existentes `AssignMemberRequest` y `ChangeRoleMemberRequest`.
  Justificación: Los requests existentes validan contra `UserProfileService::TENANT_ROLES`, que incluye roles de cliente. En el contexto admin de staff de vet, no tiene sentido permitir asignar `client-owner` o `client-manager`. Crear requests propios con un conjunto de roles más restrictivo es correcto semánticamente y evita que una modificación futura de `TENANT_ROLES` afecte el contexto admin.
  Alternativa descartada: Reutilizar `AssignMemberRequest` y `ChangeRoleMemberRequest` directamente — descartado porque permitirían asignar roles de cliente a staff de vet, lo cual es un error de dominio.

**DEC-02 — Ubicación de los 4 métodos nuevos: en AdminVetController vs. controller separado**
  Decisión: Agregar los 4 métodos (`staffIndex`, `staffStore`, `staffChangeRole`, `staffDestroy`) directamente en `AdminVetController`, inyectando `UserProfileService` como segunda dependencia en el constructor.
  Justificación: El brief ya tomó esta decisión y es técnicamente correcta: el controller tiene solo 8 métodos más los 4 nuevos = 12, sigue siendo manejable. Los endpoints viven bajo `/v1/admin/vets/{guid}/staff`, cohesionados con la vet. Un controller separado `AdminVetStaffController` crearía un archivo de 40 líneas que no justifica la indirección.
  Alternativa descartada: `AdminVetStaffController` separado — descartado, no aporta valor en este tamaño.

**DEC-03 — Filtrado de roles en el select de "agregar staff": query param `?scope=vet` vs. filtrado en frontend**
  Decisión: Filtrar en el frontend. El componente `VetStaffSection.vue` carga `GET /v1/roles` sin parámetros adicionales y filtra el resultado para mostrar solo roles con `name` en `['vet', 'vet-assistant', 'vet-administrative']`.
  Justificación: El endpoint `GET /v1/roles` es paginado (`RoleController.index` usa `paginate`). Agregar `?scope=vet` implicaría modificar `RoleService` y `RoleController` para filtrar por scope. El conjunto total de roles del sistema son < 10 registros — cargarlos todos y filtrar en memoria es negligible. La consistencia con DEC-07 del plan anterior (`frontend-modules-vets-staff-clients-plan.md`) también aplica aquí.
  Alternativa descartada: Query param `?scope=vet` — descartado por introducir cambios de backend innecesarios.

**DEC-04 — Carga de roles para el select: query propia en el componente vs. composable compartido**
  Decisión: Crear un composable `useVetRoles.ts` en `front/src/modules/vets/composables/` que encapsula la llamada a `GET /v1/roles` con filtrado a roles de scope `vet`. Así el componente no accede a la API directamente.
  Justificación: Patrón uniforme del proyecto — los componentes usan composables, no llaman a funciones API directamente. Además, si en el futuro se agrega el select en otro lugar, el composable es reutilizable.
  Alternativa descartada: Llamar a `listRolesApi()` directamente desde el componente — descartado por violar la convención de capas del proyecto.

**DEC-05 — Carga de roles: endpoint de roles existente vs. nuevo endpoint dedicado**
  Decisión: Usar el endpoint existente `GET /v1/roles` ya que devuelve todos los roles del sistema y el filtrado en frontend es suficiente. No se crea ningún endpoint nuevo de roles.
  Justificación: Ver DEC-03.

**DEC-06 — Constante de roles de vet para el filtrado: inline vs. constante exportada**
  Decisión: Definir la constante `VET_STAFF_ROLES = ['vet', 'vet-assistant', 'vet-administrative']` en `front/src/modules/vets/types/vet.types.ts` como un `as const` array exportado, y usarla tanto en `useVetRoles.ts` como en `AdminAssignStaffRequest.php` (donde ya existe la referencia en backend como array literal).
  Justificación: Centralizar la lista de roles válidos para staff en un lugar del módulo frontend facilita su mantenimiento. Es coherente con el backend donde estos roles están enumerados en el FormRequest.

**DEC-07 — Manejo de carga de roles en el formulario: useQuery con staleTime**
  Decisión: `useVetRoles` usa `useQuery` con `staleTime: 1000 * 60 * 5` (5 minutos). Los roles del sistema cambian raramente — no hay necesidad de revalidar en cada apertura del drawer.
  Justificación: Patrón del proyecto para listas de referencia (países, tipos de documento).

**DEC-08 — UI del formulario de alta de staff: BaseDrawer vs. BaseModal**
  Decisión: Usar `BaseDrawer` para el formulario de alta y edición de rol, igual que el patrón establecido en el módulo de staff tenant (`VetStaffSection.vue` existente del plan anterior).
  Justificación: El patrón `VetClientsSection.vue` usa `LinkClientModal`, pero ese modal tiene lógica de búsqueda compleja. Para un formulario simple de 2 campos (usuario + rol), un drawer o modal es indistinto. Se elige `BaseDrawer` por consistencia con el módulo tenant.
  Alternativa descartada: `BaseModal` — se puede usar igualmente si `BaseDrawer` no está disponible o si el dev lo prefiere; el plan acepta ambos.

---

## Cambios en BACKEND

### Archivos a crear

#### `back/app/Http/Requests/Members/AdminAssignStaffRequest.php`
**Propósito:** Validar el payload de alta de staff de vet desde el panel admin (restringe roles a scope `vet`).
**Firma principal:**
```php
namespace App\Http\Requests\Members;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminAssignStaffRequest extends FormRequest
{
    public const VET_STAFF_ROLES = ['vet', 'vet-assistant', 'vet-administrative'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_guid' => ['required', 'string', 'exists:users,guid'],
            'role_guid' => [
                'required',
                'string',
                Rule::exists('roles', 'guid')->where(function ($query) {
                    $query->whereIn('name', self::VET_STAFF_ROLES);
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
            'role_guid.exists'   => 'El rol seleccionado no es válido para staff de veterinaria.',
        ];
    }
}
```
**Dependencias inyectadas:** ninguna.

#### `back/app/Http/Requests/Members/AdminChangeStaffRoleRequest.php`
**Propósito:** Validar el payload de cambio de rol de un miembro de staff desde el panel admin.
**Firma principal:**
```php
namespace App\Http\Requests\Members;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminChangeStaffRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role_guid' => [
                'required',
                'string',
                Rule::exists('roles', 'guid')->where(function ($query) {
                    $query->whereIn('name', AdminAssignStaffRequest::VET_STAFF_ROLES);
                }),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'role_guid.required' => 'El rol es obligatorio.',
            'role_guid.exists'   => 'El rol seleccionado no es válido para staff de veterinaria.',
        ];
    }
}
```
**Dependencias inyectadas:** ninguna.

### Archivos a modificar

#### `back/app/Http/Controllers/V1/AdminVetController.php`
**Cambio:** Agregar inyección de `UserProfileService` en el constructor y 4 métodos nuevos: `staffIndex`, `staffStore`, `staffChangeRole`, `staffDestroy`.

**Antes (constructor):**
```php
public function __construct(
    private VetService    $vetService,
    private ClientService $clientService,
) {}
```

**Después (constructor):**
```php
public function __construct(
    private VetService          $vetService,
    private ClientService       $clientService,
    private UserProfileService  $userProfileService,
) {}
```

**Métodos nuevos a agregar al final de la clase** (antes del cierre `}`):

```php
public function staffIndex(string $guid): JsonResponse
{
    try {
        $vet = $this->vetService->findByGuid($guid);
        if (!$vet) {
            return $this->makeNotFound('Veterinaria no encontrada.');
        }

        $members = $this->userProfileService->list($vet);
        // Filtrar solo perfiles con roles de scope vet
        $staffRoles = AdminAssignStaffRequest::VET_STAFF_ROLES;
        $staff = $members->filter(fn ($profile) => in_array($profile->role->name, $staffRoles));

        return $this->makeSuccess(UserProfileResource::collection($staff));
    } catch (\Exception $e) {
        return $this->makeFromException($e);
    }
}

public function staffStore(AdminAssignStaffRequest $request, string $guid): JsonResponse
{
    try {
        $vet = $this->vetService->findByGuid($guid);
        if (!$vet) {
            return $this->makeNotFound('Veterinaria no encontrada.');
        }

        $data = $request->validated();

        $user = $this->userProfileService->resolveUser($data['user_guid']);
        if (!$user) {
            return $this->makeNotFound('Usuario no encontrado.');
        }

        $role = $this->userProfileService->resolveRole($data['role_guid']);
        if (!$role) {
            return $this->makeNotFound('Rol no encontrado.');
        }

        $profile = $this->userProfileService->addMember($vet, $user, $role);

        return $this->makeSuccess(new UserProfileResource($profile), 'Miembro agregado correctamente.', 201);
    } catch (\RuntimeException $e) {
        return $this->makeError(null, $e->getMessage(), 422);
    } catch (\Exception $e) {
        return $this->makeFromException($e);
    }
}

public function staffChangeRole(AdminChangeStaffRoleRequest $request, string $guid, string $profileGuid): JsonResponse
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

        $role = $this->userProfileService->resolveRole($request->validated()['role_guid']);
        if (!$role) {
            return $this->makeNotFound('Rol no encontrado.');
        }

        $profile = $this->userProfileService->changeRole($profile, $role);

        return $this->makeSuccess(new UserProfileResource($profile), 'Rol actualizado correctamente.');
    } catch (\Exception $e) {
        return $this->makeFromException($e);
    }
}

public function staffDestroy(string $guid, string $profileGuid): JsonResponse
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

        $this->userProfileService->removeMember($profile);

        return $this->makeSuccess(null, 'Miembro eliminado correctamente.');
    } catch (\Exception $e) {
        return $this->makeFromException($e);
    }
}
```

**Imports adicionales a agregar en el bloque `use` del archivo:**
```php
use App\Http\Requests\Members\AdminAssignStaffRequest;
use App\Http\Requests\Members\AdminChangeStaffRoleRequest;
use App\Http\Resources\V1\UserProfileResource;
use App\Services\UserProfileService;
```

**Nota sobre el `staffIndex`:** `$this->userProfileService->list($vet)` carga los perfiles con eager loading de `role`. Verificar que el repositorio cargue la relación `role` (ver `UserProfileRepository::listForVet`). Si no la carga, agregar `$members->load('role')` antes del filtro. El filtro en PHP es necesario porque el método `list()` devuelve todos los perfiles de la vet (incluidos los de clientes con `authenticatable_type = 'client'`). Nota: en realidad `listForVet` filtra por `authenticatable_type = 'vet'`, pero puede incluir roles `client-owner` si alguno quedó mal cargado. El filtro por nombre de rol es la garantía correcta.

#### `back/routes/api/vets.php`
**Cambio:** Agregar 4 rutas nuevas al grupo admin.

**Antes (cierre del grupo admin):**
```php
Route::get('/{guid}/clients', [AdminVetController::class, 'clients'])->middleware('can:clients.read');
```

**Después:**
```php
Route::get('/{guid}/clients', [AdminVetController::class, 'clients'])->middleware('can:clients.read');

// Staff de vet (panel admin)
Route::get('/{guid}/staff',                          [AdminVetController::class, 'staffIndex'])      ->middleware('can:vets.staff.read');
Route::post('/{guid}/staff',                         [AdminVetController::class, 'staffStore'])      ->middleware('can:vets.staff.create');
Route::patch('/{guid}/staff/{profileGuid}/role',     [AdminVetController::class, 'staffChangeRole']) ->middleware('can:vets.staff.update');
Route::delete('/{guid}/staff/{profileGuid}',         [AdminVetController::class, 'staffDestroy'])    ->middleware('can:vets.staff.delete');
```

#### `back/database/seeders/PermissionSeeder.php`
**Cambio:** Agregar los 4 permisos nuevos al array `$permissions`.

**Antes (fragmento):**
```php
'vets.validate',
'clients.read',
```

**Después:**
```php
'vets.validate',
'vets.staff.read',
'vets.staff.create',
'vets.staff.update',
'vets.staff.delete',
'clients.read',
```

#### `back/database/seeders/RoleSeeder.php`
**Cambio:** El rol `super-admin` ya usa `syncPermissions(Permission::all())` en la línea 29, lo que significa que al correr el seeder después de agregar los 4 permisos al `PermissionSeeder`, el `super-admin` los recibirá automáticamente. **No requiere modificación.** Solo asegurarse de que `PermissionSeeder` corra antes de `RoleSeeder` en `DatabaseSeeder`.

**Verificar** que en `DatabaseSeeder` el orden sea `PermissionSeeder` → `RoleSeeder`. Si no es así, ajustar el orden.

### Migrations

No hay nuevas migraciones. La tabla `user_profiles` ya existe con las columnas necesarias (`guid`, `user_id`, `authenticatable_type`, `authenticatable_id`, `role_id`).

### Rutas API

| Método | Path | Controller@Action | Middleware |
|--------|------|-------------------|-----------|
| GET | `/v1/admin/vets/{guid}/staff` | `AdminVetController@staffIndex` | `auth:sanctum`, `can:vets.staff.read` |
| POST | `/v1/admin/vets/{guid}/staff` | `AdminVetController@staffStore` | `auth:sanctum`, `can:vets.staff.create` |
| PATCH | `/v1/admin/vets/{guid}/staff/{profileGuid}/role` | `AdminVetController@staffChangeRole` | `auth:sanctum`, `can:vets.staff.update` |
| DELETE | `/v1/admin/vets/{guid}/staff/{profileGuid}` | `AdminVetController@staffDestroy` | `auth:sanctum`, `can:vets.staff.delete` |

### Permisos Spatie

| Nombre | Seeder | Roles que lo reciben |
|--------|--------|----------------------|
| `vets.staff.read` | `PermissionSeeder` | `super-admin` (via `syncPermissions(Permission::all())`) |
| `vets.staff.create` | `PermissionSeeder` | `super-admin` |
| `vets.staff.update` | `PermissionSeeder` | `super-admin` |
| `vets.staff.delete` | `PermissionSeeder` | `super-admin` |

Guard: `'web'` (como el resto del sistema).

### Contrato de los endpoints

**GET `/v1/admin/vets/{guid}/staff`**

Response 200:
```json
{
  "success": true,
  "data": [
    {
      "guid": "uuid",
      "user": {
        "guid": "uuid",
        "name": "Juan Pérez",
        "first_name": "Juan",
        "last_name": "Pérez",
        "email": "juan@example.com"
      },
      "role": {
        "guid": "uuid",
        "name": "vet"
      },
      "contacts": [],
      "created_at": "2024-01-15T10:00:00.000Z"
    }
  ]
}
```

Errores posibles:
| HTTP | Cuándo |
|------|--------|
| 404 | Vet no encontrada por guid |

---

**POST `/v1/admin/vets/{guid}/staff`**

Request:
```json
{
  "user_guid": "uuid del usuario existente en la tabla users",
  "role_guid": "uuid de un rol con name en ['vet', 'vet-assistant', 'vet-administrative']"
}
```

Response 201:
```json
{
  "success": true,
  "data": {
    "guid": "uuid",
    "user": { "guid": "...", "name": "...", "email": "..." },
    "role": { "guid": "...", "name": "vet" },
    "contacts": [],
    "created_at": "..."
  },
  "message": "Miembro agregado correctamente."
}
```

Errores posibles:
| HTTP | Cuándo |
|------|--------|
| 404 | Vet no encontrada |
| 404 | Usuario no encontrado por user_guid |
| 422 | Validación: campos requeridos / role_guid no válido para scope vet |
| 422 | El usuario ya es miembro de esta veterinaria (`RuntimeException` del servicio) |

---

**PATCH `/v1/admin/vets/{guid}/staff/{profileGuid}/role`**

Request:
```json
{
  "role_guid": "uuid de rol válido para scope vet"
}
```

Response 200:
```json
{
  "success": true,
  "data": { "guid": "...", "user": { ... }, "role": { "guid": "...", "name": "vet-assistant" }, ... },
  "message": "Rol actualizado correctamente."
}
```

Errores posibles:
| HTTP | Cuándo |
|------|--------|
| 404 | Vet no encontrada |
| 404 | UserProfile no encontrado o no pertenece a esta vet |
| 422 | role_guid inválido |

---

**DELETE `/v1/admin/vets/{guid}/staff/{profileGuid}`**

Response 200:
```json
{
  "success": true,
  "data": null,
  "message": "Miembro eliminado correctamente."
}
```

Errores posibles:
| HTTP | Cuándo |
|------|--------|
| 404 | Vet no encontrada |
| 404 | UserProfile no encontrado o no pertenece a esta vet |

---

### Tests a generar (qué cubrir, no el código)

**Feature tests — `AdminVetStaffTest.php`:**

`staffIndex`:
- Happy path: retorna lista solo con perfiles de scope vet (no clientes).
- 401 si no autenticado.
- 403 si usuario no tiene permiso `vets.staff.read`.
- 404 si guid de vet no existe.

`staffStore`:
- Happy path: crea UserProfile correctamente, retorna 201 con resource.
- 422 si `user_guid` no existe en `users`.
- 422 si `role_guid` es de scope cliente (`client-owner`), no de vet.
- 422 si el usuario ya es miembro de la vet.
- 403 si no tiene permiso `vets.staff.create`.
- 404 si guid de vet no existe.

`staffChangeRole`:
- Happy path: actualiza rol, retorna resource actualizado.
- 404 si `profileGuid` no pertenece a la vet (cross-tenant check).
- 422 si `role_guid` es de scope cliente.
- 403 si no tiene permiso `vets.staff.update`.

`staffDestroy`:
- Happy path: elimina el perfil, la vet sigue existiendo, el user sigue existiendo.
- 404 si `profileGuid` no pertenece a la vet (cross-tenant check).
- 403 si no tiene permiso `vets.staff.delete`.

---

## Cambios en FRONTEND

### Archivos a crear

#### `front/src/modules/vets/api/vet-staff.api.ts`
**Propósito:** Funciones de acceso a los 4 endpoints de staff de vet en el panel admin.
**Contenido:**
```typescript
import { http } from '@/core/api/http'
import type { VetStaffItem, AssignStaffPayload, ChangeStaffRolePayload } from '../types/vet.types'

export async function adminListVetStaffApi(vetGuid: string): Promise<VetStaffItem[]> {
  const res = await http.get<VetStaffItem[]>(`/v1/admin/vets/${vetGuid}/staff`)
  return res.data
}

export async function adminAssignStaffApi(vetGuid: string, payload: AssignStaffPayload): Promise<VetStaffItem> {
  const res = await http.post<VetStaffItem>(`/v1/admin/vets/${vetGuid}/staff`, payload)
  return res.data
}

export async function adminChangeStaffRoleApi(
  vetGuid: string,
  profileGuid: string,
  payload: ChangeStaffRolePayload,
): Promise<VetStaffItem> {
  const res = await http.patch<VetStaffItem>(
    `/v1/admin/vets/${vetGuid}/staff/${profileGuid}/role`,
    payload,
  )
  return res.data
}

export async function adminRemoveStaffApi(vetGuid: string, profileGuid: string): Promise<void> {
  await http.delete(`/v1/admin/vets/${vetGuid}/staff/${profileGuid}`)
}
```

#### `front/src/modules/vets/composables/useAdminVetStaff.ts`
**Propósito:** Query para listar el staff de una vet (panel admin).
```typescript
import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import type { Ref } from 'vue'
import { adminListVetStaffApi } from '../api/vet-staff.api'

export function useAdminVetStaff(vetGuid: Ref<string> | string) {
  const guidValue = computed(() => toValue(vetGuid))

  return useQuery({
    queryKey: ['admin-vet-staff', guidValue],
    queryFn: () => adminListVetStaffApi(guidValue.value),
    enabled: computed(() => Boolean(guidValue.value)),
    staleTime: 1000 * 30,
  })
}
```

#### `front/src/modules/vets/composables/useAdminAssignStaff.ts`
**Propósito:** Mutation para agregar un miembro al staff de una vet (panel admin).
```typescript
import { ref } from 'vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { adminAssignStaffApi } from '../api/vet-staff.api'
import { useNotification } from '@/core/composables/useNotification'
import { parseApiError } from '@/core/composables/parseApiError'
import type { AssignStaffPayload } from '../types/vet.types'

export function useAdminAssignStaff(vetGuid: string) {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const fieldErrors  = ref<Record<string, string> | null>(null)
  const generalError = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: (payload: AssignStaffPayload) => adminAssignStaffApi(vetGuid, payload),
    onMutate: () => {
      fieldErrors.value  = null
      generalError.value = null
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-vet-staff', vetGuid] })
      success('Miembro agregado correctamente')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      fieldErrors.value  = apiError.fieldErrors
      generalError.value = apiError.message ?? 'Error al agregar el miembro.'
      if (apiError.message) error(apiError.message)
    },
  })

  function resetErrors() {
    fieldErrors.value  = null
    generalError.value = null
    mutation.reset()
  }

  return { ...mutation, fieldErrors, generalError, resetErrors }
}
```

#### `front/src/modules/vets/composables/useAdminChangeStaffRole.ts`
**Propósito:** Mutation para cambiar el rol de un miembro del staff (panel admin).
```typescript
import { ref } from 'vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { adminChangeStaffRoleApi } from '../api/vet-staff.api'
import { useNotification } from '@/core/composables/useNotification'
import { parseApiError } from '@/core/composables/parseApiError'
import type { ChangeStaffRolePayload } from '../types/vet.types'

export function useAdminChangeStaffRole(vetGuid: string) {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const fieldErrors  = ref<Record<string, string> | null>(null)
  const generalError = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: ({ profileGuid, payload }: { profileGuid: string; payload: ChangeStaffRolePayload }) =>
      adminChangeStaffRoleApi(vetGuid, profileGuid, payload),
    onMutate: () => {
      fieldErrors.value  = null
      generalError.value = null
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-vet-staff', vetGuid] })
      success('Rol actualizado correctamente')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      fieldErrors.value  = apiError.fieldErrors
      generalError.value = apiError.message ?? 'Error al actualizar el rol.'
      if (apiError.message) error(apiError.message)
    },
  })

  function resetErrors() {
    fieldErrors.value  = null
    generalError.value = null
    mutation.reset()
  }

  return { ...mutation, fieldErrors, generalError, resetErrors }
}
```

#### `front/src/modules/vets/composables/useAdminRemoveStaff.ts`
**Propósito:** Mutation para eliminar un miembro del staff con confirmación.
```typescript
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { adminRemoveStaffApi } from '../api/vet-staff.api'
import { useNotification } from '@/core/composables/useNotification'
import { useConfirm } from '@/core/composables/useConfirm'
import { parseApiError } from '@/core/composables/parseApiError'
import type { VetStaffItem } from '../types/vet.types'

export function useAdminRemoveStaff(vetGuid: string) {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const confirm = useConfirm()

  const mutation = useMutation({
    mutationFn: (profileGuid: string) => adminRemoveStaffApi(vetGuid, profileGuid),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-vet-staff', vetGuid] })
      success('Miembro eliminado correctamente')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      error(apiError.message ?? 'Error al eliminar el miembro.')
    },
  })

  async function removeStaff(member: VetStaffItem): Promise<void> {
    await confirm.confirm({
      title:        'Eliminar miembro',
      message:      `¿Estás seguro de que querés eliminar a "${member.user.name}" del staff de esta veterinaria?`,
      confirmLabel: 'Eliminar',
      danger:       true,
      onConfirm:    () => mutation.mutateAsync(member.guid),
    })
  }

  return { ...mutation, removeStaff }
}
```

#### `front/src/modules/vets/composables/useVetRoles.ts`
**Propósito:** Query para obtener los roles de scope vet disponibles para el select del formulario.
```typescript
import { useQuery } from '@tanstack/vue-query'
import { computed } from 'vue'
import { http } from '@/core/api/http'
import { VET_STAFF_ROLES } from '../types/vet.types'

// Llamada directa al endpoint de roles con parámetros mínimos
async function listAllRolesApi() {
  const res = await http.get('/v1/roles', { params: { per_page: 50 } })
  return res.data
}

export function useVetRoles() {
  const query = useQuery({
    queryKey: ['roles-vet-scope'],
    queryFn: listAllRolesApi,
    staleTime: 1000 * 60 * 5,
  })

  const vetRoles = computed(() => {
    const items: Array<{ guid: string; name: string }> = query.data.value?.data ?? []
    return items.filter(r => VET_STAFF_ROLES.includes(r.name as any))
  })

  return { ...query, vetRoles }
}
```

**Nota:** `GET /v1/roles` devuelve paginación. Con `per_page: 50` se asegura traer todos los roles del sistema (que son menos de 15). El filtrado por `VET_STAFF_ROLES` reduce el resultado a los 3 roles de vet.

#### `front/src/modules/vets/components/VetStaffSection.vue`
**Propósito:** Componente principal del tab "Staff" en `VetDetailPage`. Lista el staff, abre drawer para alta y cambio de rol, y permite eliminar con confirmación.

```vue
<script setup lang="ts">
import { ref, reactive } from 'vue'
import { PlusOutlined, EditOutlined, DeleteOutlined } from '@ant-design/icons-vue'
import { useAdminVetStaff }      from '../composables/useAdminVetStaff'
import { useAdminAssignStaff }   from '../composables/useAdminAssignStaff'
import { useAdminChangeStaffRole } from '../composables/useAdminChangeStaffRole'
import { useAdminRemoveStaff }   from '../composables/useAdminRemoveStaff'
import { useVetRoles }           from '../composables/useVetRoles'
import type { VetStaffItem, AssignStaffPayload, ChangeStaffRolePayload } from '../types/vet.types'

const props = defineProps<{ vetGuid: string }>()

// --- Queries y mutations ---
const { data: staff, isLoading }    = useAdminVetStaff(props.vetGuid)
const assignMutation                = useAdminAssignStaff(props.vetGuid)
const changeMutation                = useAdminChangeStaffRole(props.vetGuid)
const { removeStaff, isPending: isRemoving } = useAdminRemoveStaff(props.vetGuid)
const { vetRoles, isLoading: isLoadingRoles } = useVetRoles()

// --- Estado UI ---
const isAssignDrawerOpen = ref(false)
const isChangeDrawerOpen = ref(false)
const selectedMember     = ref<VetStaffItem | null>(null)

const assignForm = reactive<AssignStaffPayload>({ user_guid: '', role_guid: '' })
const changeForm = reactive<ChangeStaffRolePayload>({ role_guid: '' })

// --- Handlers ---
function openAssignDrawer() {
  assignForm.user_guid = ''
  assignForm.role_guid = ''
  assignMutation.resetErrors()
  isAssignDrawerOpen.value = true
}

function openChangeDrawer(member: VetStaffItem) {
  selectedMember.value = member
  changeForm.role_guid = member.role.guid
  changeMutation.resetErrors()
  isChangeDrawerOpen.value = true
}

async function handleAssign() {
  await assignMutation.mutateAsync({ ...assignForm })
  if (!assignMutation.isError.value) {
    isAssignDrawerOpen.value = false
  }
}

async function handleChangeRole() {
  if (!selectedMember.value) return
  await changeMutation.mutateAsync({
    profileGuid: selectedMember.value.guid,
    payload: { ...changeForm },
  })
  if (!changeMutation.isError.value) {
    isChangeDrawerOpen.value = false
  }
}

const columns = [
  { title: 'Nombre',  key: 'name' },
  { title: 'Email',   key: 'email' },
  { title: 'Rol',     key: 'role' },
  { title: 'Alta',    key: 'created_at' },
  { title: 'Acciones', key: 'actions', width: 110 },
]
</script>

<template>
  <div class="vss-root">
    <div class="vss-toolbar">
      <PermissionGuard permission="vets.staff.create">
        <BaseButton @click="openAssignDrawer">
          <template #icon><PlusOutlined /></template>
          Agregar miembro
        </BaseButton>
      </PermissionGuard>
    </div>

    <EmptyState
      v-if="!isLoading && !staff?.length"
      message="Esta veterinaria no tiene miembros de staff."
    />

    <BaseDataTable
      v-else
      :columns="columns"
      :data-source="staff ?? []"
      :loading="isLoading || isRemoving"
      row-key="guid"
      :scroll="{ x: 600 }"
      :pagination="false"
    >
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'name'">
          <span class="vss-name">{{ record.user.name }}</span>
        </template>

        <template v-else-if="column.key === 'email'">
          <span class="vss-email">{{ record.user.email }}</span>
        </template>

        <template v-else-if="column.key === 'role'">
          <a-tag>{{ record.role.name }}</a-tag>
        </template>

        <template v-else-if="column.key === 'created_at'">
          {{ formatDate(record.created_at) }}
        </template>

        <template v-else-if="column.key === 'actions'">
          <BaseTableActions>
            <PermissionGuard permission="vets.staff.update">
              <BaseButton
                variant="row-action"
                size="small"
                tooltip="Cambiar rol"
                @click="openChangeDrawer(record)"
              >
                <template #icon><EditOutlined /></template>
              </BaseButton>
            </PermissionGuard>

            <PermissionGuard permission="vets.staff.delete">
              <BaseButton
                variant="row-action"
                size="small"
                tooltip="Eliminar"
                danger
                @click="removeStaff(record)"
              >
                <template #icon><DeleteOutlined /></template>
              </BaseButton>
            </PermissionGuard>
          </BaseTableActions>
        </template>
      </template>
    </BaseDataTable>

    <!-- Drawer: Agregar miembro -->
    <BaseDrawer
      v-if="isAssignDrawerOpen"
      title="Agregar miembro al staff"
      :open="isAssignDrawerOpen"
      @close="isAssignDrawerOpen = false"
    >
      <div class="vss-form">
        <div v-if="assignMutation.generalError.value" class="vss-error">
          {{ assignMutation.generalError.value }}
        </div>

        <BaseInput
          v-model="assignForm.user_guid"
          label="GUID del usuario"
          placeholder="UUID del usuario a agregar"
          :error="assignMutation.fieldErrors.value?.user_guid"
        />

        <a-select
          v-model:value="assignForm.role_guid"
          placeholder="Seleccionar rol"
          :loading="isLoadingRoles"
          style="width: 100%"
        >
          <a-select-option
            v-for="role in vetRoles"
            :key="role.guid"
            :value="role.guid"
          >
            {{ role.name }}
          </a-select-option>
        </a-select>
        <div v-if="assignMutation.fieldErrors.value?.role_guid" class="vss-field-error">
          {{ assignMutation.fieldErrors.value.role_guid }}
        </div>

        <div class="vss-form-actions">
          <BaseButton variant="secondary" @click="isAssignDrawerOpen = false">Cancelar</BaseButton>
          <BaseButton :loading="assignMutation.isPending.value" @click="handleAssign">
            Agregar
          </BaseButton>
        </div>
      </div>
    </BaseDrawer>

    <!-- Drawer: Cambiar rol -->
    <BaseDrawer
      v-if="isChangeDrawerOpen"
      title="Cambiar rol del miembro"
      :open="isChangeDrawerOpen"
      @close="isChangeDrawerOpen = false"
    >
      <div class="vss-form">
        <p class="vss-member-name">{{ selectedMember?.user.name }}</p>

        <div v-if="changeMutation.generalError.value" class="vss-error">
          {{ changeMutation.generalError.value }}
        </div>

        <a-select
          v-model:value="changeForm.role_guid"
          placeholder="Seleccionar nuevo rol"
          :loading="isLoadingRoles"
          style="width: 100%"
        >
          <a-select-option
            v-for="role in vetRoles"
            :key="role.guid"
            :value="role.guid"
          >
            {{ role.name }}
          </a-select-option>
        </a-select>
        <div v-if="changeMutation.fieldErrors.value?.role_guid" class="vss-field-error">
          {{ changeMutation.fieldErrors.value.role_guid }}
        </div>

        <div class="vss-form-actions">
          <BaseButton variant="secondary" @click="isChangeDrawerOpen = false">Cancelar</BaseButton>
          <BaseButton :loading="changeMutation.isPending.value" @click="handleChangeRole">
            Guardar
          </BaseButton>
        </div>
      </div>
    </BaseDrawer>
  </div>
</template>

<style scoped>
.vss-root { padding-top: 4px; }

.vss-toolbar {
  display: flex;
  justify-content: flex-end;
  margin-bottom: 16px;
}

.vss-name  { font-weight: 600; color: var(--dt-title, #fff); }
.vss-email { font-family: monospace; font-size: 12px; color: var(--dt-text, #C8E2EF); }

.vss-form {
  display: flex;
  flex-direction: column;
  gap: 16px;
  padding: 8px 0;
}

.vss-form-actions {
  display: flex;
  gap: 8px;
  justify-content: flex-end;
  padding-top: 8px;
}

.vss-member-name {
  font-weight: 600;
  color: var(--dt-title, #fff);
  margin: 0 0 4px;
}

.vss-error {
  background: rgba(255, 77, 79, 0.12);
  border: 1px solid rgba(255, 77, 79, 0.3);
  border-radius: 8px;
  padding: 10px 14px;
  font-size: 13px;
  color: #ff4d4f;
}

.vss-field-error {
  font-size: 12px;
  color: #ff4d4f;
  margin-top: -8px;
}
</style>
```

**Nota:** `formatDate` debe ser importado desde `@/core/utils/date` (igual que en `VetClientsSection.vue`). Agregar el import al `<script setup>`.

### Archivos a modificar

#### `front/src/modules/vets/types/vet.types.ts`
**Cambio:** Agregar tipos para staff y la constante `VET_STAFF_ROLES`.

**Agregar al final del archivo:**
```typescript
// --- Staff de Vet (panel admin) ---

export const VET_STAFF_ROLES = ['vet', 'vet-assistant', 'vet-administrative'] as const
export type VetStaffRoleName = typeof VET_STAFF_ROLES[number]

export interface VetStaffUserItem {
  guid: string
  name: string
  first_name: string
  last_name: string
  email: string
}

export interface VetStaffRoleItem {
  guid: string
  name: VetStaffRoleName
}

export interface VetStaffItem {
  guid: string
  user: VetStaffUserItem
  role: VetStaffRoleItem
  contacts: unknown[]
  created_at: string
}

export interface AssignStaffPayload {
  user_guid: string
  role_guid: string
}

export interface ChangeStaffRolePayload {
  role_guid: string
}
```

#### `front/src/modules/vets/pages/VetDetailPage.vue`
**Cambio:** Agregar import de `VetStaffSection` y el tab "Staff" dentro de `<a-tabs>`.

**Antes (bloque `<script setup>`):**
```typescript
import VetClientsSection from '../components/VetClientsSection.vue'
```

**Después:**
```typescript
import VetClientsSection from '../components/VetClientsSection.vue'
import VetStaffSection   from '../components/VetStaffSection.vue'
```

**Antes (bloque `<a-tabs>`):**
```html
<a-tabs class="vd-tabs">
  <a-tab-pane key="clients" tab="Clientes vinculados">
    <VetClientsSection :vet-guid="props.guid" />
  </a-tab-pane>
</a-tabs>
```

**Después:**
```html
<a-tabs class="vd-tabs">
  <a-tab-pane key="clients" tab="Clientes vinculados">
    <VetClientsSection :vet-guid="props.guid" />
  </a-tab-pane>
  <a-tab-pane key="staff" tab="Staff">
    <PermissionGuard permission="vets.staff.read">
      <VetStaffSection :vet-guid="props.guid" />
    </PermissionGuard>
  </a-tab-pane>
</a-tabs>
```

---

## Orden de implementación

1. Agregar los 4 permisos (`vets.staff.read`, `vets.staff.create`, `vets.staff.update`, `vets.staff.delete`) al array en `back/database/seeders/PermissionSeeder.php`.

2. Verificar el orden de seeders en `back/database/seeders/DatabaseSeeder.php`: `PermissionSeeder` debe ejecutarse antes de `RoleSeeder`. Si no es así, reordenar.

3. Correr los seeders en el entorno de desarrollo:
   ```
   php artisan db:seed --class=PermissionSeeder
   php artisan db:seed --class=RoleSeeder
   ```
   Verificar que el rol `super-admin` recibió los 4 permisos nuevos.

4. Crear `back/app/Http/Requests/Members/AdminAssignStaffRequest.php` con la constante `VET_STAFF_ROLES` y validaciones.

5. Crear `back/app/Http/Requests/Members/AdminChangeStaffRoleRequest.php` referenciando `AdminAssignStaffRequest::VET_STAFF_ROLES`.

6. Modificar `back/app/Http/Controllers/V1/AdminVetController.php`:
   a. Agregar imports de `AdminAssignStaffRequest`, `AdminChangeStaffRoleRequest`, `UserProfileResource`, `UserProfileService`.
   b. Agregar `UserProfileService` al constructor.
   c. Agregar los 4 métodos: `staffIndex`, `staffStore`, `staffChangeRole`, `staffDestroy`.

7. Agregar las 4 rutas nuevas al grupo admin en `back/routes/api/vets.php`.

8. Probar manualmente los 4 endpoints con un cliente HTTP (Postman/Insomnia) con usuario autenticado que tenga el rol `super-admin`.

9. Agregar los tipos e interfaces de staff en `front/src/modules/vets/types/vet.types.ts` (constante `VET_STAFF_ROLES` y tipos `VetStaffItem`, `AssignStaffPayload`, `ChangeStaffRolePayload`).

10. Crear `front/src/modules/vets/api/vet-staff.api.ts` con las 4 funciones API.

11. Crear los 5 composables en `front/src/modules/vets/composables/`:
    - `useAdminVetStaff.ts`
    - `useAdminAssignStaff.ts`
    - `useAdminChangeStaffRole.ts`
    - `useAdminRemoveStaff.ts`
    - `useVetRoles.ts`

12. Crear `front/src/modules/vets/components/VetStaffSection.vue`.

13. Modificar `front/src/modules/vets/pages/VetDetailPage.vue`: agregar import y el nuevo `<a-tab-pane>`.

14. Probar manualmente en el browser: tab "Staff" visible, listado, agregar miembro, cambiar rol, eliminar con confirmación.

15. Correr los feature tests de backend (`php artisan test --filter=AdminVetStaffTest`).

---

## Riesgos y consideraciones

**R-01 — Eager loading de `role` en `userProfileService->list()`.**
`staffIndex` filtra los perfiles por `role->name`. Si `UserProfileRepository::listForVet` no hace eager loading de `role`, el acceso a `$profile->role->name` lanzará `N+1` o `null`. Verificar la implementación de `listForVet` antes de asumir que `role` está cargada. Si no lo está, agregar `$members->load('role')` en `staffIndex` antes del filtro.

**R-02 — `listForVet` incluye perfiles de clientes.**
El método `list(Vet $vet)` llama a `userProfileRepository->listForVet($vet)`. Si el repositorio filtra solo por `authenticatable_id = $vet->id` sin filtrar `authenticatable_type`, podría traer perfiles de clientes con el mismo id. Verificar que el repositorio sí filtre por `authenticatable_type = 'vet'`. Si no, el filtro de rol en `staffIndex` es la red de seguridad, pero es preferible corregirlo en el repositorio.

**R-03 — Rol `super-admin` recibe todos los permisos via `syncPermissions(Permission::all())`.**
Esta estrategia es correcta y automática: cualquier permiso nuevo en `PermissionSeeder` queda disponible al rol `super-admin` al correr `RoleSeeder`. El riesgo es si el seeder no se corre en producción después del deploy. Documentar en las instrucciones de deploy que se debe correr `php artisan db:seed --class=PermissionSeeder && php artisan db:seed --class=RoleSeeder`.

**R-04 — Paginación del endpoint `GET /v1/roles` en el composable `useVetRoles`.**
El `RoleController::index` devuelve paginación. Con `per_page: 50` es suficiente para el conjunto actual de roles, pero si en el futuro hay muchos roles custom, el filtrado podría perder registros. Solución a largo plazo: agregar un endpoint `GET /v1/roles?scope=vet` que retorne lista plana. En el corto plazo, 50 es más que suficiente.

**R-05 — `BaseDrawer`: verificar API de props.**
El plan asume que `BaseDrawer` acepta `:open` y emite `@close`. Verificar la API real del componente antes de codear. Si acepta `v-model:open` en lugar de `:open` + `@close`, ajustar el template de `VetStaffSection.vue`. Esto es algo trivial pero que el dev debe confirmar inspeccionando `BaseDrawer.vue`.

**R-06 — Búsqueda de usuario por GUID: UX subóptima.**
El formulario de alta acepta `user_guid` como campo de texto libre. Esto requiere que el admin conozca el UUID del usuario a agregar. Es funcional pero con mala UX. Se puede mejorar en una iteración posterior agregando un campo de búsqueda por email con un endpoint admin de lookup de usuarios (`GET /v1/users?search=email` ya existe). Se documenta como deuda técnica.

**R-07 — Sin validación de que el perfil siendo eliminado o modificado sea de scope `vet`.**
`staffDestroy` y `staffChangeRole` verifican que el `profileGuid` pertenezca a la vet via `findByGuidForVet`, pero no verifican explícitamente que el perfil tenga un rol de scope vet. Si un admin intenta modificar con este endpoint un perfil con `authenticatable_type = 'client'` (lo cual no debería ocurrir dado el check de tenant), el servicio lo procesaría igualmente. El check de `findByGuidForVet` valida `authenticatable_type = 'vet'` y `authenticatable_id = $vet->id`, lo que es suficiente protección.

**R-08 — Multi-tenant awareness del panel admin.**
Este feature es del panel superadmin (`/v1/admin/...`) y opera sobre veterinarias arbitrarias pasadas por `{guid}`. No hay riesgo de cross-tenant data leak porque el endpoint no está bajo el middleware `vet.tenant` — es intencional que el superadmin pueda ver el staff de cualquier vet. El check de que el `profileGuid` pertenece a la vet indicada en `{guid}` (via `findByGuidForVet`) es la protección correcta.

---

## Pendientes / fuera de alcance

- **Búsqueda de usuario por email en el formulario de alta:** La UX ideal sería un campo de búsqueda por email que llame a `GET /v1/users?search=email` y presente sugerencias. Requiere un componente de autocomplete y potencialmente un endpoint admin de lookup. Queda para una iteración posterior.
- **Paginación del listado de staff:** El endpoint `staffIndex` retorna todos los perfiles sin paginar (igual que `MemberController::index` en el panel tenant). Con veterinarias grandes esto puede ser un problema. Se puede agregar paginación en una segunda iteración.
- **Filtro por rol en el listado:** No hay filtro de búsqueda en `VetStaffSection`. Se puede agregar un filtro por nombre o rol en una segunda iteración.
- **Tests de frontend:** El plan no incluye tests unitarios de componentes Vue. Si el proyecto tiene Vitest configurado, se deberían agregar tests para `VetStaffSection.vue` y los composables.
- **Permisos `admin` y `operador`:** El brief solo menciona `super-admin`. Los roles `admin` y `operador` no reciben los permisos de staff. Si en el futuro se quiere dar acceso a `admin`, modificar `RoleSeeder` para agregarlos explícitamente.
