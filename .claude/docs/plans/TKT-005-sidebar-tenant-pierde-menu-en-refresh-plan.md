# Plan técnico: Sidebar tenant pierde menú en refresh/deep-link (TKT-005)

## Input procesado
`.claude/docs/tickets/TKT-005-sidebar-tenant-pierde-menu-en-refresh.md`

## Resumen ejecutivo

La causa raíz NO es un problema de timing entre Pinia persist / `watchEffect` / fetch redundante, como sugerían los candidatos del ticket. Es un **bug de registro de rutas**: `vetProtocolsRoutes` y `vetProgramsRoutes` (y, se confirmó durante la investigación, también `clientsRoutes`) están registradas como hijas del primer bloque `path: '/'` en `front/src/router/index.ts` — el que usa `AppLayout.vue` y solo corre `authGuard` — en vez de ser hijas de `vetsTenantRoutes` (`path: '/vets/:vetGuid'`, usa `VetTenantLayout.vue` y corre `vetTenantGuard`). Como Vue Router resuelve por el primer route record que matchea, `/vets/{guid}/protocols` y `/vets/{guid}/programs` terminan montándose bajo `AppLayout.vue`, que **nunca invoca `useVetTenant()`** (ese composable solo se llama dentro de `VetTenantLayout.vue`). Por lo tanto `authStore.tenantPermissions` nunca se popula para esas rutas en la sesión actual, y como `AppSidebar.vue` decide mostrar `VetMenu` únicamente en base a `route.path.startsWith('/vets/')` (sin importar bajo qué layout real se montó), el sidebar queda con `hasTenantContext = false` de forma permanente — no es un parpadeo transitorio, es que el código que corrige el estado literalmente no corre en esa rama de rutas. Esto explica por qué el contenido de la página carga bien (la página resuelve sus propios datos por `vetGuid` de la URL, sin depender de `tenantPermissions`) y por qué el bug es idéntico en `/protocols` y `/programs` (mismo defecto de registro, copiado en ambos módulos).

El fix es mover el registro de esas rutas al árbol correcto (`vetsTenantRoutes`), sin tocar el sistema de permisos ni el sidebar en su diseño. Se agrega además un gateo defensivo mínimo (`isLoading` también sobre `AppSidebar`) para cerrar el único caso residual real: un deep-link a un tenant DISTINTO al de la sesión persistida previa, donde `tenantPermissions` persistido (de otro vet) puede mostrarse por una fracción de segundo hasta que `useVetTenant()` resuelve.

## Decisiones tomadas

DEC-01 — Causa raíz confirmada: registro de rutas fuera del árbol de `VetTenantLayout`, no timing de Pinia/watchEffect
  Decisión: el fix ataca el registro de rutas (`router/index.ts` + archivos de rutas de `protocols`/`programs`/`clients`), no `useVetTenant.ts` ni `vetTenantGuard.ts`.
  Justificación: se confirmó leyendo `front/src/router/index.ts` que `vetProtocolsRoutes`/`vetProgramsRoutes` (y `clientsRoutes`) se spreadean dentro del primer bloque `{ path: '/', component: AppLayout.vue, beforeEnter: authGuard, children: [...] }`, NO dentro del segundo bloque `{ path: '/', beforeEnter: authGuard, children: vetsTenantRoutes }` que usa `VetTenantLayout.vue`. Vue Router matchea por el primer route record que coincide en el array de rutas; como el bloque de `AppLayout` aparece antes en `routes`, gana. `AppLayout.vue` no importa ni llama `useVetTenant()` en ningún punto — solo `VetTenantLayout.vue` lo hace (línea 27 de ese archivo). Sin esa llamada, `authStore.tenantPermissions` nunca se setea para esas rutas, y `AppSidebar.vue` (línea 12: `isVetContext = route.path.startsWith('/vets/')`) igual renderiza `VetMenu` porque decide por el path de la URL, no por el layout real montado — de ahí que el síntoma sea "queda pegado" y no "flashea y se corrige": el código que lo corregiría no existe en esa rama.
  Alternativa descartada: mover el `watchEffect` de `useVetTenant.ts` a `vetTenantGuard.ts` (candidato sugerido en el ticket). Se descarta porque no soluciona nada si el guard correcto (`vetTenantGuard`) ni siquiera se ejecuta para estas rutas — el guard que sí corre en la rama rota es `authGuard`, no `vetTenantGuard`. Cambiar el guard equivocado no repara una ruta que nunca lo invoca.
  Alternativa descartada: "gatear el sidebar por isLoading" como fix único (lo que el ticket pide explícitamente NO hacer sin antes confirmar la causa). Confirmado que sería tapar el síntoma: mientras las rutas sigan mal registradas, `isLoading` de `useVetTenant()` nunca existiría en el árbol de componentes de `/protocols`/`/programs` (ese composable no se monta ahí), con lo cual no hay ningún `isLoading` que gatear en el layout real que se está usando.

DEC-02 — Alcance del fix: incluir también `clients.routes.ts` (mismo defecto, no mencionado en el ticket)
  Decisión: se extiende el fix a `front/src/modules/clients/router/clients.routes.ts`, que tiene exactamente el mismo patrón roto (`path: '/vets/:vetGuid/clients...'` spreadeado dentro del bloque `AppLayout`, nunca dentro de `vetsTenantRoutes`).
  Justificación: es el mismo bug, con el mismo mecanismo de causa raíz, confirmado por lectura directa del archivo — no es una extensión de alcance del sistema de permisos ni un rediseño, es corregir el mismo defecto de registro donde ya se sabe que existe. Dejarlo sin corregir en el mismo cambio, sabiendo que reproduce el idéntico síntoma en `/vets/{guid}/clients`, sería negligente. El ticket restringe el alcance a "no rediseñar el sistema de permisos ni el sidebar", no a "ignorar instancias confirmadas del mismo bug en otros módulos".
  Alternativa descartada: dejar `clients.routes.ts` para un ticket aparte. Descartada porque el fix es mecánicamente idéntico (mover rutas + relativizar paths), de bajo riesgo, y no amerita una ronda completa de ticket→plan→dev separada para el mismo one-liner conceptual. Se deja explícito en Riesgos para que QA lo verifique also ahí.

DEC-03 — Gateo adicional de `AppSidebar` por `isLoading` de `useVetTenant()` (responde "A definir 2" del ticket)
  Decisión: una vez el layout correcto (`VetTenantLayout.vue`) se ejecuta siempre para rutas tenant (DEC-01/DEC-02), se agrega que el `<AppSidebar>` también reciba/considere el `isLoading` de `useVetTenant()` — no ocultando el sidebar completo, sino pasando un prop `tenantContextLoading` que `VetMenu.vue` usa para NO renderizar (mostrar contenedor vacío o skeleton chico) los bloques "Veterinaria"/"Reproducción" mientras `isLoading` es `true`, en vez de decidir solo por `hasTenantContext`.
  Justificación: una vez arreglado el bug de ruteo, el flujo normal (F5 dentro del mismo tenant) no muestra ningún hueco porque `tenantPermissions` está persistido y sigue siendo válido mientras `useVetProfile` re-valida en segundo plano. El único caso residual real es un deep-link a un tenant DISTINTO al de la última sesión persistida (ej. el usuario tiene acceso a Vet A y Vet B, la sesión anterior dejó `tenantPermissions` de Vet A persistido, y el deep-link apunta a Vet B) — ahí, por una fracción de segundo hasta que `useVetProfile(guid)` resuelve y el `watchEffect` corrige `tenantPermissions`, el sidebar podría mostrar (o directamente no mostrar, según overlap de permisos) los bloques del vet incorrecto. Es un caso de bajo impacto pero real, y gatear solo esos dos bloques del sidebar (no toda la navegación, no bloqueante) resuelve el "A definir 2" del ticket sin introducir un loading global — cumple la restricción explícita del ticket ("no introducir un loading global que bloquee toda la navegación tenant de forma permanente").
  Alternativa descartada: no tocar el sidebar y confiar en que el `watchEffect` corrija en <1s. Descartada porque, aunque el caso es transitorio y de bajo impacto, mostrar momentáneamente permisos de OTRO tenant (aunque sea solo visualmente, ya que el backend igual scoped por `vet.tenant` middleware) es una mala señal de UX y contradice el criterio de "el sidebar tenant nunca debe renderizarse con `tenantPermissions` en un estado transitorio/vacío de forma persistente" del ticket — acá el estado no sería "vacío" sino "de otro tenant", variante del mismo riesgo.
  Alternativa descartada: gatear el `<AppSidebar>` completo (como ya hace `RouterView`) en vez de solo los bloques "Veterinaria"/"Reproducción" dentro de `VetMenu`. Descartada porque el sidebar también contiene "Mensajes"/"Tutoriales"/"Mi perfil", que no dependen de `tenantPermissions` — ocultar todo el sidebar sería un downgrade de UX innecesario (parpadeo de layout completo) para un problema que solo afecta 2 de 4 bloques.

## Cambios en FRONTEND

### Archivos a modificar

#### `front/src/modules/protocols/router/vet-protocols.routes.ts`
**Cambio:** relativizar el `path` para que la ruta pase a ser hija de `/vets/:vetGuid` en vez de una ruta absoluta top-level.
**Antes:**
```ts
export const vetProtocolsRoutes: RouteRecordRaw[] = [
  {
    path: '/vets/:vetGuid/protocols',
    name: 'vet-protocols-list',
    component: () => import('@/modules/protocols/pages/tenant/VetProtocolsListPage.vue'),
    meta: { requiresAuth: true, title: 'Protocolos' },
  },
]
```
**Después:**
```ts
export const vetProtocolsRoutes: RouteRecordRaw[] = [
  {
    path: 'protocols',
    name: 'vet-protocols-list',
    component: () => import('@/modules/protocols/pages/tenant/VetProtocolsListPage.vue'),
    meta: { requiresAuth: true, title: 'Protocolos' },
  },
]
```

#### `front/src/modules/programs/router/vet-programs.routes.ts`
**Cambio:** ídem, `path: '/vets/:vetGuid/programs'` → `path: 'programs'`.

#### `front/src/modules/clients/router/clients.routes.ts`
**Cambio:** relativizar los 6 paths (DEC-02), manteniendo el orden y los comentarios de precedencia ya documentados en el archivo (críticos para que Vue Router no capture `new` como `:guid`).
**Antes → Después** (solo la columna `path`, el resto de cada objeto no cambia):
- `/vets/:vetGuid/clients` → `clients`
- `/vets/:vetGuid/clients/new` → `clients/new`
- `/vets/:vetGuid/clients/:clientGuid/staff/new` → `clients/:clientGuid/staff/new`
- `/vets/:vetGuid/clients/:clientGuid/staff/:profileGuid/edit` → `clients/:clientGuid/staff/:profileGuid/edit`
- `/vets/:vetGuid/clients/:guid` → `clients/:guid`
- `/vets/:vetGuid/clients/:guid/edit` → `clients/:guid/edit`

#### `front/src/modules/vets/router/vets-tenant.routes.ts`
**Cambio:** importar y spreadear `vetProtocolsRoutes`, `vetProgramsRoutes`, `clientsRoutes` dentro del `children` de la ruta `/vets/:vetGuid`, junto a las rutas existentes (`perfil`, `usuarios`, `mi-perfil`, `tutoriales`, `soporte`).
**Antes:** (extracto)
```ts
import type { RouteRecordRaw } from 'vue-router'
import { vetTenantGuard } from '@/router/guards/vetTenantGuard'

export const vetsTenantRoutes: RouteRecordRaw[] = [
  {
    path: '/vets/:vetGuid',
    component: () => import('@/components/layouts/VetTenantLayout.vue'),
    beforeEnter: vetTenantGuard,
    children: [
      { path: '', redirect: to => `/vets/${to.params.vetGuid}/perfil` },
      { path: 'perfil', ... },
      // ... resto de hijos existentes
    ],
  },
]
```
**Después:** (extracto, agregando el import y el spread)
```ts
import type { RouteRecordRaw } from 'vue-router'
import { vetTenantGuard } from '@/router/guards/vetTenantGuard'
import { vetProtocolsRoutes } from '@/modules/protocols/router/vet-protocols.routes'
import { vetProgramsRoutes } from '@/modules/programs/router/vet-programs.routes'
import { clientsRoutes } from '@/modules/clients/router/clients.routes'

export const vetsTenantRoutes: RouteRecordRaw[] = [
  {
    path: '/vets/:vetGuid',
    component: () => import('@/components/layouts/VetTenantLayout.vue'),
    beforeEnter: vetTenantGuard,
    children: [
      { path: '', redirect: to => `/vets/${to.params.vetGuid}/perfil` },
      { path: 'perfil', ... },
      // ... resto de hijos existentes (sin cambios)
      ...vetProtocolsRoutes,
      ...vetProgramsRoutes,
      ...clientsRoutes,
    ],
  },
]
```
Nota: el orden del spread no es crítico entre sí (paths distintos, sin ambigüedad), pero SÍ debe respetarse el orden interno ya existente dentro de `clientsRoutes` (`new` antes que `:guid`, `:clientGuid/staff/...` antes que `:guid`) — eso no cambia, solo se relativizan los `path`.

#### `front/src/router/index.ts`
**Cambio:** quitar `...vetProtocolsRoutes`, `...vetProgramsRoutes`, `...clientsRoutes` del `children` del bloque `AppLayout.vue`, y eliminar sus imports (ya no se usan ahí — ahora se importan dentro de `vets-tenant.routes.ts`).
**Antes:** (extracto)
```ts
import { clientsRoutes } from '@/modules/clients/router/clients.routes'
import { vetProtocolsRoutes } from '@/modules/protocols/router/vet-protocols.routes'
import { vetProgramsRoutes } from '@/modules/programs/router/vet-programs.routes'
// ...
children: [
    ...dashboardRoutes,
    ...usersRoutes,
    ...rolesRoutes,
    ...settingsRoutes,
    ...supportMessagesRoutes,
    ...tutorialsRoutes,
    ...systemSettingsRoutes,
    ...vetsRoutes,
    ...clientsRoutes,
    ...adminClientsRoutes,
    ...techniquesRoutes,
    ...vetProtocolsRoutes,
    ...vetProgramsRoutes,
    ...healthRoutes,
],
```
**Después:** (extracto)
```ts
// imports de clientsRoutes / vetProtocolsRoutes / vetProgramsRoutes eliminados de este archivo
// ...
children: [
    ...dashboardRoutes,
    ...usersRoutes,
    ...rolesRoutes,
    ...settingsRoutes,
    ...supportMessagesRoutes,
    ...tutorialsRoutes,
    ...systemSettingsRoutes,
    ...vetsRoutes,
    ...adminClientsRoutes,
    ...techniquesRoutes,
    ...healthRoutes,
],
```
`adminClientsRoutes` y `techniquesRoutes` NO se tocan — son módulos de scope admin (`/admin/clients`, catálogo de técnicas), confirmado por grep que no matchean el patrón `/vets/:vetGuid/...`, no sufren este bug.

#### `front/src/modules/vets/composables/useVetTenant.ts`
**Cambio:** exponer `isLoading` ya existe (no requiere cambio de firma), pero se agrega un comentario aclarando por qué el gateo del sidebar depende de este valor (documentación, no lógica nueva). Sin cambio funcional en este archivo.

#### `front/src/components/layouts/VetTenantLayout.vue`
**Cambio:** pasar `isLoading` (ya obtenido en la línea 27, `const { isLoading } = useVetTenant()`) como prop a `<AppSidebar>`.
**Antes:**
```vue
<AppSidebar v-model:collapsed="collapsed" />
```
**Después:**
```vue
<AppSidebar v-model:collapsed="collapsed" :tenant-context-loading="isLoading" />
```

#### `front/src/components/layouts/partials/AppSidebar.vue`
**Cambio:** aceptar la nueva prop y pasarla a `VetMenu` (default `false` para no afectar el uso desde `AppLayout.vue`, que no la pasa).
**Antes:**
```ts
defineProps<{ collapsed: boolean }>()
```
```vue
<VetMenu v-if="isVetContext" :collapsed="collapsed" />
```
**Después:**
```ts
withDefaults(defineProps<{ collapsed: boolean; tenantContextLoading?: boolean }>(), {
  tenantContextLoading: false,
})
```
```vue
<VetMenu v-if="isVetContext" :collapsed="collapsed" :tenant-context-loading="tenantContextLoading" />
```

#### `front/src/components/layouts/partials/VetMenu.vue`
**Cambio:** recibir la prop y usarla para no mostrar los bloques "Veterinaria"/"Reproducción" mientras el contexto tenant está cargando (DEC-03), en vez de decidir solo por `hasTenantContext`.
**Antes:**
```ts
defineProps<{ collapsed: boolean }>()
// ...
const visibleItems = computed(() =>
  hasTenantContext.value ? vetNavItems.value : [],
)
```
```vue
<template v-if="reproduccionNavItems.length">
```
**Después:**
```ts
const props = defineProps<{ collapsed: boolean; tenantContextLoading?: boolean }>()
// ...
const visibleItems = computed(() =>
  !props.tenantContextLoading && hasTenantContext.value ? vetNavItems.value : [],
)
const visibleReproduccionItems = computed(() =>
  props.tenantContextLoading ? [] : reproduccionNavItems.value,
)
```
```vue
<template v-if="visibleReproduccionItems.length">
```
(y el `v-for` de esa sección pasa a iterar `visibleReproduccionItems` en vez de `reproduccionNavItems`).
Nota: los bloques "Soporte" y "Mi perfil" NO se gatean — no dependen de `tenantPermissions`, y ocultarlos degradaría la UX sin necesidad (ya cargan bien hoy, confirmado por el propio ticket).

### Tests a generar

Frontend (`front/src/`, ubicar carpeta de test real del proyecto antes de escribir — confirmar si usa Vitest + `@vue/test-utils`, mismo patrón que otros módulos ya testeados):
- Test de router: navegar directamente (sin pasar por login/otra ruta previa) a `/vets/{guid}/protocols`, `/vets/{guid}/programs` y `/vets/{guid}/clients` y verificar que el componente montado es `VetTenantLayout.vue` (no `AppLayout.vue`) — esto es lo que prueba que el fix de ruteo realmente aplica, no solo que la página carga.
- Test de `vetTenantGuard`: verificar que se ejecuta (spy/mock) al navegar a `/vets/{guid}/protocols` tras el fix — antes del fix, este guard nunca corría para esa ruta.
- Test de `VetMenu.vue`: con `hasTenantContext = true` y `tenantContextLoading = true`, los bloques "Veterinaria"/"Reproducción" NO se renderizan; con `tenantContextLoading = false` y `hasTenantContext = true`, sí se renderizan; "Mensajes"/"Tutoriales"/"Mi perfil" se renderizan en ambos casos.
- Test de regresión manual (no automatizable fácilmente sin un entorno de sesión real): F5 en `/vets/{guid}/protocols` con sesión activa debe mostrar los 4 bloques del sidebar sin necesidad de navegar primero a `/vets/{guid}/perfil`.

## Cambios en BACKEND

Sin cambios backend en esta iteración — el bug es 100% de registro de rutas en el frontend. El contrato de permisos expuesto por el backend no se toca (cumple la restricción explícita del ticket).

## Orden de implementación

1. Relativizar paths en `front/src/modules/protocols/router/vet-protocols.routes.ts`.
2. Relativizar paths en `front/src/modules/programs/router/vet-programs.routes.ts`.
3. Relativizar paths en `front/src/modules/clients/router/clients.routes.ts` (DEC-02).
4. Actualizar `front/src/modules/vets/router/vets-tenant.routes.ts`: importar y spreadear las 3 rutas anteriores dentro de `children` de `/vets/:vetGuid`.
5. Actualizar `front/src/router/index.ts`: quitar los 3 imports y sus spreads del bloque `AppLayout`.
6. Verificar en el navegador (o test de router) que `/vets/{guid}/protocols`, `/vets/{guid}/programs` y `/vets/{guid}/clients` ahora resuelven bajo `VetTenantLayout.vue` y corren `vetTenantGuard`.
7. Gateo defensivo (DEC-03): `VetTenantLayout.vue` pasa `isLoading` → `AppSidebar.vue` recibe `tenantContextLoading` → `VetMenu.vue` lo usa para ocultar transitoriamente "Veterinaria"/"Reproducción".
8. Tests: router (montaje de layout correcto + guard ejecutándose), `VetMenu.vue` (prop `tenantContextLoading`).
9. QA manual: F5 y deep-link directo (pegar URL en una pestaña nueva con sesión ya autenticada) en `/protocols`, `/programs` y `/clients`, confirmando los 4 bloques del sidebar sin necesidad de navegar primero por otra ruta tenant. Adicionalmente, probar el caso de deep-link a un Vet B teniendo `tenantPermissions` de un Vet A persistido de la sesión anterior, confirmando que no se ve momentáneamente el menú del Vet A.

## Riesgos y consideraciones

- **`clients.routes.ts` no estaba en el alcance explícito del ticket, pero tiene el mismo bug confirmado por lectura de código.** Se decidió incluirlo (DEC-02) porque es el mismo defecto mecánico. Si el equipo prefiere separar el fix de clients en otro PR/ticket por temas de revisión o despliegue, es una decisión de proceso, no técnica — el código ya deja documentado por qué se agrupó.
- **No se revisó exhaustivamente si algún otro módulo futuro repite este patrón** (registrar `/vets/:vetGuid/...` fuera de `vetsTenantRoutes`). Se grepeó todo `front/src` buscando el literal `/vets/:vetGuid` y solo aparecieron `vets-tenant.routes.ts` (correcto), `clients.routes.ts`, `vet-protocols.routes.ts` y `vet-programs.routes.ts` (los 3 corregidos acá). Vale dejar como norma para `frontend-module-gen`/`ui-specialist`: toda ruta tenant nueva SIEMPRE debe registrarse dentro de `vetsTenantRoutes`, nunca como bloque top-level aparte — recomendar agregar esto a `.claude/skills/frontend-conventions.md` en una tarea separada (documentación, fuera de este plan).
- **El gateo de DEC-03 depende de que `isLoading` de `useVetProfile` no quede en `true` indefinidamente si el fetch falla.** Ya existe manejo de error en `useVetTenant.ts` (`watch(isError, ...)` redirige a `/dashboard` y notifica) — no se agrega nada nuevo ahí, solo se confirma que ese camino de error ya corta el loading (via el propio estado de `useQuery`, que pasa a `isLoading: false` al fallar). No debería generar un loading colgado.
- **No se toca `vetTenantGuard.ts` ni la falta de persistencia de `vetStore.userVets`.** Son válidos como observaciones (mencionadas en el ticket como candidatos), pero quedaron descartados como causa de ESTE bug porque el guard correcto ni siquiera se ejecutaba en las rutas afectadas. Una vez con el ruteo corregido, el guard sí corre en cada F5/deep-link tenant (como ya lo hace hoy correctamente en `/vets/{guid}/perfil`), y no se observó evidencia de que el fetch duplicado guard-vs-`useUserVets()` cause corrupción de estado — ambos usan la misma `queryKey` (`['user-vets']`) y el mismo `queryClient`, por lo que vue-query dedupea. Queda como posible micro-optimización a futuro (eliminar el fetch redundante en `useUserVets()` cuando el guard ya lo resolvió), pero no es necesaria para cerrar este ticket.
- **Verificar que mover `clientsRoutes` no rompe ningún link absoluto hardcodeado** (`router.push('/vets/.../clients')` en vez de `router.push({ name: 'clients-list' })`) en otros componentes — el path final generado por Vue Router es el mismo string (`/vets/:vetGuid/clients`) tanto si la ruta es top-level como si es hija relativa de `/vets/:vetGuid`, así que los links por path string no deberían romperse; los links por `name` tampoco cambian (los `name` no se tocan). Confirmar igual con una búsqueda de `router.push('/vets/` hardcodeado antes de mergear, por las dudas.

## Pendientes / fuera de alcance

- Auditoría completa de si algún otro módulo repite el patrón de registrar rutas tenant fuera de `vetsTenantRoutes` (se verificó solo por el literal `/vets/:vetGuid`, no se revisó módulo por módulo).
- Actualizar `.claude/skills/frontend-conventions.md` con la norma "toda ruta `/vets/:vetGuid/...` se registra dentro de `vetsTenantRoutes`" — documentación, no código.
- Eliminar el fetch redundante entre `vetTenantGuard.ts` y `useUserVets()` (mismo queryKey) — optimización menor, no bloqueante para este ticket.
- Persistir o no `vetStore.userVets` en Pinia — se dejó como está (no persistido), no es causa de este bug y cambiarlo sería tocar el diseño del store sin necesidad confirmada.
