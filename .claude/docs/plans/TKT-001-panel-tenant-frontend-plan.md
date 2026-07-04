# Plan técnico: Panel Tenant Frontend — Infraestructura de navegación multi-vet y página de perfil

## Input procesado
`C:\laragon\www\sav\.claude\docs\tickets\TKT-001-panel-tenant-frontend.md`

---

## Resumen ejecutivo

Se implementa la infraestructura completa de navegación tenant-scoped para el panel de veterinarias en SAV. El trabajo abarca: (1) un endpoint backend nuevo `GET /v1/user/vets` que lista las veterinarias activas del usuario autenticado; (2) la ampliación del store `useVetStore` con persistencia parcial en localStorage solo para `lastVisitedSlug`; (3) la extensión de `usePermission()` para evaluar roles del contexto tenant sin romper callers existentes; (4) composables de carga de datos tenant; (5) el guard `vetTenantGuard` async con manejo diferenciado de errores; (6) el layout `VetTenantLayout` con skeleton y el switcher `VetSwitcher`; (7) el banner multi-pestaña `VetChangedBanner`; y (8) la primera página funcional `VetProfilePage` en solo lectura. El módulo admin `/admin/vets/*` no se toca. Las rutas existentes `/vets/:vetSlug/clients` ya definidas en el router se preservan intactas.

---

## Hallazgos de la investigación previa (código real)

**H01 — `vet.store.ts` implementado con firma mínima:**
El store real tiene solo `currentVet`, `setCurrentVet`, `clearCurrentVet`. No tiene `vetSlug`, `userVets`, `currentProfile` ni `lastVisitedSlug`. El plan del módulo anterior lo definía con `vetSlug` pero la implementación real lo omitió. Al extender el store hay que agregar `vetSlug` también (estaba en la spec original pero no fue implementado).

**H02 — `usePermissions.ts` expone `can(permission)`, `hasRole(role)`, `hasAnyRole(...roles)`:**
La firma real NO coincide exactamente con la del ticket (que hablaba de `usePermission()` con sobrecarga). El callers existente usa `can()`, `hasRole()`, `hasAnyRole()`. Se extiende agregando `canInTenant(role)` como función adicional, respetando los nombres existentes.

**H03 — `pinia-plugin-persistedstate` instalado y configurado:**
`front/src/core/plugins/pinia.ts` ya registra `piniaPersist`. `auth.store.ts` usa `persist: true` (opción de opciones object). El patrón para persistencia parcial es `persist: { pick: ['lastVisitedSlug'] }` (API de `pinia-plugin-persistedstate` v3+).

**H04 — `routes/api/user.php` NO existe:**
El archivo de rutas debe crearse. El `api.php` carga automáticamente todos los archivos en `routes/api/*.php` via `glob`.

**H05 — `getRouteKeyName()` retorna `'guid'` (vía `HasGuid`):**
Route model binding de Laravel resuelve `Vet` por `guid`, no por slug. El middleware `EnsureUserBelongsToVet` ya resuelve por slug raw del parámetro de ruta usando `$request->route('vet')`. El `VetController::show()` recibe la vet ya resuelta desde `$request->attributes->get('current_vet')`. La URL del frontend usa slug; el backend lo resuelve por el middleware, no por binding.

**H06 — `VetResource` shape confirmado:**
Retorna: `guid`, `name`, `slug`, `tax_id`, `registration_number`, `logo_path`, `pdf_title`, `pdf_subtitle`, `validated_at`, `suspended_at`, `is_active`, `country` (whenLoaded), `document_type` (whenLoaded), `validated_by` (whenLoaded), `contacts` (whenLoaded), `created_at`. `VetItem` en frontend refleja este shape correctamente.

**H07 — Rutas `/vets/:vetSlug/clients` ya existen en el router:**
`clients.routes.ts` y `admin-clients.routes.ts` ya están registradas. Las nuevas rutas del panel tenant van en un archivo separado `vets-tenant.routes.ts`. Hay que tener cuidado de no pisar los `name` de ruta existentes.

**H08 — `AppSidebar` ya tiene `VetMenu` con detección de contexto vet:**
`isVetContext = route.path.startsWith('/vets/')`. El `VetMenu` ya existe con ítems de Clientes y "Volver al admin". El nuevo layout `VetTenantLayout` coexiste con `AppLayout` como grupo de rutas separado. Hay que revisar que el nuevo layout use el mismo sidebar o uno propio.

**H09 — Discrepancia: `UserProfileService.findForUserAndVet` usa morphMap alias `'vet'`:**
La implementación en `UserProfileRepositoryEloquent.findForUserAndVet` usa `where('authenticatable_type', 'vet')` (alias morphMap), no el nombre de clase completo. El `UserVetService` nuevo debe hacer lo mismo.

---

## Decisiones tomadas

**DEC-01 — `UserVetService`: query directa sin nuevo repositorio method**
  Decisión: El `UserVetService::getActiveVetsForUser()` usa el `UserProfileRepositoryInterface` existente más una query directa sobre el modelo `Vet` para cargar los datos de la vet. No se agrega un método nuevo al repositorio `UserProfileRepositoryInterface`.
  Justificación: La query es simple: listar `user_profiles` del usuario con `authenticatable_type = 'vet'`, hacer JOIN con `vets` para filtrar activas. El repositorio de `UserProfile` ya tiene `findForUserAndVet` pero no un `listActiveVetsForUser`. Agregar ese método al repositorio es correcto en teoría, pero la query involucra dos modelos (UserProfile + Vet) y el join más limpio es hacerlo en el Service con Eloquent. Se usa el patrón que ya usa `AdminVetController::staffIndex`: acceso a través del Service, no del repositorio directo.
  Alternativa descartada: Agregar `listActiveVetsForUser(User $user): Collection` a `UserProfileRepositoryInterface`. Descartado para no tocar la interfaz en este ticket — cambiar una interfaz requiere actualizar la implementación y el binding, y para una query de una sola línea es overhead desproporcionado.

**DEC-02 — `UserVetController`: sin FormRequest (endpoint de lectura sin parámetros)**
  Decisión: `UserVetController::index()` no usa FormRequest. Recibe solo `Request $request` y extrae `$request->user()`.
  Justificación: El endpoint no recibe body ni query params. Un FormRequest vacío no agrega valor.
  Alternativa descartada: FormRequest vacío — overhead sin beneficio.

**DEC-03 — Extensión de `usePermissions.ts`: agregar `canInTenant()`, no sobrecarga de `usePermission()`**
  Decisión: Se agrega la función `canInTenant(role: VetTenantRole | VetTenantRole[]): boolean` dentro del retorno de `usePermission()`. Los callers existentes (`can`, `hasRole`, `hasAnyRole`) no se modifican.
  Justificación: TypeScript no tiene sobrecarga de funciones real con implementaciones separadas de forma limpia para composables. Agregar una función con nombre diferente `canInTenant` es más explícito y no rompe nada existente. El ticket habla de "segunda sobrecarga opcional" — la semántica es la misma aunque el mecanismo sea una función adicional en el mismo composable.
  Alternativa descartada: Modificar la firma de `usePermission(permission, context?)` con segundo parámetro opcional — descartado porque requiere cambiar todos los callers existentes que usan desestructuración `const { can } = usePermission()`.

**DEC-04 — `VetTenantLayout` como layout separado, no modificar `AppLayout`**
  Decisión: Se crea `front/src/components/layouts/VetTenantLayout.vue` como un layout completamente independiente. Las rutas `/vets/:vetSlug/*` se montan bajo este layout, NO bajo `AppLayout`.
  Justificación: `AppLayout` ya tiene su propio sidebar (`AppSidebar`) que condiciona `VetMenu` vs `AppMenu` por ruta. El nuevo layout puede reutilizar `AppSidebar` directamente ya que `AppSidebar` ya detecta `/vets/` context. Esto preserva el sidebar existente sin duplicarlo, mientras que el layout propio permite inyectar el guard y la carga del contexto tenant de forma limpia.
  Alternativa descartada: Agregar el guard y la carga tenant dentro de `AppLayout` con un `v-if` para rutas vet — descartado porque ensucia un layout que hoy es limpio y hace que toda la app cargue el contexto tenant incluso cuando no está en rutas vet.

**DEC-05 — Guard `vetTenantGuard`: usa `queryClient` de Vue Query para cache de `userVets`**
  Decisión: El guard llama directamente a `fetchUserVets()` (función de API pura) y cachea el resultado en el store (`vetStore.userVets`). Si `vetStore.userVets.length > 0`, usa el cache del store en lugar de llamar al servidor. El guard NO usa `useQuery` directamente (los composables de Vue Query no pueden usarse fuera de componentes/setup).
  Justificación: Los navigation guards de Vue Router no tienen contexto de componente, por lo que `useQuery` no funciona ahí. La solución correcta es llamar a la función de API directamente y mantener el cache en el store Pinia (que sí es accesible desde el guard).
  Alternativa descartada: Inyectar `queryClient` en el guard — descartado porque `useQueryClient()` tampoco funciona fuera de setup.

**DEC-06 — Rutas tenant: grupo separado bajo `VetTenantLayout` con `beforeEnter` en el grupo padre**
  Decisión: Se crea un bloque de rutas en el router con `component: VetTenantLayout` y `beforeEnter: vetTenantGuard`. Las rutas hijas (`/vets/:vetSlug/perfil`) son children de este grupo.
  Justificación: Aplicar el guard una vez en el padre evita duplicarlo en cada ruta hija. Este es el patrón de Vue Router para guards de grupo.
  Consecuencia: Las rutas existentes `/vets/:vetSlug/clients/*` que actualmente están bajo `AppLayout` necesitan ser movidas o conservadas. Se conservan en su ubicación actual bajo `AppLayout` para no romper nada — el guard nuevo solo aplica a las rutas del nuevo grupo bajo `VetTenantLayout`.

**DEC-07 — `useVetProfile` y `useVetTenant`: dos composables distintos con responsabilidades separadas**
  Decisión: `useVetProfile(slug)` es un wrapper de `useQuery` puro que llama a `GET /v1/vets/{slug}`. `useVetTenant()` es el composable orquestador que popula el store (llama a `useVetProfile` internamente y sincroniza el store). `VetTenantLayout` usa `useVetTenant()`.
  Justificación: Separa la capa de datos (Vue Query) de la lógica de estado global (store Pinia). `useVetProfile` puede reutilizarse en otros contextos sin efectos secundarios en el store.

**DEC-08 — `UserVetResource` incluye `role.name` del `UserProfile`, no rol Spatie**
  Decisión: El resource retorna el `role.name` del `UserProfile` (rol tenant), no los roles Spatie del usuario. La query carga el `UserProfile` con su relación `role`.
  Justificación: El ticket especifica explícitamente esto. Los roles Spatie son del panel de administración; los roles tenant son los del `UserProfile`.

**DEC-09 — Persistencia parcial del store: `persist: { pick: ['lastVisitedSlug'] }`**
  Decisión: El store usa la opción `persist` de `pinia-plugin-persistedstate` con `pick: ['lastVisitedSlug']`. Solo este campo sobrevive recargas de página.
  Justificación: `currentVet` y `currentProfile` son datos de sesión que deben recargarse del servidor en cada navegación (el guard los recarga). Persistirlos generaría datos stale tras cambios de estado de la vet en el backend.

**DEC-10 — Endpoint `GET /v1/vets/{vet}` desde el frontend usa slug en la URL**
  Decisión: `fetchVetBySlug(slug)` llama a `GET /v1/vets/{slug}`. El backend resuelve el slug vía middleware `vet.tenant`, no vía route model binding.
  Justificación: La ruta está definida en `vets.php` como `Route::prefix('v1/vets/{vet}')` con middleware `vet.tenant`. El middleware resuelve el parámetro `{vet}` como slug raw. El `VetController::show()` ya funciona así.

---

## Cambios en BACKEND

### Archivos a crear

---

#### `back/routes/api/user.php`

**Propósito:** Rutas del usuario autenticado que no dependen de un tenant. Incluye el endpoint de listado de vets asignadas al usuario.

```php
<?php

use App\Http\Controllers\V1\UserVetController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/user')->middleware('auth:sanctum')->group(function () {
    Route::get('/vets', [UserVetController::class, 'index']);
});
```

**Notas:**
- NO usa middleware `vet.tenant` — el usuario puede no tener slug activo aún.
- No requiere permiso Spatie — todo usuario autenticado puede consultar sus propias vets.
- El archivo es cargado automáticamente por `routes/api.php` via `glob`.

---

#### `back/app/Http/Controllers/V1/UserVetController.php`

**Propósito:** Controlador delgado que retorna las veterinarias activas asignadas al usuario autenticado.

```php
<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\UserVetResource;
use App\Services\UserVetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserVetController extends Controller
{
    public function __construct(
        private UserVetService $userVetService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $vets = $this->userVetService->getActiveVetsForUser($request->user());

            return $this->makeSuccess(UserVetResource::collection($vets));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
```

**Dependencias inyectadas:** `UserVetService`.

---

#### `back/app/Services/UserVetService.php`

**Propósito:** Lógica para obtener las veterinarias activas asignadas al usuario autenticado, incluyendo su rol en cada una.

```php
<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Database\Eloquent\Collection;

class UserVetService
{
    /**
     * Retorna las veterinarias activas asignadas al usuario.
     *
     * Una vet activa es aquella con validated_at IS NOT NULL y suspended_at IS NULL.
     * El rol retornado es el del UserProfile correspondiente (rol tenant),
     * no los roles Spatie del usuario.
     *
     * @param  User  $user
     * @return Collection  Colección de UserProfile con relaciones 'authenticatable' (Vet) y 'role' cargadas.
     */
    public function getActiveVetsForUser(User $user): Collection
    {
        return UserProfile::query()
            ->with(['role', 'authenticatable'])
            ->where('user_id', $user->id)
            ->where('authenticatable_type', 'vet')
            ->whereHas('authenticatable', function ($query) {
                $query->whereNotNull('validated_at')
                      ->whereNull('suspended_at');
            })
            ->get();
    }
}
```

**Notas:**
- Usa `UserProfile::query()` directamente porque este caso de uso cruza dos modelos (UserProfile + Vet) y no justifica un método adicional en la interfaz del repositorio (DEC-01).
- `whereHas('authenticatable', ...)` filtra solo perfiles donde la vet esté activa.
- `with(['role', 'authenticatable'])` hace eager loading de rol y la vet en una sola query adicional.
- El resource `UserVetResource` recibe un `UserProfile` y accede a `$this->authenticatable` para los datos de la vet.

---

#### `back/app/Http/Resources/V1/UserVetResource.php`

**Propósito:** Resource que transforma un `UserProfile` (con relaciones cargadas) en el shape esperado por el frontend para el selector de vets.

```php
<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserVetResource extends JsonResource
{
    /**
     * @param  Request  $request
     * @return array{
     *   guid: string,
     *   name: string,
     *   slug: string,
     *   logo_path: string|null,
     *   is_active: bool,
     *   role: array{name: string}
     * }
     */
    public function toArray(Request $request): array
    {
        /** @var \App\Models\Vet $vet */
        $vet = $this->authenticatable;

        return [
            'guid'      => $vet->guid,
            'name'      => $vet->name,
            'slug'      => $vet->slug,
            'logo_path' => $vet->logo_path,
            'is_active' => $vet->validated_at !== null && $vet->suspended_at === null,
            'role'      => [
                'name' => $this->role->name,
            ],
        ];
    }
}
```

**Notas:**
- `$this` es el `UserProfile`. `$this->authenticatable` es la `Vet` (cargada con eager loading por el Service).
- `$this->role` es el `Role` del `UserProfile` (rol tenant). Se accede a `->name`.
- El `guid` retornado es el de la `Vet`, no del `UserProfile`. El frontend necesita el identificador de la vet para el switcher.

---

### Archivos a modificar en BACKEND

No se modifica ningún archivo de backend existente. El `UserVetService` es autónomo y no requiere binding en `AppServiceProvider` porque Laravel resuelve automáticamente las dependencias concretas que no tienen interface (solo las interfaces necesitan binding explícito).

---

### Rutas API nuevas

| Método | Path | Controller@action | Middleware |
|--------|------|-------------------|------------|
| GET | `/v1/user/vets` | `UserVetController@index` | `auth:sanctum` |

**Contrato del endpoint:**

Request:
```
GET /v1/user/vets
Authorization: Bearer {token}
```

Response 200 (usuario con 2 vets activas):
```json
{
  "success": true,
  "data": [
    {
      "guid": "550e8400-e29b-41d4-a716-446655440000",
      "name": "Clínica San Marcos",
      "slug": "clinica-san-marcos",
      "logo_path": null,
      "is_active": true,
      "role": {
        "name": "vet"
      }
    },
    {
      "guid": "660e9511-f30c-52e5-b827-557766551111",
      "name": "Veterinaria Norte",
      "slug": "veterinaria-norte",
      "logo_path": "https://storage.example.com/logos/vet-norte.png",
      "is_active": true,
      "role": {
        "name": "vet-assistant"
      }
    }
  ]
}
```

Response 200 (usuario sin vets asignadas o solo con vets inactivas):
```json
{
  "success": true,
  "data": []
}
```

Errores posibles:
| HTTP | Cuándo |
|------|--------|
| 401  | Token inválido o expirado |
| 500  | Error interno del servidor |

---

### Tests a generar — Backend

**Feature — `tests/Feature/Controllers/V1/UserVetControllerTest.php`**
- `test_returns_active_vets_for_authenticated_user`: usuario con 2 vets activas (validated_at set, suspended_at null) → response 200, data con 2 elementos, campos guid/name/slug/logo_path/is_active/role.name presentes.
- `test_returns_empty_array_when_user_has_no_vets`: usuario sin UserProfiles de tipo vet → response 200, data vacío.
- `test_excludes_suspended_vets`: usuario con 1 vet activa y 1 suspendida → solo retorna la activa.
- `test_excludes_unvalidated_vets`: usuario con 1 vet validada y 1 sin validar → solo retorna la validada.
- `test_returns_correct_role_name`: usuario con rol vet-assistant en la vet → `role.name` es `"vet-assistant"`.
- `test_requires_authentication`: request sin token → 401.
- `test_does_not_use_vet_tenant_middleware`: el endpoint no requiere slug de vet en la URL ni `vet.tenant` middleware.

---

## Cambios en FRONTEND

### Paso 1: Tipos y API

---

#### `front/src/core/types/vet-context.types.ts` — CREAR

**Propósito:** Tipos compartidos del contexto tenant accesibles desde cualquier módulo.

```typescript
export type VetTenantRole = 'vet' | 'vet-assistant' | 'vet-administrative'

export interface VetUserProfile {
  guid: string
  role: {
    name: VetTenantRole
  }
}
```

---

#### `front/src/modules/vets/types/user-vet.types.ts` — CREAR

**Propósito:** Tipos del response de `GET /v1/user/vets`.

```typescript
import type { VetTenantRole } from '@/core/types/vet-context.types'

export interface UserVetItem {
  guid: string
  name: string
  slug: string
  logo_path: string | null
  is_active: boolean
  role: {
    name: VetTenantRole
  }
}
```

---

#### `front/src/modules/vets/api/user-vets.api.ts` — CREAR

**Propósito:** Función de API pura para `GET /v1/user/vets`. Usada por el guard (fuera de setup) y por el composable `useUserVets`.

```typescript
import { http } from '@/core/api/http'
import type { UserVetItem } from '../types/user-vet.types'

export async function fetchUserVets(): Promise<UserVetItem[]> {
  const res = await http.get<UserVetItem[]>('/v1/user/vets')
  return res.data
}
```

**Nota:** El interceptor de `http` desenvuelve `{ success, data }` automáticamente. `res.data` ya es el array de `UserVetItem`.

---

### Paso 2: Store ampliado

---

#### `front/src/stores/vet.store.ts` — MODIFICAR

**Cambio:** Reemplazar completamente el archivo para ampliar el store con los campos nuevos y persistencia parcial.

**Estado actual (resumido):**
```typescript
// Solo tiene: currentVet (VetBasic | null), setCurrentVet, clearCurrentVet
// Sin: vetSlug, currentProfile, userVets, lastVisitedSlug
```

**Estado nuevo completo:**

```typescript
import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { VetItem } from '@/modules/vets/types/vet.types'
import type { UserVetItem } from '@/modules/vets/types/user-vet.types'
import type { VetUserProfile } from '@/core/types/vet-context.types'

export const useVetStore = defineStore('vet', () => {
  // Datos de la vet activa (cargados desde GET /v1/vets/{slug} por VetTenantLayout)
  const currentVet = ref<VetItem | null>(null)
  const vetSlug    = ref<string | null>(null)

  // Perfil del usuario autenticado en la vet activa
  const currentProfile = ref<VetUserProfile | null>(null)

  // Lista de vets asignadas al usuario (cache en memoria, NO persistido)
  const userVets = ref<UserVetItem[]>([])

  // Último slug visitado — solo este campo se persiste en localStorage
  const lastVisitedSlug = ref<string | null>(null)

  // --- Acciones ---

  function setCurrentVet(vet: VetItem, profile: VetUserProfile): void {
    currentVet.value     = vet
    vetSlug.value        = vet.slug
    currentProfile.value = profile
    lastVisitedSlug.value = vet.slug
  }

  function setUserVets(vets: UserVetItem[]): void {
    userVets.value = vets
  }

  function clearCurrentVet(): void {
    currentVet.value     = null
    vetSlug.value        = null
    currentProfile.value = null
    // NO limpiar userVets ni lastVisitedSlug aquí
  }

  function clearAll(): void {
    currentVet.value      = null
    vetSlug.value         = null
    currentProfile.value  = null
    userVets.value        = []
    lastVisitedSlug.value = null
  }

  function setLastVisitedSlug(slug: string): void {
    lastVisitedSlug.value = slug
  }

  return {
    currentVet,
    vetSlug,
    currentProfile,
    userVets,
    lastVisitedSlug,
    setCurrentVet,
    setUserVets,
    clearCurrentVet,
    clearAll,
    setLastVisitedSlug,
  }
}, {
  persist: {
    pick: ['lastVisitedSlug'],
  },
})
```

**Notas importantes:**
- `VetBasic` ya no se usa en el store — se usa `VetItem` (tipo completo, que es lo que carga el layout desde `GET /v1/vets/{slug}`).
- Los callers existentes de `setCurrentVet(vet)` deben actualizarse para pasar también `profile`. Verificar usos en el código base antes de ejecutar.
- `clearAll()` es para usar en logout o cuando el usuario pierde acceso. `clearCurrentVet()` es para el switcher (no limpia userVets ni lastVisitedSlug).
- La opción `persist: { pick: ['lastVisitedSlug'] }` es la sintaxis de `pinia-plugin-persistedstate` v3. El plugin ya está registrado en `front/src/core/plugins/pinia.ts`.

**Verificar callers existentes de `useVetStore`:**
Buscar en el proyecto con `grep -r "useVetStore" front/src --include="*.ts" --include="*.vue"` antes de implementar. Si hay callers de `setCurrentVet(vet)` con la firma vieja (sin profile), actualizarlos.

---

### Paso 3: usePermissions extendido

---

#### `front/src/core/composables/usePermissions.ts` — MODIFICAR

**Cambio:** Agregar `canInTenant(role)` que consulta `vetStore.currentProfile.role.name`.

**Antes (resumido):**
```typescript
export function usePermission() {
    // ...
    return { can, hasRole, hasAnyRole };
}
```

**Después:**

```typescript
import { computed } from 'vue'
import { useAuthStore } from '@/modules/auth/stores/auth.store'
import { useVetStore } from '@/stores/vet.store'
import type { VetTenantRole } from '@/core/types/vet-context.types'

export function usePermission() {
    const auth     = useAuthStore()
    const vetStore = useVetStore()

    const userPermissions = computed(() => auth.user?.permissions ?? [])
    const userRoles       = computed(() => auth.user?.roles ?? [])

    function can(permission: string): boolean {
        return userPermissions.value.includes(permission)
    }

    function hasRole(role: string): boolean {
        return userRoles.value.some(r => r.name === role)
    }

    function hasAnyRole(...roles: string[]): boolean {
        return roles.some(r => userRoles.value.some(ur => ur.name === r))
    }

    /**
     * Verifica si el usuario tiene el rol (o alguno de los roles) en el contexto
     * del tenant activo (veterinaria). Consulta vetStore.currentProfile.role.name.
     *
     * Retorna false si currentProfile es null (sin contexto tenant activo).
     * NO evalúa permisos Spatie — es un sistema ortogonal.
     *
     * @example
     * // En VetProfilePage:
     * const { canInTenant } = usePermission()
     * canInTenant('vet')               // true si el usuario es vet en la vet activa
     * canInTenant(['vet', 'vet-assistant']) // true si tiene alguno de esos roles
     */
    function canInTenant(role: VetTenantRole | VetTenantRole[]): boolean {
        const profile = vetStore.currentProfile
        if (!profile) return false

        const currentRole = profile.role.name
        if (Array.isArray(role)) {
            return role.includes(currentRole)
        }
        return currentRole === role
    }

    return { can, hasRole, hasAnyRole, canInTenant }
}
```

**Notas:**
- Los callers existentes (`can`, `hasRole`, `hasAnyRole`) no cambian.
- `canInTenant` es reactivo: como `vetStore.currentProfile` es un `ref`, la evaluación es automáticamente reactiva si se usa en un `computed` o template.

---

### Paso 4: Composables tenant

---

#### `front/src/modules/vets/composables/useUserVets.ts` — CREAR

**Propósito:** Carga y cachea la lista de vets del usuario vía Vue Query. Usado por `VetSwitcher` y potencialmente por la página de selección futura.

```typescript
import { useQuery } from '@tanstack/vue-query'
import { useVetStore } from '@/stores/vet.store'
import { fetchUserVets } from '../api/user-vets.api'

export function useUserVets() {
  const vetStore = useVetStore()

  const query = useQuery({
    queryKey: ['user-vets'],
    queryFn: async () => {
      const vets = await fetchUserVets()
      vetStore.setUserVets(vets)
      return vets
    },
    staleTime: 1000 * 60 * 5, // 5 minutos — la lista de vets del usuario cambia raramente
  })

  return query
}
```

---

#### `front/src/modules/vets/api/vets.api.ts` — MODIFICAR

**Cambio:** Agregar `fetchVetBySlug(slug)` para el endpoint del panel tenant. El endpoint admin existente usa `getVetApi(guid)` que llama a `/v1/admin/vets/{guid}`. El panel tenant llama a `/v1/vets/{slug}`.

**Agregar al final del archivo:**

```typescript
/**
 * Obtiene los datos completos de una veterinaria desde el panel tenant.
 * Requiere que el usuario tenga UserProfile en esa vet y que esté activa.
 * El backend resuelve el parámetro {vet} como slug vía middleware vet.tenant.
 */
export async function fetchVetBySlug(slug: string): Promise<VetItem> {
  const res = await http.get<VetItem>(`/v1/vets/${slug}`)
  return res.data
}
```

---

#### `front/src/modules/vets/composables/useVetProfile.ts` — CREAR

**Propósito:** Wrapper de `useQuery` para `GET /v1/vets/{slug}`. Retorna `VetItem` completo. Sin efectos secundarios en el store.

```typescript
import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import type { Ref } from 'vue'
import { fetchVetBySlug } from '../api/vets.api'

export function useVetProfile(slug: Ref<string> | string) {
  const slugRef = computed(() => toValue(slug))

  return useQuery({
    queryKey: ['vet-profile', slugRef],
    queryFn: () => fetchVetBySlug(slugRef.value),
    enabled: computed(() => Boolean(slugRef.value)),
    staleTime: 1000 * 60 * 2, // 2 minutos
    retry: (failureCount, error: unknown) => {
      // No reintentar en 403/404 — son errores definitivos de acceso
      const e = error as { status?: number }
      if (e?.status === 403 || e?.status === 404) return false
      return failureCount < 2
    },
  })
}
```

---

#### `front/src/modules/vets/composables/useVetTenant.ts` — CREAR

**Propósito:** Composable orquestador del contexto tenant. Carga la vet activa, determina el perfil del usuario en ella, y popula el `vetStore`. Usado exclusivamente por `VetTenantLayout`.

```typescript
import { watch, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useVetStore } from '@/stores/vet.store'
import { useVetProfile } from './useVetProfile'
import { useNotification } from '@/core/composables/useNotification'
import type { VetUserProfile } from '@/core/types/vet-context.types'
import type { VetTenantRole } from '@/core/types/vet-context.types'

export function useVetTenant() {
  const route    = useRoute()
  const router   = useRouter()
  const vetStore = useVetStore()
  const { error: notifyError } = useNotification()

  const slug = computed(() => route.params.vetSlug as string)

  const { data: vetData, isLoading, isError, error } = useVetProfile(slug)

  // Cuando la vet se carga correctamente, sincronizar el store
  watch(vetData, (vet) => {
    if (!vet) return

    // Encontrar el perfil del usuario en esta vet desde la lista de userVets
    const userVetItem = vetStore.userVets.find(v => v.slug === slug.value)
    if (!userVetItem) return

    const profile: VetUserProfile = {
      guid: userVetItem.guid,
      role: { name: userVetItem.role.name as VetTenantRole },
    }

    vetStore.setCurrentVet(vet, profile)
  }, { immediate: true })

  // Manejar errores de carga: redirect con toast
  watch(isError, (hasError) => {
    if (!hasError) return

    const e = error.value as { status?: number } | null

    vetStore.clearCurrentVet()
    vetStore.setLastVisitedSlug(null as unknown as string) // limpiar slug guardado

    if (e?.status === 404) {
      notifyError('La veterinaria no existe.')
    } else if (e?.status === 403) {
      notifyError('Ya no tenés acceso a esta veterinaria.')
    } else {
      notifyError('Esta veterinaria no está disponible actualmente.')
    }

    router.replace('/dashboard')
  })

  return { vetData, isLoading, isError }
}
```

**Notas:**
- `userVetItem.guid` es el guid de la Vet (no del UserProfile) según el `UserVetResource`. Para armar el `VetUserProfile` necesitamos el guid del UserProfile, pero `UserVetItem` solo tiene el guid de la vet. Se usa el guid de la vet como identificador del perfil de forma temporal ya que el frontend solo necesita el rol, no el guid del UserProfile para las acciones de este ticket. Si en un ticket futuro se necesita el guid del UserProfile, extender `UserVetResource` para incluirlo.
- La limpieza de `lastVisitedSlug` al encontrar error: `vetStore.setLastVisitedSlug(null as unknown as string)` es inelegante. Alternativa: agregar `clearLastVisitedSlug(): void` al store. Se deja como tarea del dev elegir cuál prefiere; ambas son válidas.

---

### Paso 5: Guard

---

#### `front/src/router/guards/vetTenantGuard.ts` — CREAR

**Propósito:** Guard que verifica que el slug del parámetro de ruta pertenece al usuario autenticado. Opera sobre el cache del store; si el cache está vacío, llama al servidor.

```typescript
import type { NavigationGuard } from 'vue-router'
import { useVetStore } from '@/stores/vet.store'
import { useNotification } from '@/core/composables/useNotification'
import { fetchUserVets } from '@/modules/vets/api/user-vets.api'

export const vetTenantGuard: NavigationGuard = async (to) => {
  const vetStore = useVetStore()
  const { error: notifyError } = useNotification()

  const slug = to.params.vetSlug as string

  if (!slug) {
    return { path: '/dashboard', replace: true }
  }

  // Si el cache de userVets está vacío, cargar desde el servidor
  if (vetStore.userVets.length === 0) {
    try {
      const vets = await fetchUserVets()
      vetStore.setUserVets(vets)
    } catch {
      notifyError('No se pudo verificar el acceso a la veterinaria.')
      return { path: '/dashboard', replace: true }
    }
  }

  // Verificar que el slug esté en la lista de vets del usuario
  const matchingVet = vetStore.userVets.find(v => v.slug === slug)

  if (!matchingVet) {
    // No está en la lista: puede ser slug inexistente o vet de otro tenant
    // El backend distinguirá 404 vs 403; aquí usamos un mensaje genérico
    // ya que no sabemos si el slug existe o si simplemente no tiene acceso.
    notifyError('No tenés acceso a esta veterinaria o no existe.')
    return { path: '/dashboard', replace: true }
  }

  return true
}
```

**Notas de comportamiento:**
- El guard es async porque puede necesitar llamar al servidor.
- Si `userVets` ya está populado (por una navegación previa en la misma sesión), no hace llamada al servidor.
- El mensaje de error es genérico en el guard porque no se puede distinguir 404 vs 403 sin hacer otra llamada. La distinción detallada la maneja `useVetTenant()` al cargar el perfil completo.
- Cuando el usuario hace logout, `vetStore.clearAll()` debe llamarse. Verificar que el logout en `auth.store.ts` lo incluya.

---

### Paso 6: Componentes de layout y shared

---

#### `front/src/components/shared/VetChangedBanner.vue` — CREAR

**Propósito:** Banner no intrusivo que detecta cambio de veterinaria en otra pestaña vía evento `storage` de localStorage.

```vue
<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'

const showBanner = ref(false)

function handleStorageEvent(event: StorageEvent): void {
  // pinia-plugin-persistedstate almacena el estado bajo la key del store ('vet')
  // El campo lastVisitedSlug está dentro del JSON serializado del store.
  // La key del storage depende de la configuración; por defecto es el id del store: 'vet'
  if (event.key !== 'vet') return

  try {
    const newValue = event.newValue ? JSON.parse(event.newValue) : null
    const oldValue = event.oldValue ? JSON.parse(event.oldValue) : null

    const newSlug = newValue?.lastVisitedSlug ?? null
    const oldSlug = oldValue?.lastVisitedSlug ?? null

    if (newSlug !== oldSlug && newSlug !== null) {
      showBanner.value = true
    }
  } catch {
    // JSON parse falló — ignorar
  }
}

onMounted(() => {
  window.addEventListener('storage', handleStorageEvent)
})

onUnmounted(() => {
  window.removeEventListener('storage', handleStorageEvent)
})

function reload(): void {
  window.location.reload()
}
</script>

<template>
  <Transition name="banner-slide">
    <div v-if="showBanner" class="vet-changed-banner">
      <span class="vet-changed-banner__text">
        Cambiaste de veterinaria en otra pestaña.
      </span>
      <button class="vet-changed-banner__btn" @click="reload">
        Recargar
      </button>
      <button class="vet-changed-banner__close" @click="showBanner = false" aria-label="Cerrar">
        &times;
      </button>
    </div>
  </Transition>
</template>

<style scoped>
.vet-changed-banner {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  z-index: 9999;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
  padding: 10px 16px;
  background: var(--dt-warning-bg, #2a2200);
  border-bottom: 1px solid var(--dt-warning, #faad14);
  font-size: 13px;
  color: var(--dt-warning, #faad14);
}

.vet-changed-banner__text {
  flex: 1;
  text-align: center;
}

.vet-changed-banner__btn {
  background: var(--dt-warning, #faad14);
  color: #000;
  border: none;
  border-radius: 4px;
  padding: 4px 12px;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  transition: opacity 0.15s;
}

.vet-changed-banner__btn:hover {
  opacity: 0.85;
}

.vet-changed-banner__close {
  background: none;
  border: none;
  color: var(--dt-warning, #faad14);
  font-size: 18px;
  cursor: pointer;
  line-height: 1;
  padding: 0 4px;
  opacity: 0.7;
}

.vet-changed-banner__close:hover {
  opacity: 1;
}

.banner-slide-enter-active,
.banner-slide-leave-active {
  transition: transform 0.2s ease, opacity 0.2s ease;
}

.banner-slide-enter-from,
.banner-slide-leave-to {
  transform: translateY(-100%);
  opacity: 0;
}
</style>
```

**Nota sobre la key de localStorage:** `pinia-plugin-persistedstate` usa el id del store como key por defecto. El store tiene id `'vet'`, por lo que la key en localStorage es `'vet'`. El valor es el JSON serializado de solo los campos en `pick` (en este caso `{ lastVisitedSlug: 'clinica-san-marcos' }`). El `handleStorageEvent` lee este JSON para comparar.

---

#### `front/src/components/shared/VetSwitcher.vue` — CREAR

**Propósito:** Dropdown en el header del layout tenant que muestra la vet activa y permite cambiar a otra. Solo visible si el usuario tiene 2+ vets.

```vue
<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useVetStore } from '@/stores/vet.store'
import { SwapOutlined } from '@ant-design/icons-vue'

const route    = useRoute()
const router   = useRouter()
const vetStore = useVetStore()

// Solo mostrar si hay 2+ vets
const shouldShow = computed(() => vetStore.userVets.length >= 2)

const currentSlug = computed(() => route.params.vetSlug as string)

const currentVetItem = computed(() =>
  vetStore.userVets.find(v => v.slug === currentSlug.value) ?? null
)

const otherVets = computed(() =>
  vetStore.userVets.filter(v => v.slug !== currentSlug.value)
)

function switchToVet(targetSlug: string): void {
  if (targetSlug === currentSlug.value) return

  // Limpiar el contexto actual (no los userVets ni lastVisitedSlug)
  vetStore.clearCurrentVet()

  // Calcular el path relativo y redirigir al mismo path bajo el nuevo slug
  // Ejemplo: /vets/vet-a/perfil → /vets/vet-b/perfil
  const currentPath = route.path                                 // /vets/vet-a/perfil
  const relPath = currentPath.replace(`/vets/${currentSlug.value}`, '') // /perfil
  const newPath = `/vets/${targetSlug}${relPath || ''}`          // /vets/vet-b/perfil

  router.push(newPath)
}
</script>

<template>
  <div v-if="shouldShow" class="vet-switcher">
    <a-dropdown trigger="click" placement="bottomRight">
      <button class="vet-switcher__trigger" :title="`Cambiar veterinaria (${currentVetItem?.name ?? '...'})`">
        <span class="vet-switcher__name">{{ currentVetItem?.name ?? '...' }}</span>
        <SwapOutlined class="vet-switcher__icon" />
      </button>
      <template #overlay>
        <a-menu>
          <a-menu-item
            v-for="vet in otherVets"
            :key="vet.slug"
            @click="switchToVet(vet.slug)"
          >
            <div class="vet-switcher__option">
              <img
                v-if="vet.logo_path"
                :src="vet.logo_path"
                :alt="vet.name"
                class="vet-switcher__option-logo"
              />
              <span v-else class="vet-switcher__option-avatar">
                {{ vet.name.slice(0, 2).toUpperCase() }}
              </span>
              <div class="vet-switcher__option-info">
                <span class="vet-switcher__option-name">{{ vet.name }}</span>
                <span class="vet-switcher__option-role">{{ vet.role.name }}</span>
              </div>
            </div>
          </a-menu-item>
        </a-menu>
      </template>
    </a-dropdown>
  </div>
</template>

<style scoped>
.vet-switcher__trigger {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  background: none;
  border: 1px solid var(--dt-border, rgba(26,229,160,0.12));
  border-radius: 8px;
  padding: 6px 12px;
  color: var(--dt-text, #C8E2EF);
  font-size: 13px;
  cursor: pointer;
  transition: border-color 0.15s;
}
.vet-switcher__trigger:hover {
  border-color: var(--dt-accent, #1AE5A0);
}
.vet-switcher__icon {
  font-size: 12px;
  color: var(--dt-muted, #6B8CAE);
}
.vet-switcher__option {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 4px 0;
}
.vet-switcher__option-logo {
  width: 28px;
  height: 28px;
  border-radius: 6px;
  object-fit: cover;
}
.vet-switcher__option-avatar {
  width: 28px;
  height: 28px;
  border-radius: 6px;
  background: var(--dt-accent, #1AE5A0);
  color: #000;
  font-size: 11px;
  font-weight: 700;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}
.vet-switcher__option-info {
  display: flex;
  flex-direction: column;
  gap: 1px;
}
.vet-switcher__option-name {
  font-size: 13px;
  color: var(--dt-text, #C8E2EF);
  font-weight: 500;
}
.vet-switcher__option-role {
  font-size: 11px;
  color: var(--dt-muted, #6B8CAE);
}
</style>
```

---

#### `front/src/components/layouts/VetTenantLayout.vue` — CREAR

**Propósito:** Layout que envuelve todas las rutas `/vets/:vetSlug/*`. Orquesta la carga del contexto tenant vía `useVetTenant()`, muestra skeleton mientras carga, e incluye el `VetSwitcher` en el header y el `VetChangedBanner`.

```vue
<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { APP_NAME } from '@/core/constants/app'
import { useTheme } from '@/core/composables/useTheme'
import { useSidebar } from '@/core/composables/useSidebar'
import { MenuOutlined } from '@ant-design/icons-vue'
import AppSidebar from '@/components/layouts/partials/AppSidebar.vue'
import NotificationBell from '@/modules/notifications/components/NotificationBell.vue'
import AppUserMenu from '@/components/layouts/partials/AppUserMenu.vue'
import ConfirmDialog from '@/components/shared/ConfirmDialog.vue'
import VetSwitcher from '@/components/shared/VetSwitcher.vue'
import VetChangedBanner from '@/components/shared/VetChangedBanner.vue'
import { useVetTenant } from '@/modules/vets/composables/useVetTenant'
import { useUserVets } from '@/modules/vets/composables/useUserVets'

const route = useRoute()
const { dashTheme, isLight, palette } = useTheme()
const { collapsed } = useSidebar()

const pageTitle = computed(() => (route.meta.title as string | undefined) ?? APP_NAME)

// Cargar lista de vets del usuario (para el switcher)
// useUserVets también popula vetStore.userVets vía el onSuccess del queryFn
useUserVets()

// Orquestar contexto tenant: carga vet activa, popula store, maneja errores
const { isLoading } = useVetTenant()
</script>

<template>
  <a-config-provider :theme="dashTheme">
    <div class="dash-root" :class="[{ light: isLight }, `palette-${palette}`]">

      <VetChangedBanner />

      <Transition name="dash-overlay">
        <div v-if="!collapsed" class="dash-overlay" @click="collapsed = true" />
      </Transition>

      <AppSidebar v-model:collapsed="collapsed" />

      <div class="dash-main">
        <header class="dash-header">
          <button class="dash-menu-btn" title="Menú" @click="collapsed = !collapsed">
            <MenuOutlined />
          </button>
          <h1 class="dash-header-title">{{ pageTitle }}</h1>
          <div class="dash-header-right">
            <VetSwitcher />
            <NotificationBell />
            <AppUserMenu />
          </div>
        </header>

        <main class="dash-content">
          <!-- Skeleton mientras carga el contexto tenant -->
          <template v-if="isLoading">
            <div class="vet-layout-skeleton">
              <a-skeleton active :paragraph="{ rows: 4 }" />
            </div>
          </template>

          <!-- Contenido normal una vez que la vet está cargada -->
          <RouterView v-else />
        </main>
      </div>

      <ConfirmDialog />
    </div>
  </a-config-provider>
</template>

<style scoped>
/* Mismos estilos que AppLayout — copiar o extraer a un CSS compartido */
.dash-root {
  display: flex;
  min-height: 100vh;
  background: var(--dt-bg, #07111F);
}

.dash-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.5);
  z-index: 99;
}

.dash-main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}

.dash-header {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 0 24px;
  height: 56px;
  border-bottom: 1px solid var(--dt-border, rgba(26,229,160,0.12));
  background: var(--dt-card, #0E2038);
  position: sticky;
  top: 0;
  z-index: 10;
}

.dash-menu-btn {
  background: none;
  border: none;
  color: var(--dt-muted, #6B8CAE);
  font-size: 16px;
  cursor: pointer;
  padding: 4px;
  border-radius: 4px;
  transition: color 0.15s;
}
.dash-menu-btn:hover { color: var(--dt-text, #C8E2EF); }

.dash-header-title {
  flex: 1;
  font-family: 'Syne', sans-serif;
  font-size: 16px;
  font-weight: 700;
  color: var(--dt-title, #fff);
  margin: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.dash-header-right {
  display: flex;
  align-items: center;
  gap: 8px;
}

.dash-content {
  flex: 1;
  padding: 24px;
  overflow-y: auto;
}

.vet-layout-skeleton {
  max-width: 800px;
  padding: 8px;
}
</style>
```

**Notas:**
- El layout reutiliza `AppSidebar` que ya tiene `VetMenu` activado para rutas `/vets/`.
- `useUserVets()` se llama aquí para que la lista esté disponible desde el primer render del switcher.
- `useVetTenant()` se llama para cargar el perfil completo de la vet y popularlo en el store.
- Si `isLoading` es `true`, se muestra skeleton y no el `RouterView` (evita que las páginas hijas accedan a `vetStore.currentVet` antes de que esté disponible).

---

### Paso 7: Rutas y router

---

#### `front/src/modules/vets/router/vets-tenant.routes.ts` — CREAR

**Propósito:** Rutas del panel tenant bajo `VetTenantLayout` con guard de acceso.

```typescript
import type { RouteRecordRaw } from 'vue-router'
import { vetTenantGuard } from '@/router/guards/vetTenantGuard'

export const vetsTenantRoutes: RouteRecordRaw[] = [
  {
    path: '/vets/:vetSlug',
    component: () => import('@/components/layouts/VetTenantLayout.vue'),
    beforeEnter: vetTenantGuard,
    children: [
      {
        path: '',
        redirect: to => `/vets/${to.params.vetSlug}/perfil`,
      },
      {
        path: 'perfil',
        name: 'vet-tenant-perfil',
        component: () => import('@/modules/vets/pages/tenant/VetProfilePage.vue'),
        meta: { requiresAuth: true, title: 'Perfil de la veterinaria' },
      },
    ],
  },
]
```

**Notas:**
- La ruta raíz `/vets/:vetSlug` redirige a `/perfil` (primer página funcional).
- El `name: 'vet-tenant-perfil'` no colisiona con ningún nombre existente (las rutas admin usan `vets-detail`, `vets-list`, etc.).
- Las rutas existentes `/vets/:vetSlug/clients/*` siguen bajo `AppLayout` y el guard `authGuard` general. No se mueven — mantienen compatibilidad.

---

#### `front/src/router/index.ts` — MODIFICAR

**Cambio:** Importar `vetsTenantRoutes` y registrarlas como un grupo de rutas independiente (NO como children de `AppLayout`).

**Antes (resumido):**
```typescript
import { vetsRoutes } from '@/modules/vets/router/vets.routes'
import { clientsRoutes } from '@/modules/clients/router/clients.routes'
// ...
const routes = [
    {
        path: '/auth',
        component: () => import('@/layouts/AuthLayout.vue'),
        children: authRoutes,
    },
    {
        path: '/',
        component: () => import('@/layouts/AppLayout.vue'),
        beforeEnter: authGuard,
        children: [
            ...dashboardRoutes,
            ...vetsRoutes,
            ...clientsRoutes,
            // ...
        ],
    },
    // ...
]
```

**Después:**
```typescript
import { vetsTenantRoutes } from '@/modules/vets/router/vets-tenant.routes'
// ... (mantener todos los imports existentes)

const routes = [
    {
        path: '/auth',
        component: () => import('@/layouts/AuthLayout.vue'),
        children: authRoutes,
    },
    {
        path: '/',
        component: () => import('@/layouts/AppLayout.vue'),
        beforeEnter: authGuard,
        children: [
            ...dashboardRoutes,
            ...usersRoutes,
            ...rolesRoutes,
            ...settingsRoutes,
            ...supportMessagesRoutes,
            ...systemSettingsRoutes,
            ...vetsRoutes,
            ...clientsRoutes,
            ...adminClientsRoutes,
        ],
    },
    // NUEVO: Rutas del panel tenant bajo VetTenantLayout (guard propio)
    // IMPORTANTE: authGuard sigue aplicando porque vetTenantGuard
    // asume usuario autenticado. Si el usuario no está autenticado, el
    // http interceptor lo redirige a /login cuando falla el GET /user/vets.
    // Para robustez, el grupo tenant también aplica authGuard.
    {
        path: '/',
        beforeEnter: authGuard,
        children: vetsTenantRoutes,
        // No tiene component propio — VetTenantLayout está en cada ruta hija
    },
    {
        path: '/change-expired-password',
        component: () => import('@/layouts/ForceChangePasswordLayout.vue'),
        beforeEnter: authGuard,
        children: [
            {
                path: '',
                name: 'change-expired-password',
                component: () => import('@/modules/auth/pages/ChangeExpiredPasswordPage.vue'),
                meta: { requiresAuth: true, title: 'Cambiar contraseña' },
            },
        ],
    },
    { path: '/:pathMatch(.*)*', redirect: '/dashboard' },
]
```

**Alternativa más simple (si Vue Router acepta group sin component):**
Vue Router v4 permite grupos de rutas (`RouteRecordRaw` con solo `path` y `children`, sin `component`) pero solo si `component` está omitido o si hay un `redirect`. El patrón correcto es montar `vetsTenantRoutes` directamente en el array de rutas (flat), ya que cada ruta hija tiene su propio component (`VetTenantLayout`):

```typescript
// Opción más simple: agregar las rutas tenant directamente al array de rutas
const routes = [
    // ... rutas existentes
    // Las vetsTenantRoutes ya tienen VetTenantLayout como component en el padre
    ...vetsTenantRoutes,
    { path: '/:pathMatch(.*)*', redirect: '/dashboard' },
]
```

**Decisión: usar la opción simple (spread directo).** `vetsTenantRoutes` ya tiene `component: VetTenantLayout` y `beforeEnter: vetTenantGuard`. Basta con hacer spread al nivel raíz. El `authGuard` que aplica actualmente al grupo `AppLayout` NO aplica automáticamente a este grupo nuevo — pero el `vetTenantGuard` llama a `fetchUserVets()` que requiere auth, por lo que un 401 redirigirá a `/login` vía el interceptor de `http.ts`. Para mayor robustez, componer ambos guards en `vetTenantGuard` o agregar `authGuard` como `beforeEnter` adicional en el grupo.

**Implementación final de `router/index.ts`:**

```typescript
import { createRouter, createWebHistory } from 'vue-router'
import { authRoutes } from '@/modules/auth/router/auth.routes'
import { usersRoutes } from '@/modules/users/router/users.routes'
import { rolesRoutes } from '@/modules/roles/router/roles.routes'
import { dashboardRoutes } from '@/modules/dashboard/router/dashboard.routes'
import { settingsRoutes } from '@/modules/settings/router/settings.routes'
import { supportMessagesRoutes } from '@/modules/support-messages/router/support-messages.routes'
import { systemSettingsRoutes } from '@/modules/system-settings/router/system-settings.routes'
import { vetsRoutes } from '@/modules/vets/router/vets.routes'
import { vetsTenantRoutes } from '@/modules/vets/router/vets-tenant.routes'
import { clientsRoutes } from '@/modules/clients/router/clients.routes'
import { adminClientsRoutes } from '@/modules/clients/router/admin-clients.routes'
import { authGuard } from './guards/auth.guard'
import { guestGuard } from './guards/guest.guard'

const routes = [
    {
        path: '/auth',
        component: () => import('@/layouts/AuthLayout.vue'),
        children: authRoutes,
    },
    {
        path: '/',
        component: () => import('@/layouts/AppLayout.vue'),
        beforeEnter: authGuard,
        children: [
            ...dashboardRoutes,
            ...usersRoutes,
            ...rolesRoutes,
            ...settingsRoutes,
            ...supportMessagesRoutes,
            ...systemSettingsRoutes,
            ...vetsRoutes,
            ...clientsRoutes,
            ...adminClientsRoutes,
        ],
    },
    // Panel tenant: rutas bajo VetTenantLayout con guard propio
    // authGuard también aplica aquí para seguridad defensiva
    {
        path: '/',
        beforeEnter: authGuard,
        children: vetsTenantRoutes,
    },
    {
        path: '/change-expired-password',
        component: () => import('@/layouts/ForceChangePasswordLayout.vue'),
        beforeEnter: authGuard,
        children: [
            {
                path: '',
                name: 'change-expired-password',
                component: () => import('@/modules/auth/pages/ChangeExpiredPasswordPage.vue'),
                meta: { requiresAuth: true, title: 'Cambiar contraseña' },
            },
        ],
    },
    { path: '/:pathMatch(.*)*', redirect: '/dashboard' },
]

export const router = createRouter({
    history: createWebHistory(),
    routes,
})

router.beforeEach((to, from) => {
    if (to.meta?.requiresGuest) {
        return guestGuard(to, from, () => undefined)
    }
    return true
})
```

---

### Paso 8: Página VetProfilePage

---

#### Directorio — crear subdirectorio

Crear el directorio `front/src/modules/vets/pages/tenant/` (no existe actualmente).

---

#### `front/src/modules/vets/pages/tenant/VetProfilePage.vue` — CREAR

**Propósito:** Página de solo lectura del perfil de la veterinaria activa. Consume `vetStore.currentVet` que ya está populado por `VetTenantLayout` antes de renderizar esta página.

```vue
<script setup lang="ts">
import { computed } from 'vue'
import { useVetStore } from '@/stores/vet.store'
import { formatDate } from '@/core/utils/date'
import VetStatusBadge from '@/modules/vets/components/VetStatusBadge.vue'

const vetStore = useVetStore()
const vet = computed(() => vetStore.currentVet)

// Iniciales para el avatar placeholder
const initials = computed(() => {
  if (!vet.value?.name) return '??'
  return vet.value.name
    .split(' ')
    .slice(0, 2)
    .map(w => w[0].toUpperCase())
    .join('')
})
</script>

<template>
  <div class="vp-root">
    <!-- El layout garantiza que currentVet está disponible antes de renderizar -->
    <template v-if="vet">
      <!-- Encabezado del perfil -->
      <div class="vp-header">
        <div class="vp-avatar-wrap">
          <img
            v-if="vet.logo_path"
            :src="vet.logo_path"
            :alt="vet.name"
            class="vp-logo"
          />
          <div v-else class="vp-avatar-placeholder">
            {{ initials }}
          </div>
        </div>
        <div class="vp-title-block">
          <h2 class="vp-title">{{ vet.name }}</h2>
          <p class="vp-slug">{{ vet.slug }}</p>
          <VetStatusBadge
            :validated_at="vet.validated_at"
            :suspended_at="vet.suspended_at"
          />
        </div>
      </div>

      <!-- Grid de datos -->
      <div class="vp-grid">
        <!-- Datos fiscales -->
        <div class="vp-card">
          <h3 class="vp-card-title">Datos fiscales</h3>
          <dl class="vp-dl">
            <dt>País</dt>
            <dd>{{ vet.country?.name ?? '—' }}</dd>

            <dt>Tipo de documento</dt>
            <dd>{{ vet.document_type?.name ?? '—' }}</dd>

            <dt>Identificador fiscal</dt>
            <dd>{{ vet.tax_id }}</dd>

            <dt>Número de matrícula</dt>
            <dd>{{ vet.registration_number ?? '—' }}</dd>
          </dl>
        </div>

        <!-- Estado y fechas -->
        <div class="vp-card">
          <h3 class="vp-card-title">Estado y fechas</h3>
          <dl class="vp-dl">
            <dt>Fecha de alta</dt>
            <dd>{{ formatDate(vet.created_at) }}</dd>

            <dt>Validada el</dt>
            <dd>{{ vet.validated_at ? formatDate(vet.validated_at) : 'Sin validar' }}</dd>

            <dt>Validada por</dt>
            <dd>{{ vet.validated_by?.name ?? '—' }}</dd>

            <dt>Suspendida el</dt>
            <dd>{{ vet.suspended_at ? formatDate(vet.suspended_at) : '—' }}</dd>
          </dl>
        </div>

        <!-- Personalización de documentos -->
        <div class="vp-card">
          <h3 class="vp-card-title">Documentos</h3>
          <dl class="vp-dl">
            <dt>Título del PDF</dt>
            <dd>{{ vet.pdf_title ?? '—' }}</dd>

            <dt>Subtítulo del PDF</dt>
            <dd>{{ vet.pdf_subtitle ?? '—' }}</dd>
          </dl>
        </div>

        <!-- Contactos -->
        <div v-if="vet.contacts && vet.contacts.length > 0" class="vp-card vp-card--full">
          <h3 class="vp-card-title">Contactos</h3>
          <div class="vp-contacts">
            <div
              v-for="contact in vet.contacts"
              :key="contact.guid"
              class="vp-contact-item"
            >
              <span class="vp-contact-type">{{ contact.type }}</span>
              <span class="vp-contact-value">{{ contact.value }}</span>
              <span v-if="contact.label" class="vp-contact-label">{{ contact.label }}</span>
              <span v-if="contact.is_primary" class="vp-contact-badge">Principal</span>
            </div>
          </div>
        </div>
      </div>
    </template>

    <!-- Fallback: no debería mostrarse porque el layout lo previene -->
    <div v-else class="vp-empty">
      Cargando perfil...
    </div>
  </div>
</template>

<style scoped>
.vp-root {
  max-width: 960px;
}

.vp-header {
  display: flex;
  align-items: flex-start;
  gap: 20px;
  margin-bottom: 28px;
  flex-wrap: wrap;
}

.vp-avatar-wrap {
  flex-shrink: 0;
}

.vp-logo {
  width: 72px;
  height: 72px;
  border-radius: 16px;
  object-fit: cover;
  border: 1px solid var(--dt-border, rgba(26,229,160,0.12));
}

.vp-avatar-placeholder {
  width: 72px;
  height: 72px;
  border-radius: 16px;
  background: var(--dt-accent, #1AE5A0);
  color: #000;
  font-family: 'Syne', sans-serif;
  font-size: 22px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
}

.vp-title-block {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.vp-title {
  font-family: 'Syne', sans-serif;
  font-size: 24px;
  font-weight: 700;
  color: var(--dt-title, #fff);
  margin: 0;
}

.vp-slug {
  font-size: 12px;
  color: var(--dt-muted, #6B8CAE);
  margin: 0;
}

.vp-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 16px;
}

.vp-card {
  background: var(--dt-card, #0E2038);
  border: 1px solid var(--dt-border, rgba(26,229,160,0.12));
  border-radius: 16px;
  padding: 20px 24px;
}

.vp-card--full {
  grid-column: 1 / -1;
}

.vp-card-title {
  font-family: 'Syne', sans-serif;
  font-size: 14px;
  font-weight: 700;
  color: var(--dt-title, #fff);
  margin: 0 0 16px;
}

.vp-dl {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 8px 16px;
  margin: 0;
}

.vp-dl dt {
  font-size: 12px;
  font-weight: 600;
  color: var(--dt-muted, #6B8CAE);
  white-space: nowrap;
}

.vp-dl dd {
  font-size: 13px;
  color: var(--dt-text, #C8E2EF);
  margin: 0;
  word-break: break-word;
}

.vp-contacts {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.vp-contact-item {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 13px;
  color: var(--dt-text, #C8E2EF);
  flex-wrap: wrap;
}

.vp-contact-type {
  text-transform: capitalize;
  font-weight: 600;
  color: var(--dt-muted, #6B8CAE);
  font-size: 12px;
  min-width: 70px;
}

.vp-contact-label {
  color: var(--dt-muted, #6B8CAE);
  font-size: 12px;
}

.vp-contact-badge {
  font-size: 11px;
  background: rgba(26,229,160,0.15);
  color: var(--dt-accent, #1AE5A0);
  border-radius: 4px;
  padding: 1px 6px;
}

.vp-empty {
  font-size: 13px;
  color: var(--dt-muted, #6B8CAE);
  padding: 20px 0;
}
</style>
```

**Notas:**
- La página NO tiene botón de editar (DEC-NEG-07 del ticket).
- `VetStatusBadge` ya existe en `front/src/modules/vets/components/VetStatusBadge.vue` con props `validated_at` y `suspended_at`. Se reutiliza directamente.
- `formatDate` importado de `@/core/utils/date`.
- El componente asume que `vetStore.currentVet` ya está populado cuando el `RouterView` renderiza esta página (el layout garantiza esto mediante el `v-if="!isLoading"` en `VetTenantLayout`).

---

### Tests a generar — Frontend

**Unit — `vetTenantGuard`**
- `test_redirects_to_dashboard_if_slug_missing`: navegar sin `vetSlug` en params → redirect `/dashboard`.
- `test_redirects_to_dashboard_if_vet_not_in_user_list`: slug no encontrado en `vetStore.userVets` → redirect `/dashboard` + notifyError.
- `test_passes_if_slug_is_in_user_vets`: slug presente en `vetStore.userVets` → retorna `true`.
- `test_fetches_user_vets_if_store_is_empty`: `vetStore.userVets = []` → llama a `fetchUserVets()`, popula el store, luego evalúa.
- `test_redirects_if_fetch_fails`: `fetchUserVets()` lanza error → redirect `/dashboard` + notifyError.

**Unit — `useVetStore`**
- `test_setCurrentVet_updates_all_fields`: llamar `setCurrentVet(vet, profile)` popula `currentVet`, `vetSlug`, `currentProfile`, `lastVisitedSlug`.
- `test_clearCurrentVet_preserves_userVets`: `clearCurrentVet()` limpia vet/slug/profile pero deja `userVets` intacto.
- `test_clearAll_resets_everything`: `clearAll()` limpia todos los campos incluyendo `userVets` y `lastVisitedSlug`.
- `test_lastVisitedSlug_persists_in_localStorage`: después de `setCurrentVet`, verificar que `localStorage.getItem('vet')` contiene `lastVisitedSlug`.
- `test_currentVet_not_persisted`: después de reload simulado, `currentVet` debe ser null.

**Unit — `usePermission.canInTenant`**
- `test_canInTenant_returns_true_for_matching_role`: `currentProfile.role.name = 'vet'`, `canInTenant('vet')` → `true`.
- `test_canInTenant_returns_false_for_non_matching_role`: `currentProfile.role.name = 'vet-assistant'`, `canInTenant('vet')` → `false`.
- `test_canInTenant_returns_false_when_no_profile`: `currentProfile = null`, `canInTenant('vet')` → `false`.
- `test_canInTenant_accepts_array_of_roles`: `canInTenant(['vet', 'vet-assistant'])` con rol `'vet-assistant'` → `true`.
- `test_existing_can_function_unaffected`: verificar que `can('some.permission')` sigue funcionando sin cambios.

---

## Orden de implementación

### Backend (prerequisito — implementar primero)

1. Crear `back/app/Services/UserVetService.php`.
2. Crear `back/app/Http/Resources/V1/UserVetResource.php`.
3. Crear `back/app/Http/Controllers/V1/UserVetController.php`.
4. Crear `back/routes/api/user.php`.
5. Verificar que el endpoint responde correctamente: `GET /v1/user/vets` con Bearer token válido de un usuario con vets asignadas.
6. Escribir y correr tests de backend (`UserVetControllerTest`).

### Frontend — infraestructura base

7. Crear `front/src/core/types/vet-context.types.ts`.
8. Crear `front/src/modules/vets/types/user-vet.types.ts`.
9. Crear `front/src/modules/vets/api/user-vets.api.ts`.
10. Modificar `front/src/modules/vets/api/vets.api.ts` — agregar `fetchVetBySlug(slug)`.
11. Modificar `front/src/stores/vet.store.ts` — ampliar store con campos nuevos y persistencia parcial.
    - Antes de modificar, buscar todos los callers de `useVetStore()` con `setCurrentVet` en el proyecto para actualizar la firma.
12. Modificar `front/src/core/composables/usePermissions.ts` — agregar `canInTenant()`.

### Frontend — composables tenant

13. Crear `front/src/modules/vets/composables/useUserVets.ts`.
14. Crear `front/src/modules/vets/composables/useVetProfile.ts`.
15. Crear `front/src/modules/vets/composables/useVetTenant.ts`.

### Frontend — guard

16. Crear `front/src/router/guards/vetTenantGuard.ts`.

### Frontend — componentes shared y layout

17. Crear `front/src/components/shared/VetChangedBanner.vue`.
18. Crear `front/src/components/shared/VetSwitcher.vue`.
19. Crear `front/src/components/layouts/VetTenantLayout.vue`.

### Frontend — rutas y página

20. Crear directorio `front/src/modules/vets/pages/tenant/`.
21. Crear `front/src/modules/vets/pages/tenant/VetProfilePage.vue`.
22. Crear `front/src/modules/vets/router/vets-tenant.routes.ts`.
23. Modificar `front/src/router/index.ts` — agregar grupo de rutas tenant.

### Verificación

24. Verificar compilación TypeScript: `cd front && npx tsc --noEmit`.
25. Probar manualmente: navegar a `/vets/{slug-real}/perfil` con usuario autenticado que tiene vet asignada.
26. Probar guard: intentar `/vets/slug-inexistente/perfil` → debe redirigir a `/dashboard` con toast.
27. Probar switcher: con usuario de 2+ vets, verificar que el switcher aparece y redirige correctamente.
28. Probar multi-pestaña: abrir en dos pestañas, cambiar vet en una, verificar banner en la otra.
29. Escribir y correr tests de frontend.

---

## Riesgos y consideraciones

**R01 — Callers existentes de `setCurrentVet(vet)` con firma vieja (sin profile):**
El store actual tiene `setCurrentVet(vet: VetBasic)`. Al cambiar la firma a `setCurrentVet(vet: VetItem, profile: VetUserProfile)`, cualquier caller existente que no pase `profile` fallará en compilación TypeScript. Buscar en el proyecto con grep antes del paso 11. Actualmente no parece haber callers ya que el store fue definido pero la hidratación del mismo no fue implementada (R01 del plan anterior lo documentó como deuda pendiente).

**R02 — Conflicto de rutas: `/vets/:vetSlug/clients/*` bajo `AppLayout` y nuevas rutas bajo `VetTenantLayout`:**
Las rutas de clientes (`/vets/:vetSlug/clients/*`) actualmente están bajo `AppLayout` y NO tienen `vetTenantGuard`. El nuevo grupo de rutas bajo `VetTenantLayout` cubre `/vets/:vetSlug/perfil`. Las rutas de clientes NO se mueven en este ticket para no romperlas. En un ticket futuro, las rutas de clientes deben migrarse a `VetTenantLayout` y el guard debe aplicarse también a ellas. Documentar como deuda.

**R03 — `UserVetResource` usa `$this->authenticatable` (eager loading obligatorio):**
Si el Service no carga `authenticatable` con eager loading, `$this->authenticatable` será `null` y el resource fallará con `TypeError`. El Service usa `->with(['role', 'authenticatable'])` — verificar que este eager loading esté presente en la implementación final.

**R04 — `useVetTenant` arma `VetUserProfile` con `guid` de la Vet (no del UserProfile):**
El `UserVetItem` retornado por `GET /v1/user/vets` tiene `guid` de la Vet. El `VetUserProfile` en el store tiene un campo `guid` que conceptualmente debería ser el del `UserProfile`. En este ticket esto no causa problemas funcionales (el guid no se usa para nada en la UI). Si en un ticket futuro se necesita el guid del `UserProfile` (ej: para editar el propio perfil dentro de la vet), extender `UserVetResource` para incluir `profile_guid`. Documentar como deuda.

**R05 — `vetTenantGuard` no distingue 404 vs 403 en el slug:**
El guard compara el slug contra la lista local de `userVets`. Si el slug no está en la lista, puede ser porque: (a) no existe en el sistema, (b) el usuario no tiene acceso. El mensaje de toast es genérico. La distinción precisa ocurre en `useVetTenant()` cuando hace la llamada al servidor y recibe 404 vs 403 del middleware. El flujo completo da la información correcta, pero el primer mensaje (del guard) es genérico.

**R06 — Multi-tenant: el endpoint `GET /v1/user/vets` filtra por `user_id = auth()->id()`:**
La query en `UserVetService` filtra explícitamente por `user_id = $user->id`. No hay riesgo de cross-tenant porque cada usuario solo ve sus propios `UserProfile`. Sin embargo, el dev debe verificar que no haya manera de manipular la request para inyectar otro `user_id`. Como se usa `$request->user()` que proviene del guard de autenticación, esto es seguro.

**R07 — `lastVisitedSlug` en localStorage puede apuntar a una vet a la que el usuario ya no tiene acceso:**
Si el usuario fue removido de una vet entre sesiones, `lastVisitedSlug` podría apuntar a una vet que ya no está en `userVets`. El guard maneja esto correctamente: verifica que el slug esté en `userVets` antes de permitir acceso. La lógica de redirect inicial (ir a `lastVisitedSlug` o a pantalla de selección) queda fuera de scope de este ticket.

**R08 — `VetChangedBanner` depende de la key de localStorage del store:**
La key es `'vet'` (id del defineStore). Si se cambia el id del store en el futuro, el banner dejaría de detectar cambios. Es un acoplamiento implícito. Alternativa más robusta: usar un BroadcastChannel API para comunicación entre pestañas, sin depender de la key de localStorage. Para el MVP, el evento `storage` es suficiente.

**R09 — `VetTenantLayout` duplica estilos de `AppLayout`:**
Los estilos `.dash-root`, `.dash-main`, `.dash-header`, etc. están duplicados. La deuda técnica es extraer estos estilos a un CSS compartido o a un layout base abstracto. Documentar como deuda para no perder tiempo en este ticket.

**R10 — El campo `contacts` en `VetItem` puede estar ausente:**
El `VetController::show()` hace `$vet->load(['country', 'documentType', 'contacts'])` pero NO carga `validatedBy`. En `VetProfilePage`, el campo `validated_by.name` mostrará `—` siempre porque la relación no viene cargada desde el endpoint tenant. Opciones: (a) agregar `'validatedBy'` al load del `VetController::show()`, (b) aceptar `—` en la UI. Recomendado: agregar `'validatedBy'` al load — es un cambio mínimo con alto valor de UX. El dev puede hacerlo durante la implementación sin necesidad de otro ticket.

---

## Pendientes / fuera de alcance

- **Redirect inteligente desde `/` o `/dashboard`**: lógica de redirigir automáticamente al `lastVisitedSlug` si el usuario tiene una sola vet, o a pantalla de selección si tiene múltiples. Ticket futuro.
- **Migración de rutas de clientes a `VetTenantLayout`**: las rutas `/vets/:vetSlug/clients/*` actualmente bajo `AppLayout` deben migrarse al nuevo layout y guard. Ticket futuro.
- **Edición del perfil de la vet**: `PUT /v1/vets/{slug}` ya existe en el backend. El formulario de edición en el panel tenant queda fuera de este ticket (DEC-NEG-07).
- **`VetMenu` ampliado**: agregar ítem "Perfil" a la navegación lateral `VetMenu.vue`. Actualmente solo tiene "Clientes". Puede hacerse como micro-tarea dentro de este ticket o en un ticket posterior.
- **Páginas adicionales del panel tenant**: staff, protocolos, plan sanitario. Tickets posteriores.
- **Pantalla de selección de veterinaria**: para usuarios con múltiples vets sin `lastVisitedSlug`. Ticket futuro.
- **Tests de integración E2E**: Cypress/Playwright para los flujos de guard, switcher y multi-pestaña. Fuera de scope.
- **`profile_guid` en `UserVetItem`**: incluir el guid del `UserProfile` en el resource para casos de uso futuros (ver R04).
- **Ajuste de `validatedBy` en `VetController::show()`**: agregar `'validatedBy'` al load (micro-tarea dentro de este ticket, baja complejidad).
