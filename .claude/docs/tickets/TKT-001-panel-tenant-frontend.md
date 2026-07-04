# TKT-001 - Panel Tenant Frontend: Infraestructura de navegación multi-vet y página de perfil

## Tipo
Feature Compleja — Infraestructura + página de perfil (solo lectura)

## Contexto
El sistema SAV es multi-tenant: un usuario puede pertenecer a 0, 1 o 2+ veterinarias simultáneamente. Hoy el frontend solo tiene el panel superadmin (`/admin/vets/`) para gestionar veterinarias desde afuera. No existe ninguna superficie de navegación que permita al usuario autenticado entrar al contexto de UNA veterinaria y operar dentro de ella.

Este ticket implementa la infraestructura de navegación tenant-scoped (rutas `/vets/:vetSlug/*`, store de vet activa ampliado, guard de acceso, layout VetTenantLayout, switcher de veterinaria en el header) más la primera página funcional del panel tenant: el perfil de la veterinaria en modo solo lectura.

El objetivo operativo es que un `vet` o `vet-assistant` pueda seleccionar su veterinaria desde un selector en el header, navegar a `/vets/:vetSlug/`, y ver el perfil de la veterinaria con los datos retornados por `GET /v1/vets/{vet}`.

## Estado actual
- Módulo `front/src/modules/vets/` existe pero solo cubre el panel superadmin (`/admin/vets/*`).
- `front/src/stores/vet.store.ts` existe con firma básica (`currentVet`, `vetSlug`, `setCurrentVet`, `clearCurrentVet`) — definido en el plan `frontend-modules-vets-staff-clients-plan.md`.
- `usePermission()` en `front/src/core/composables/usePermissions.ts` cubre únicamente permisos Spatie de plataforma.
- El router no tiene rutas `/vets/:vetSlug/*`.
- No existe endpoint `GET /v1/user/vets` (ni similar) para listar las veterinarias asignadas al usuario autenticado. Sin este endpoint el selector no puede popularse. Este es el ÚNICO cambio backend requerido.
- El middleware `EnsureUserBelongsToVet` ya existe y funciona: resuelve la vet por slug, verifica el `UserProfile` del usuario autenticado para esa vet, e inyecta `current_vet` y `current_profile` en el request.

---

## Prerequisito backend (BLOQUEANTE)

### PREREQ-01 — Endpoint `GET /v1/user/vets`
El frontend necesita conocer qué veterinarias tiene asignadas el usuario autenticado para poblar el selector del header. Este endpoint NO existe.

**Contrato esperado:**
```
GET /v1/user/vets
Auth: Bearer {token}

Response 200:
{
  "success": true,
  "data": [
    {
      "guid": "...",
      "name": "Clínica San Marcos",
      "slug": "clinica-san-marcos",
      "logo_path": null | "https://...",
      "is_active": true,
      "role": {
        "name": "vet"
      }
    }
  ]
}
```

**Notas para el arquitecto backend:**
- La query debe ir sobre `user_profiles` WHERE `user_id = auth()->id()` AND `authenticatable_type = 'vet'`, JOIN con `vets`.
- Solo se deben retornar vets donde `vets.suspended_at IS NULL` y `vets.validated_at IS NOT NULL` (is_active = true). Una vet inactiva no debe aparecer en el selector.
- Si el usuario no tiene ninguna vet asignada, retornar array vacío (no 404).
- El rol retornado es el `role.name` del `UserProfile` correspondiente, no los roles Spatie del usuario.
- Este endpoint debe estar en `routes/api/user.php` (o crear el archivo) bajo middleware `auth:sanctum`. NO bajo middleware `vet.tenant` — porque el usuario todavía no tiene un slug activo cuando consulta la lista.
- Identificador de ruta: usar el `guid` de la vet, nunca el id interno.

---

## Decisiones tomadas (no negociables)

### DEC-NEG-01 — Selección de vet activa: URL como fuente de verdad, localStorage para redirect inicial
El slug de la vet activa vive en la URL (`/vets/:vetSlug/*`). El store Pinia (`useVetStore`) es un cache de los datos cargados para ese slug, NO la fuente de verdad.

localStorage persiste el ÚLTIMO slug visitado únicamente para resolver el redirect inicial: cuando el usuario navega a `/dashboard` o a `/`, si tiene una sola vet asignada se le redirige automáticamente a `/vets/:slug/`. Si tiene múltiples, se lo redirige al slug del localStorage (si aún tiene acceso) o a una pantalla de selección.

Al cambiar de vet vía el switcher, el store se limpia y se recarga para el nuevo slug.

### DEC-NEG-02 — Permisos tenant: extensión de `usePermission()` con contexto del vetStore
`usePermission()` se extiende para aceptar un segundo contexto opcional: `'platform'` (Spatie, comportamiento actual) o `'tenant'` (roles del `UserProfile` de la vet activa).

La API externa del composable no cambia para los callers existentes. Internamente, cuando se llama con contexto `'tenant'`, consulta el `vetStore.currentProfile.role.name` para determinar si el usuario es `vet`, `vet-assistant` o `vet-administrative` en el contexto de la vet activa.

Los roles tenant válidos son: `vet`, `vet-assistant`, `vet-administrative`.

### DEC-NEG-03 — Layout multi-vet: switcher global en header + URL slug
Se crea `VetTenantLayout.vue` que envuelve todas las rutas `/vets/:vetSlug/*`. El header de este layout incluye un componente `VetSwitcher.vue` que muestra la vet activa y permite cambiar a otra. Al seleccionar otra vet, el switcher redirige al mismo path relativo en el nuevo slug (ej: si estoy en `/vets/vet-a/perfil`, el switcher redirige a `/vets/vet-b/perfil`).

El switcher se muestra solo si el usuario tiene 2+ vets activas asignadas. Con una sola vet no hay nada para switchear.

### DEC-NEG-04 — Carga de datos tenant: guard de acceso + Vue Query con suspense
El guard `vetTenantGuard` se ejecuta antes de entrar a cualquier ruta `/vets/:vetSlug/*`. Verifica que el slug existe en la lista de vets del usuario (cargada desde `GET /v1/user/vets`). Si no está, aplica la lógica de error (ver DEC-NEG-05).

Dentro del layout `VetTenantLayout.vue`, se usa `useQuery` para cargar `GET /v1/vets/{vetSlug}` y obtener los datos completos de la vet. Mientras carga, el layout muestra un skeleton. Cuando resuelve, popula el `vetStore`.

### DEC-NEG-05 — Errores de carga: redirect a /dashboard + toast específico
- **404** (la vet no existe en el sistema): redirect a `/dashboard` + toast de error "La veterinaria no existe".
- **403** (el usuario ya no tiene acceso a esa vet): redirect a `/dashboard` + toast de error "Ya no tenés acceso a esta veterinaria".
- **Vet inactiva** (suspendida o no validada): redirect a `/dashboard` + toast "Esta veterinaria no está disponible actualmente".

En todos los casos el `vetStore` se limpia y el localStorage del último slug se borra.

### DEC-NEG-06 — Multi-pestaña: banner de aviso + botón de recarga
Si el usuario cambia de vet en otra pestaña del navegador (detectado vía evento `storage` de localStorage), la pestaña actual muestra un banner no intrusivo en la parte superior: "Cambiaste de veterinaria en otra pestaña. [Recargar]". Al hacer clic en "Recargar", la página se recarga completamente. El banner NO redirige automáticamente — el usuario decide cuándo recargar.

### DEC-NEG-07 — Alcance inicial: infraestructura + página de perfil en solo lectura
Este ticket entrega:
1. Toda la infraestructura de navegación tenant (guard, layout, store ampliado, switcher, tipos, composables base).
2. Una sola página funcional: `VetProfilePage.vue` — perfil de la vet activa, solo lectura, con todos los campos de `VetItem` ya definidos en `vet.types.ts`.

No se implementa edición del perfil en este ticket (`PUT /v1/vets/{vet}` queda fuera de scope). Las páginas de protocolos, staff, clientes y planes sanitarios son tickets posteriores.

---

## Archivos a crear / modificar

### Backend (prerequisito)

| Archivo | Acción | Responsabilidad |
|---------|--------|-----------------|
| `back/routes/api/user.php` | Crear (o modificar si existe) | Ruta `GET /v1/user/vets` bajo `auth:sanctum` |
| `back/app/Http/Controllers/V1/UserVetController.php` | Crear | Controlador delgado; llama a servicio y retorna resource |
| `back/app/Http/Resources/V1/UserVetResource.php` | Crear | Resource: guid, name, slug, logo_path, is_active, role.name |
| `back/app/Services/UserVetService.php` | Crear | Lógica: obtener vets activas del usuario autenticado |

### Frontend — Store y tipos globales

| Archivo | Acción | Responsabilidad |
|---------|--------|-----------------|
| `front/src/stores/vet.store.ts` | Modificar | Ampliar con `userVets`, `currentProfile`, `lastVisitedSlug` (localStorage), lógica de persistencia de slug |
| `front/src/core/composables/usePermissions.ts` | Modificar | Agregar soporte para contexto `'tenant'` consultando `vetStore.currentProfile.role.name` |
| `front/src/core/types/vet-context.types.ts` | Crear | Tipos compartidos de contexto tenant: `VetContext`, `VetTenantRole`, `VetUserProfile` |

### Frontend — Módulo vets (nuevos subdirectorios y archivos)

| Archivo | Acción | Responsabilidad |
|---------|--------|-----------------|
| `front/src/modules/vets/api/user-vets.api.ts` | Crear | `fetchUserVets(): Promise<UserVetItem[]>` — llama a `GET /v1/user/vets` |
| `front/src/modules/vets/types/user-vet.types.ts` | Crear | `UserVetItem`, `VetTenantRole` |
| `front/src/modules/vets/composables/useUserVets.ts` | Crear | `useQuery` sobre `fetchUserVets`, usado por el guard y el switcher |
| `front/src/modules/vets/composables/useVetTenant.ts` | Crear | Composable principal del contexto tenant: carga vet activa, expone `vetData`, `isLoading`, `error`. Usado por `VetTenantLayout` |
| `front/src/modules/vets/composables/useVetProfile.ts` | Crear | `useQuery` para `GET /v1/vets/{slug}` — retorna `VetItem` completo |
| `front/src/modules/vets/pages/tenant/VetProfilePage.vue` | Crear | Perfil de la vet activa en solo lectura. Muestra todos los campos de `VetItem`. |
| `front/src/modules/vets/router/vets-tenant.routes.ts` | Crear | Rutas `/vets/:vetSlug/*` con `VetTenantLayout` como layout padre |

### Frontend — Layout y componentes compartidos

| Archivo | Acción | Responsabilidad |
|---------|--------|-----------------|
| `front/src/components/layouts/VetTenantLayout.vue` | Crear | Layout que envuelve `/vets/:vetSlug/*`. Header con `VetSwitcher`, nav lateral tenant, `RouterView`. Orquesta la carga de datos de la vet activa y muestra skeleton mientras carga. |
| `front/src/components/shared/VetSwitcher.vue` | Crear | Dropdown en header. Muestra nombre y logo de la vet activa. Lista las otras vets del usuario (solo si tiene 2+). Al seleccionar, redirige al mismo path relativo. Oculto si el usuario tiene 1 sola vet. |
| `front/src/components/shared/VetChangedBanner.vue` | Crear | Banner no intrusivo que escucha el evento `storage` de localStorage. Muestra "Cambiaste de veterinaria en otra pestaña. [Recargar]" si detecta cambio de slug. |

### Frontend — Router principal

| Archivo | Acción | Responsabilidad |
|---------|--------|-----------------|
| `front/src/router/index.ts` | Modificar | Registrar las rutas `/vets/:vetSlug/*` usando `vets-tenant.routes.ts` |
| `front/src/router/guards/vetTenantGuard.ts` | Crear | Guard que verifica que el slug del parámetro de ruta pertenece al usuario autenticado. Usa la lista cacheada de `useUserVets`. Redirige a `/dashboard` con toast si hay error de acceso. |

---

## Shape de stores, composables y tipos nuevos

### `useVetStore` (ampliado)

```typescript
interface VetStore {
  // Datos de la vet activa (cargados desde GET /v1/vets/{slug})
  currentVet: VetItem | null
  vetSlug: string | null

  // Perfil del usuario autenticado en la vet activa
  // (rol tenant: vet | vet-assistant | vet-administrative)
  currentProfile: VetUserProfile | null

  // Lista de todas las vets activas asignadas al usuario
  // (cargadas desde GET /v1/user/vets — se cachea en memoria)
  userVets: UserVetItem[]

  // Último slug visitado — persiste en localStorage para redirect inicial
  lastVisitedSlug: string | null

  // Acciones
  setCurrentVet(vet: VetItem, profile: VetUserProfile): void
  setUserVets(vets: UserVetItem[]): void
  clearCurrentVet(): void
  setLastVisitedSlug(slug: string): void
}
```

### `VetUserProfile` (tipo nuevo en `core/types/vet-context.types.ts`)

```typescript
export type VetTenantRole = 'vet' | 'vet-assistant' | 'vet-administrative'

export interface VetUserProfile {
  guid: string
  role: {
    name: VetTenantRole
  }
}
```

### `UserVetItem` (respuesta de `GET /v1/user/vets`)

```typescript
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

### `usePermission()` ampliado

```typescript
// Firma ACTUAL (no cambia para callers existentes):
usePermission(permission: string): { hasPermission: ComputedRef<boolean> }

// Nueva sobrecarga para contexto tenant:
usePermission(role: VetTenantRole | VetTenantRole[], context: 'tenant'): { hasPermission: ComputedRef<boolean> }

// Internamente: cuando context === 'tenant', consulta vetStore.currentProfile.role.name
// Si currentProfile es null, hasPermission === false
```

---

## Estructura de rutas Vue Router

```
/                              → redirect según lógica (ver DEC-NEG-01)
/dashboard                     → DashboardLayout (sin cambios)
/admin/vets/*                  → DashboardLayout > módulo admin vets (sin cambios)

/vets/:vetSlug                 → VetTenantLayout (guard: vetTenantGuard)
/vets/:vetSlug/perfil          → VetTenantLayout > VetProfilePage
```

El parámetro de ruta es `vetSlug` (no guid). El guard usa el slug para verificar acceso contra la lista de `userVets`.

Rutas futuras (fuera de scope de este ticket, se definen en tickets posteriores):
```
/vets/:vetSlug/staff           → VetTenantLayout > StaffPage
/vets/:vetSlug/clientes        → VetTenantLayout > ClientsPage
/vets/:vetSlug/protocolos/*    → VetTenantLayout > módulo protocolos
/vets/:vetSlug/plan-sanitario  → VetTenantLayout > HealthPlanPage
```

---

## Restricciones

- Toda consulta a endpoints bajo `/vets/:vetSlug/*` DEBE ir autenticada con Bearer token y pasar por el middleware `EnsureUserBelongsToVet` en el backend. El frontend NO puede asumir acceso a una vet sin que el backend lo valide.
- El slug se usa en la URL y en las llamadas API. Nunca usar el guid interno de la vet en las rutas del panel tenant (los endpoints backend ya usan slug por Route Key del modelo `Vet`).
- `useVetStore` NO persiste `currentVet` ni `currentProfile` en localStorage — son datos de sesión que se recargan con cada navegación. Solo `lastVisitedSlug` se persiste.
- `usePermission()` con contexto `'tenant'` NO evalúa permisos Spatie — evalúa el rol del `UserProfile`. Son dos sistemas ortogonales.
- La página `VetProfilePage` es solo lectura en este ticket. No se debe agregar ningún botón de edición aunque el usuario sea `vet`.
- El módulo admin `/admin/vets/*` NO debe verse afectado por ningún cambio de este ticket.
- Un usuario con rol Spatie `superadmin` puede no tener ningún `UserProfile` de vet. En ese caso, `userVets` retorna array vacío y el switcher no aparece.

---

## Investigación previa que el arquitecto debe hacer

1. Verificar el estado actual de `front/src/stores/vet.store.ts`: si ya fue creado por el plan `frontend-modules-vets-staff-clients-plan.md`, el arquitecto debe extenderlo sin romper la firma existente.
2. Verificar si `front/src/router/guards/` ya tiene archivos y si existe un patrón para guards async (el guard `vetTenantGuard` debe poder llamar a `GET /v1/user/vets` de forma async si no hay cache).
3. Revisar `front/src/core/composables/usePermissions.ts` para entender la firma exacta actual antes de extenderla.
4. Verificar si `routes/api/user.php` existe en el backend. Si no existe, crearlo. Si existe, revisar qué rutas tiene para no pisar nada.
5. Confirmar que el modelo `Vet` usa slug como `getRouteKeyName()` — el endpoint `GET /v1/vets/{vet}` debe resolver por slug, no por guid.
6. Revisar `back/app/Http/Resources/V1/VetResource.php` para confirmar que `VetItem` en el frontend refleja fielmente el shape real del recurso backend. Los campos `contacts`, `validated_by` y `country` deben estar documentados.
7. Verificar si `pinia-plugin-persistedstate` ya está configurado para stores existentes y cómo se define el scope de persistencia parcial (solo `lastVisitedSlug`, no todo el store).

---

## Criterios de aceptación verificables

### CA-01 — Endpoint prerequisito
- `GET /v1/user/vets` con token de un usuario que tiene 2 vets activas retorna array de 2 objetos con los campos `guid`, `name`, `slug`, `logo_path`, `is_active`, `role.name`.
- `GET /v1/user/vets` con token de un usuario sin vets asignadas retorna `{ "success": true, "data": [] }`.
- `GET /v1/user/vets` con token de un usuario cuya única vet está suspendida retorna array vacío.
- El endpoint NO está protegido por `vet.tenant` middleware.

### CA-02 — Guard de acceso
- Navegar a `/vets/slug-inexistente/perfil` redirige a `/dashboard` y muestra toast "La veterinaria no existe".
- Navegar a `/vets/slug-de-vet-de-otro-usuario/perfil` redirige a `/dashboard` y muestra toast "Ya no tenés acceso a esta veterinaria".
- Navegar a `/vets/slug-valido/perfil` siendo miembro de esa vet carga el layout correctamente.

### CA-03 — VetTenantLayout y carga
- Al navegar a `/vets/:vetSlug/perfil`, el layout muestra un skeleton mientras `GET /v1/vets/{vetSlug}` está en vuelo.
- Una vez resuelta la query, el skeleton desaparece y se muestra el contenido de la página.
- El `vetStore.currentVet` tiene los datos de la vet cargada.
- El `vetStore.lastVisitedSlug` se actualiza en localStorage con el slug visitado.

### CA-04 — VetSwitcher
- Un usuario con 1 vet asignada NO ve el switcher en el header.
- Un usuario con 2+ vets asignadas VE el switcher con la vet activa marcada visualmente.
- Al seleccionar una vet distinta en el switcher estando en `/vets/vet-a/perfil`, el navegador redirige a `/vets/vet-b/perfil` (mismo path relativo).
- El store se limpia y recarga para la nueva vet.

### CA-05 — VetProfilePage
- La página muestra: nombre, slug, tax_id, país, tipo de documento, estado (usando `VetStatusBadge`), logo (si tiene), `validated_at` formateado, contactos.
- Si `logo_path` es null, se muestra un avatar placeholder con las iniciales del nombre de la vet.
- No hay ningún botón de edición en la página.

### CA-06 — Multi-pestaña
- Abrir la app en dos pestañas en la misma vet, cambiar de vet en la pestaña 2 (via switcher), y verificar que la pestaña 1 muestra el banner "Cambiaste de veterinaria en otra pestaña. Recargar."
- Hacer clic en "Recargar" en la pestaña 1 recarga la página completa.

### CA-07 — usePermission con contexto tenant
- En `VetProfilePage`, `usePermission('vet', 'tenant')` retorna `true` para un usuario con rol `vet` en esa vet y `false` para un `vet-assistant`.
- Los callers existentes de `usePermission(permission: string)` sin contexto siguen funcionando sin cambios.

### CA-08 — Sin regresiones en panel admin
- Las rutas `/admin/vets/*` cargan y funcionan sin cambios.
- `usePermission()` sin contexto sigue evaluando permisos Spatie correctamente.

---

## Output esperado

Plan técnico en `.claude/docs/plans/TKT-001-panel-tenant-frontend-plan.md` que incluya:
- Orden de implementación recomendado (prerequisito backend primero, luego infraestructura frontend, luego página)
- Código de los archivos a crear/modificar, completo y funcional
- Estrategia para la persistencia parcial del store (solo `lastVisitedSlug`)
- Implementación del guard async con manejo de cache de `userVets`
- Implementación del evento `storage` para detección multi-pestaña
- Cómo extender `usePermission()` sin romper la firma existente
- Tests unitarios para el guard y el store (al menos casos happy path y casos de error 403/404)
