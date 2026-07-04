# Plan técnico: Unificación del sistema de permisos en el frontend Vue 3

## Input procesado
Brief informal del usuario en el chat (descripción del problema y objetivo).

## Resumen ejecutivo

Hoy coexisten dos mecanismos de verificación de permisos en el frontend: el `usePermission()` composable que lee permisos del auth store (scope plataforma/admin), y la función `canInTenant()` dentro del mismo composable que verifica el *rol* del usuario en la vet actual (no permisos granulares: solo compara el nombre del rol). El objetivo es unificar en un único punto de verdad de forma que, cuando el usuario selecciona una VET, los permisos granulares de ese contexto tenant se carguen en el auth store y sean consultables con el `can()` existente, sin necesidad de `canInTenant()`.

El approach elegido es la **estrategia de montaje aditivo con prefijo `tenant:`**: al seleccionar una VET, se cargan los permisos tenant en el auth store con el prefijo `tenant:`, reemplazando los anteriores de ese espacio. Al salir del contexto, se limpian. El composable `usePermission()` expone un `canTenant(permission)` que internamente llama a `can('tenant:' + permission)`, eliminando `canInTenant()`. El componente `PermissionGuard` no cambia su API. No se crea un store nuevo: el auth store absorbe los permisos tenant en un campo separado `tenantPermissions`.

Módulos tocados: `auth.store.ts`, `vet.store.ts`, `usePermissions.ts`, `VetMenu.vue`, `useVetTenant.ts`, y todos los sitios que llaman `canInTenant`.

---

## Decisiones tomadas

### DEC-01 — Dónde vive el estado de permisos tenant

**Decisión:** Los permisos tenant se guardan en el `auth.store.ts` en un campo nuevo `tenantPermissions: string[]`, no en el `vet.store.ts`.

**Justificación:** El auth store ya es el dueño de los permisos de plataforma. El composable `usePermission()` ya importa el auth store. Centralizar allí evita tener que cruzar stores en el composable. El vet store sigue siendo dueño del contexto de la vet (guid, nombre, currentVet) pero ya no de los permisos.

**Alternativa descartada:** Store separado `permissions.store.ts`. Agrega indirección y un store más para sincronizar sin beneficio real dado el alcance del proyecto.

---

### DEC-02 — Estrategia de mezcla de permisos: prefijo vs reemplazo total vs namespace separado

**Decisión:** Los permisos tenant se almacenan en `tenantPermissions: string[]` **sin prefijo**, en un array separado del `permissions` de plataforma. La función `canTenant(permission)` consulta `tenantPermissions`. La función `can(permission)` solo consulta `permissions` de plataforma. No se mezclan en un solo array.

**Justificación:** El prefijo `tenant:` fue considerado pero crea ambigüedad: ¿`can('tenant:clients.create')` y `can('clients.create')` son lo mismo en contexto tenant? La separación en dos arrays es explícita y sin colisiones. La API resultante es `can()` para scope plataforma, `canTenant()` para scope tenant; ambas viven en el mismo composable. El componente `PermissionGuard` existente ya usa `can()` para el scope admin — el comportamiento no cambia para esos 90+ usos existentes. Las páginas tenant que necesitan permisos usan `canTenant()`.

**Alternativa descartada:** Reemplazo total (los permisos tenant sobreescriben los de plataforma). Descartado porque el admin SAV puede entrar al panel tenant y necesita conservar sus permisos de admin (`vets.read`, `vets.update`, etc.) que controlan, por ejemplo, el link "Volver al admin" en VetMenu.

---

### DEC-03 — Cuándo y quién carga los permisos tenant en el auth store

**Decisión:** El composable `useVetTenant.ts` ya hace el `watch` sobre `vetData` para llamar `vetStore.setCurrentVet(vet, profile)`. En ese mismo watch se agrega la llamada `authStore.setTenantPermissions(profile.role.permissions)`. No se agrega una llamada HTTP adicional: los permisos ya vienen en `userVets` (campo `role.permissions` del `UserVetItem`).

**Justificación:** La fuente de permisos tenant ya existe en `userVets` (se cargan via `/v1/user/vets` que retorna `role.permissions`). No hace falta un endpoint nuevo. El lugar natural de carga es `useVetTenant` porque ya es el composable que activa el contexto vet en el layout.

**Alternativa descartada:** Cargar los permisos desde el guard `vetTenantGuard`. El guard es síncrono-async pero no tiene acceso reactivo; es más correcto cargar en el composable donde ya está la lógica de watch.

---

### DEC-04 — Cuándo limpiar los permisos tenant

**Decisión:** Se limpian en dos momentos:
1. En `vetStore.clearCurrentVet()` y `vetStore.clearAll()` — estas funciones deben también llamar a `authStore.clearTenantPermissions()`.
2. En el watch de error de `useVetTenant` cuando se llama `vetStore.clearCurrentVet()`.

El `authStore.logout()` ya llama `$reset()` que resetea todo el store incluyendo `tenantPermissions`.

**Justificación:** Los dos sitios donde hoy se limpia el contexto vet son `clearCurrentVet()` y `clearAll()`. Si el auth store también tiene que limpiar, lo más limpio es hacerlo desde esas funciones del vet store (que ya importará el auth store). Alternativa: hacerlo en el router `afterEach` detectando salida de rutas `/vets/:vetGuid`, pero es frágil ante navegación programática y reload.

**Alternativa descartada:** Que el watch de `useVetTenant` limpie en `onUnmounted`. El VetTenantLayout puede remontarse en SPA navigation y el timing no es confiable.

---

### DEC-05 — La función `canInTenant()` existente

**Decisión:** La función `canInTenant(role)` se **elimina** del composable `usePermission()`. Su único consumidor real es `VetMenu.vue` (donde filtra ítems por rol). Ese filtro se reemplaza por: todos los ítems son visibles para cualquier usuario con al menos un permiso tenant cargado (ya que hoy el menú muestra todo a todos los roles vet). Si en el futuro hubiera ítems con visibilidad por permiso específico, se usará `canTenant()`.

**Justificación:** Revisar el código real: `VetMenu.vue` define `ALL_VET_ROLES = ['vet', 'vet-assistant', 'vet-administrative']` y filtra con `canInTenant(item.roles)` donde `item.roles` siempre es `ALL_VET_ROLES`. Es decir, el filtro hoy pasa a *cualquiera* de los tres roles — efectivamente muestra todo a todos. El resultado lógico es: si hay `tenantPermissions` cargados (el usuario está en contexto vet), mostrar los ítems. Esto simplifica el código sin cambiar el comportamiento.

**Alternativa descartada:** Migrar `canInTenant` a verificar por permisos en lugar de roles. Innecesario con el estado actual del menú; cuando se necesite control granular se usará `canTenant()`.

---

### DEC-06 — `canInCurrentVet()` en vet.store.ts

**Decisión:** La función `canInCurrentVet(permission)` del vet store se **elimina**. No tiene consumidores en el código actual (solo está definida, no hay ningún import ni uso detectado en el grep).

**Justificación:** Grep de `canInCurrentVet` muestra 0 llamadas fuera del propio store. Es código muerto. Se elimina para no mantener dos caminos.

---

### DEC-07 — El componente `PermissionGuard`

**Decisión:** No se modifica. Sigue usando `can()` del composable, que sigue siendo solo para permisos de plataforma. No se crea un `TenantPermissionGuard` separado: el template puede usar `v-if="canTenant('clients.create')"` directamente o crear un simple wrapper si el equipo lo prefiere. Esto queda fuera del alcance de este plan.

**Justificación:** Los 90+ usos de `PermissionGuard` son todos en contexto admin/plataforma (verifican permisos como `vets.read`, `clients.create`, `users.delete`, etc.). Ninguno de esos usos ocurre en páginas exclusivas del panel tenant. No hay rotura.

---

### DEC-08 — Persistencia

**Decisión:** `tenantPermissions` en el auth store **no se persiste** (no se agrega a `pick`). Al recargar la página, el `VetTenantLayout` monta `useVetTenant()` que recarga el profile, lo que dispara el watch y vuelve a cargar los permisos.

**Justificación:** Los permisos tenant son derivados del `userVets` y pueden cambiar (si el admin cambia el rol del usuario en la vet). Persistirlos introduce riesgo de permisos stale. El costo de recargarlos es una query que ya está cacheada por Vue Query con `staleTime: 1000 * 60 * 2`.

---

## Cambios en BACKEND

No requiere cambios en backend en esta iteración. El endpoint `/v1/user/vets` ya retorna `role.permissions` para cada vet del usuario. Ese dato es la fuente de permisos tenant.

---

## Cambios en FRONTEND

### Archivos a modificar

#### `front/src/modules/auth/stores/auth.store.ts`

**Cambio:** Agregar el campo `tenantPermissions: string[]` al estado, y las acciones `setTenantPermissions(permissions: string[])` y `clearTenantPermissions()`.

**Antes (resumido):**
```ts
interface AuthStoreState {
    token: string | null
    user: User | null
    isLoggingIn: boolean
    isLoggingOut: boolean
    mustVerifyAccount: boolean
    pendingVerificationGuid: string | null
    mustChangePassword: boolean
}
// state():
state: (): AuthStoreState => ({
    token: null,
    user: null,
    // ...
})
// Sin acciones de permisos tenant.
```

**Después (resumido):**
```ts
interface AuthStoreState {
    token: string | null
    user: User | null
    isLoggingIn: boolean
    isLoggingOut: boolean
    mustVerifyAccount: boolean
    pendingVerificationGuid: string | null
    mustChangePassword: boolean
    tenantPermissions: string[]   // NUEVO
}
// state():
state: (): AuthStoreState => ({
    // ... igual que antes ...
    tenantPermissions: [],         // NUEVO
})
// Agregar en actions:
setTenantPermissions(permissions: string[]): void {
    this.tenantPermissions = permissions
},
clearTenantPermissions(): void {
    this.tenantPermissions = []
},
```

Nota: `persist.pick` no incluye `tenantPermissions` (intencional, ver DEC-08).

---

#### `front/src/core/stores/vet.store.ts`

**Cambio 1:** Importar `useAuthStore` y llamar a `authStore.clearTenantPermissions()` en `clearCurrentVet()` y `clearAll()`.

**Cambio 2:** Eliminar la función `canInCurrentVet()` (código muerto, DEC-06).

**Antes (resumido):**
```ts
import { defineStore } from 'pinia'
import { ref } from 'vue'
// Sin import de authStore

function clearCurrentVet(): void {
    currentVet.value    = null
    vetGuid.value       = null
    currentProfile.value = null
}

function clearAll(): void {
    currentVet.value      = null
    vetGuid.value         = null
    currentProfile.value  = null
    userVets.value        = []
    lastVisitedGuid.value = null
}

function canInCurrentVet(permission: string): boolean {
    return currentProfile.value?.role.permissions.includes(permission) ?? false
}

return {
    // ...
    canInCurrentVet,  // <- exportado
}
```

**Después (resumido):**
```ts
import { defineStore } from 'pinia'
import { ref } from 'vue'
import { useAuthStore } from '@/modules/auth/stores/auth.store'  // NUEVO

// Dentro del store (setup store):
function clearCurrentVet(): void {
    currentVet.value     = null
    vetGuid.value        = null
    currentProfile.value = null
    useAuthStore().clearTenantPermissions()  // NUEVO
}

function clearAll(): void {
    currentVet.value      = null
    vetGuid.value         = null
    currentProfile.value  = null
    userVets.value        = []
    lastVisitedGuid.value = null
    useAuthStore().clearTenantPermissions()  // NUEVO
}

// canInCurrentVet ELIMINADA

return {
    currentVet,
    vetGuid,
    currentProfile,
    userVets,
    lastVisitedGuid,
    setCurrentVet,
    setUserVets,
    clearCurrentVet,
    clearAll,
    setLastVisitedGuid,
    // canInCurrentVet ya NO se exporta
}
```

Nota sobre circular dependency: Pinia permite usar `useAuthStore()` dentro de las funciones de otro store (no en el top-level de la factory function) porque los stores se resuelven en tiempo de ejecución. Llamar `useAuthStore()` dentro de `clearCurrentVet()` es seguro.

---

#### `front/src/core/composables/usePermissions.ts`

**Cambio:** Reemplazar `canInTenant(role)` (que verifica nombre de rol) por `canTenant(permission)` (que verifica permiso granular en `tenantPermissions`). Agregar getter de conveniencia `hasTenantContext` (booleano: `tenantPermissions.length > 0`).

**Antes (resumido):**
```ts
import { computed } from 'vue';
import { useAuthStore } from '@/modules/auth/stores/auth.store';
import { useVetStore } from '@/core/stores/vet.store';
import type { VetTenantRole } from '@/core/types/vet-context.types';

export function usePermission() {
    const auth     = useAuthStore();
    const vetStore = useVetStore();

    const userPermissions = computed(() => auth.user?.permissions ?? []);
    const userRoles       = computed(() => auth.user?.roles ?? []);

    function can(permission: string): boolean { ... }
    function hasRole(role: string): boolean { ... }
    function hasAnyRole(...roles: string[]): boolean { ... }

    function canInTenant(role: VetTenantRole | VetTenantRole[]): boolean {
        const profile = vetStore.currentProfile;
        if (!profile) return false;
        const currentRole = profile.role.name;
        if (Array.isArray(role)) return role.includes(currentRole);
        return currentRole === role;
    }

    return { can, hasRole, hasAnyRole, canInTenant };
}
```

**Después (resumido):**
```ts
import { computed } from 'vue';
import { useAuthStore } from '@/modules/auth/stores/auth.store';
// ELIMINAR: import { useVetStore } ...
// ELIMINAR: import type { VetTenantRole } ...

export function usePermission() {
    const auth = useAuthStore();

    const userPermissions   = computed(() => auth.user?.permissions ?? []);
    const userRoles         = computed(() => auth.user?.roles ?? []);
    const tenantPermissions = computed(() => auth.tenantPermissions);  // NUEVO

    function can(permission: string): boolean {
        return userPermissions.value.includes(permission);
    }

    function hasRole(role: string): boolean {
        return userRoles.value.some(r => r.name === role);
    }

    function hasAnyRole(...roles: string[]): boolean {
        return roles.some(r => userRoles.value.some(ur => ur.name === r));
    }

    // NUEVO — reemplaza canInTenant
    function canTenant(permission: string): boolean {
        return tenantPermissions.value.includes(permission);
    }

    // NUEVO — útil para condicionar visibilidad de ítems de menú
    const hasTenantContext = computed(() => tenantPermissions.value.length > 0);

    // ELIMINAR: canInTenant

    return { can, hasRole, hasAnyRole, canTenant, hasTenantContext };
}
```

---

#### `front/src/modules/vets/composables/useVetTenant.ts`

**Cambio:** En el `watch(vetData, ...)`, después de llamar `vetStore.setCurrentVet(vet, profile)`, agregar la llamada `authStore.setTenantPermissions(profile.role.permissions)`.

**Antes (resumido):**
```ts
import { useVetStore } from '@/core/stores/vet.store'
// Sin import de authStore

watch(vetData, (vet) => {
    if (!vet) return
    const userVetItem = vetStore.userVets.find(v => v.guid === guid.value)
    if (!userVetItem) return

    const profile: VetUserProfile = {
        guid: userVetItem.guid,
        role: {
            name:        userVetItem.role.name as VetTenantRole,
            permissions: userVetItem.role.permissions,
        },
    }

    vetStore.setCurrentVet(vet, profile)
    // Fin del watch — sin carga de permisos en auth store
}, { immediate: true })
```

**Después (resumido):**
```ts
import { useVetStore } from '@/core/stores/vet.store'
import { useAuthStore } from '@/modules/auth/stores/auth.store'  // NUEVO

const authStore = useAuthStore()  // NUEVO

watch(vetData, (vet) => {
    if (!vet) return
    const userVetItem = vetStore.userVets.find(v => v.guid === guid.value)
    if (!userVetItem) return

    const profile: VetUserProfile = {
        guid: userVetItem.guid,
        role: {
            name:        userVetItem.role.name as VetTenantRole,
            permissions: userVetItem.role.permissions,
        },
    }

    vetStore.setCurrentVet(vet, profile)
    authStore.setTenantPermissions(profile.role.permissions)  // NUEVO
}, { immediate: true })
```

El watch de error no requiere cambios: `vetStore.clearCurrentVet()` ya llamará `authStore.clearTenantPermissions()` en cascada (DEC-04).

---

#### `front/src/components/layouts/partials/VetMenu.vue`

**Cambio:** Reemplazar el uso de `canInTenant(item.roles)` por `hasTenantContext` para el filtro de ítems del menú.

**Antes (resumido):**
```ts
import { usePermission } from '@/core/composables/usePermissions'
import type { VetTenantRole } from '@/core/types/vet-context.types'

const { can, canInTenant } = usePermission()

const ALL_VET_ROLES: VetTenantRole[] = ['vet', 'vet-assistant', 'vet-administrative']

const vetNavItems = computed(() => [
  { path: ..., label: 'Perfil',     icon: IdcardOutlined,   roles: ALL_VET_ROLES },
  { path: ..., label: 'Clientes',   icon: TeamOutlined,     roles: ALL_VET_ROLES },
  { path: ..., label: 'Usuarios',   icon: UserOutlined,     roles: ALL_VET_ROLES },
  { path: ..., label: 'Tutoriales', icon: PlayCircleOutlined, roles: ALL_VET_ROLES },
])

const visibleItems = computed(() =>
  vetNavItems.value.filter(item => canInTenant(item.roles)),
)
```

**Después (resumido):**
```ts
import { usePermission } from '@/core/composables/usePermissions'
// ELIMINAR: import type { VetTenantRole } from '@/core/types/vet-context.types'

const { can, hasTenantContext } = usePermission()

// ELIMINAR: ALL_VET_ROLES

const vetNavItems = computed(() => [
  { path: ..., label: 'Perfil',     icon: IdcardOutlined },
  { path: ..., label: 'Clientes',   icon: TeamOutlined },
  { path: ..., label: 'Usuarios',   icon: UserOutlined },
  { path: ..., label: 'Tutoriales', icon: PlayCircleOutlined },
])

const visibleItems = computed(() =>
  hasTenantContext.value ? vetNavItems.value : [],
)
```

Nota: la propiedad `roles` se elimina de cada ítem porque ya no se usa.

---

### Archivos a crear

Ninguno. El plan no agrega stores, composables ni componentes nuevos.

---

### Supuestos hechos

1. **`canInTenant` no tiene más consumidores que `VetMenu.vue`.** El grep confirma exactamente dos referencias: la definición en `usePermissions.ts` y el uso en `VetMenu.vue`. No hay otros archivos.

2. **`canInCurrentVet` del vet store es código muerto.** El grep de `canInCurrentVet` muestra solo las líneas de definición y export en `vet.store.ts`, ningún consumidor externo.

3. **Las rutas del panel tenant bajo `/vets/:vetGuid/...` que usan `PermissionGuard` (como `ClientsListPage.vue`, `ClientDetailPage.vue`, etc.) verifican permisos de plataforma** (`clients.create`, `clients.update`, etc.) que el usuario admin tiene en `auth.user.permissions`. Este comportamiento no cambia. Si en el futuro un usuario puramente tenant (sin permisos de plataforma) accede a esas páginas, esos `PermissionGuard` no le mostrarán los botones — ese es un problema de diseño pre-existente no relacionado con este refactor.

4. **El campo `role.permissions` en `UserVetItem` ya tiene los permisos granulares correctos.** Se asume que el backend retorna permisos como `clients.create`, `clients.update`, etc. en ese campo, no solo el nombre del rol. Si no fuera así, se debe verificar el endpoint `/v1/user/vets` antes de implementar.

---

## Orden de implementación

Cada paso es independiente y testeable antes de pasar al siguiente.

1. **Modificar `front/src/modules/auth/stores/auth.store.ts`:** Agregar campo `tenantPermissions: string[]` al estado (inicializado en `[]`), y las acciones `setTenantPermissions(permissions: string[])` y `clearTenantPermissions()`. NO agregarlo al `persist.pick`. Verificar que la app sigue funcionando sin cambios de comportamiento (el campo existe pero está vacío).

2. **Modificar `front/src/core/stores/vet.store.ts`:** Importar `useAuthStore` y agregar las llamadas a `clearTenantPermissions()` dentro de `clearCurrentVet()` y `clearAll()`. Eliminar la función `canInCurrentVet()` y su entrada en el objeto `return`. Verificar que logout y salida de contexto vet no rompen nada.

3. **Modificar `front/src/core/composables/usePermissions.ts`:** Agregar `tenantPermissions` computed desde `auth.tenantPermissions`. Agregar `canTenant(permission: string)` y `hasTenantContext` computed. Eliminar `canInTenant`. Eliminar el import de `useVetStore` y el import de `VetTenantRole`. Actualizar el objeto de retorno.

4. **Modificar `front/src/modules/vets/composables/useVetTenant.ts`:** Importar `useAuthStore`. Instanciar `authStore`. Agregar `authStore.setTenantPermissions(profile.role.permissions)` dentro del `watch(vetData, ...)`, justo después de `vetStore.setCurrentVet(vet, profile)`.

5. **Modificar `front/src/components/layouts/partials/VetMenu.vue`:** Reemplazar `canInTenant` por `hasTenantContext`. Eliminar `ALL_VET_ROLES`. Eliminar la propiedad `roles` de cada ítem de `vetNavItems`. Eliminar el import de `VetTenantRole`.

6. **Smoke test manual:** Iniciar sesión como usuario con acceso a una vet. Verificar que VetMenu muestra los ítems. Abrir DevTools → Pinia → `auth` store → confirmar que `tenantPermissions` tiene permisos cargados al estar en el panel tenant. Navegar a `/dashboard` o hacer logout y verificar que `tenantPermissions` se limpian.

7. **Test de regresión funcional:** Verificar que los `PermissionGuard` en vistas admin (VetStaffSection, ClientsTable, etc.) siguen funcionando para roles admin, ya que usan `can()` que no fue modificado.

---

## Riesgos y consideraciones

### R-01 — Verificar contenido real de `role.permissions` en el endpoint `/v1/user/vets`

**Riesgo medio.** El tipo `UserVetItem.role.permissions: string[]` existe en el frontend, pero no se auditó el endpoint backend en este plan. Si el array retorna solo el nombre del rol (ej: `['vet']`) en lugar de permisos granulares (ej: `['clients.create', 'clients.read']`), el sistema unificado funcionará estructuralmente pero `canTenant()` nunca va a matchear ningún permiso. **Antes de implementar el paso 4, verificar la respuesta real del endpoint `/v1/user/vets` con un request de prueba.**

### R-02 — Circular dependency Pinia stores

**Riesgo bajo.** El vet store llama `useAuthStore()` dentro de funciones (no en el cuerpo del `defineStore`). Pinia soporta esto con la sintaxis setup store y con la store options API siempre que se llame al store dentro de las acciones. La implementación actual usa la setup store (`defineStore('vet', () => { ... })`), que es la forma más segura. No hay riesgo en este caso.

### R-03 — Usuario puramente tenant sin permisos de plataforma

**Riesgo pre-existente (no nuevo, pero que este refactor expone con más claridad).** Las páginas del panel tenant (`/vets/:vetGuid/clients/*`) usan `PermissionGuard` con permisos de plataforma (`clients.create`, etc.). Si un usuario que NO tiene esos permisos en `auth.user.permissions` accede al panel tenant, los `PermissionGuard` no le mostrarán botones de acción aunque su rol vet tenga `canTenant('clients.create')`. Este es un problema de diseño previo. Este plan NO lo resuelve — se documenta como deuda técnica para una iteración futura donde esas páginas tenant migren sus `PermissionGuard` a usar `canTenant()`.

### R-04 — Permisos stale al cambiar de vet

**Riesgo medio.** Si el usuario navega de `/vets/guid-A/...` a `/vets/guid-B/...` directamente (via VetSwitcher o navegación manual), `useVetTenant` se remonta en el nuevo layout y el `watch(vetData, ..., { immediate: true })` se dispara nuevamente. Sin embargo, hay una ventana corta donde `tenantPermissions` aún tiene los permisos de la vet-A mientras se carga el perfil de vet-B. Para mitigarla, agregar en el watch: **antes** de llamar `setCurrentVet`, llamar `authStore.clearTenantPermissions()`. Esto asegura que no haya permisos "viejos" visibles durante la transición.

Pseudocódigo del watch con la mitigación:
```ts
watch(vetData, (vet) => {
    if (!vet) return
    const userVetItem = vetStore.userVets.find(v => v.guid === guid.value)
    if (!userVetItem) return
    
    authStore.clearTenantPermissions()  // Limpiar ANTES de cargar los nuevos
    
    const profile: VetUserProfile = { ... }
    vetStore.setCurrentVet(vet, profile)
    authStore.setTenantPermissions(profile.role.permissions)
}, { immediate: true })
```

### R-05 — `VetTenantRole` en `vet-context.types.ts`

El tipo `VetTenantRole` se usa en otros lugares además de `usePermissions.ts` (`useVetTenant.ts`, `user-vet.types.ts`). El tipo NO se elimina de `vet-context.types.ts` — solo se elimina su import en `usePermissions.ts`. Confirmar que el tipo sigue siendo importado correctamente donde se necesita.

---

## Pendientes / fuera de alcance

1. **Migrar `PermissionGuard` en páginas tenant a `canTenant()`.** Las páginas como `ClientsListPage.vue`, `ClientDetailPage.vue`, `EstablishmentsSection.vue`, etc. usan `PermissionGuard` con permisos de plataforma. Para que un usuario puramente tenant pueda ver esos controles, habría que cambiar esos guards a `v-if="canTenant('clients.create')"` o crear un `TenantPermissionGuard`. Queda para una iteración siguiente una vez que se confirme que los permisos granulares tenant retornados por el backend coinciden en nombre con los de plataforma (o se definen los propios).

2. **Guard de rutas tenant por permiso.** Hoy `vetTenantGuard` solo verifica que el usuario pertenece a la vet. No verifica permisos específicos por ruta. Si se quisiera bloquear `/vets/:guid/usuarios` a usuarios sin permiso de gestión de staff, habría que agregar lógica en el guard o en meta de la ruta. Fuera del alcance de este plan.

3. **`canTenant` para guards de rutas.** La función `canTenant()` del composable solo puede usarse dentro de componentes Vue (composable). Si en el futuro se necesita verificar permisos tenant en un navigation guard (fuera de un componente), se deberá leer `useAuthStore().tenantPermissions` directamente desde el guard.
