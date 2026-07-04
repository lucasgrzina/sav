# Plan técnico: Módulos Frontend — Vets, Staff y Clients

## Input procesado

Brief informal del usuario (texto en chat). Tres módulos Vue 3 para SAV:
- `vets` — panel superadmin para gestión de veterinarias
- `staff` — panel tenant para miembros con rol `vet-*`
- `clients` — panel tenant para miembros con rol `client-*`

---

## Resumen ejecutivo

Se implementan tres feature modules en `front/src/modules/`. El módulo `vets` cubre el CRUD completo de veterinarias en el panel superadmin, con formulario en dos pasos (país → tipo de documento → datos), acciones de validar/suspender/reactivar, y páginas separadas para lista, detalle y edición siguiendo el patrón de `roles` (navegación por rutas, no modales). Los módulos `staff` y `clients` son idénticos en estructura pero filtran el endpoint unificado `GET /vets/{slug}/members` por prefijo de rol; ambos usan `BaseModal` para el formulario de agregar miembro. Los tres módulos comparten un store global `useVetStore` que centraliza el `vetSlug` activo y los datos básicos de la vet para el panel tenant, evitando prop drilling. No hay cambios en el backend.

---

## Decisiones tomadas

**DEC-01 — Navegación en módulo `vets`: páginas separadas vs. modales**
  Decisión: Páginas separadas para crear (`/admin/vets/new`), detalle (`/admin/vets/:guid`) y editar (`/admin/vets/:guid/edit`). La lista usa tabla como `RolesPage`.
  Justificación: El formulario de vet tiene lógica dependiente (país → tipos de documento) y el detalle necesita espacio para mostrar múltiples secciones de datos más los botones de acción. Un modal sería demasiado estrecho y los módulos `roles` ya establecen este patrón para formularios complejos.
  Alternativa descartada: Modales como en `users` — descartado porque `users` tiene formularios simples de ~6 campos; el formulario de vet incluye selects dependientes y estado visual de validación que se adaptan mejor a página completa.

**DEC-02 — Formulario de vet: componente único reutilizado en create y edit**
  Decisión: Un componente `VetForm.vue` con prop `mode: 'create' | 'edit'` que se usa en `VetCreatePage` y `VetEditPage`, idéntico al patrón `UserForm.vue`.
  Justificación: Los campos son los mismos en ambos modos; solo cambia el schema Zod aplicado (en edit `name` y `country_guid` son opcionales vía `sometimes`). Reutilizar reduce duplicación.
  Alternativa descartada: Dos componentes de formulario separados — descartado, es duplicación innecesaria.

**DEC-03 — Select de tipo de documento: dependencia reactiva del país seleccionado**
  Decisión: El select de `document_type_guid` se deshabilita hasta que `country_guid` tenga valor y dispara una query `useQuery` con `enabled: computed(() => !!countryGuid.value)`. Al cambiar país, se limpia el valor de `document_type_guid`.
  Justificación: El backend valida que `document_type_guid` pertenezca al país. Si el usuario cambia de país sin limpiar el tipo de documento, el backend retornaría 422. El comportamiento reactivo previene esto en el frontend.
  Alternativa descartada: Cargar todos los tipos de todos los países de una vez — descartado, escala mal con múltiples países.

**DEC-04 — Búsqueda de usuario para agregar miembro: campo `user_guid` directo en MVP**
  Decisión: El formulario de agregar miembro (staff y clients) acepta el `user_guid` directamente como campo de texto. No se implementa búsqueda por email en este plan.
  Justificación: Revisé el backend — `GET /api/v1/users` existe y acepta `search`, pero ese endpoint es del panel superadmin (`auth:sanctum` sin filtro de tenant). No hay endpoint de búsqueda de usuarios accesible desde el contexto tenant. Implementar búsqueda requeriría un nuevo endpoint backend fuera del alcance de este plan. El campo guid directo es funcional para el MVP.
  Alternativa descartada: Usar `GET /api/v1/users?search=email` desde el módulo tenant — descartado por riesgo de exposición cross-tenant (endpoint superadmin) y porque no está en el scope de este plan.

**DEC-05 — Store de vet activa: store global vs. composable**
  Decisión: Store Pinia `useVetStore` en `front/src/stores/vet.store.ts` (nivel global, fuera de módulos) que persiste el `vetSlug` y datos básicos de la vet actual del usuario autenticado.
  Justificación: Tanto `staff` como `clients` (y futuros módulos tenant) necesitan acceso al `vetSlug`. Si el store viviera dentro del módulo `staff`, `clients` no podría importarlo sin acoplamiento cruzado entre módulos. Un store global en `front/src/stores/` es el patrón correcto para estado compartido entre módulos.
  Alternativa descartada: Propagar `vetSlug` por props desde el router — descartado, genera prop drilling innecesario y el router ya lo tiene disponible como parámetro accesible desde cualquier composable con `useRoute`.

**DEC-06 — Filtrado staff/clients: en el frontend, no endpoint separado**
  Decisión: El componente de lista filtra en memoria sobre la respuesta de `GET /members` usando `role.name.startsWith('vet-')` para staff y `role.name.startsWith('client-')` para clients. No se piden endpoints separados al backend.
  Justificación: El backend ya fue diseñado así. La colección de miembros de una vet tendrá pocas decenas de registros en el peor caso — filtrar en memoria es eficiente. Añadir parámetros de filtro al endpoint requeriría cambios de backend fuera de scope.
  Alternativa descartada: Parámetro `role_prefix` en el endpoint — fuera de alcance de este plan.

**DEC-07 — Roles disponibles para staff/clients: constants hardcodeadas vs. desde API**
  Decisión: Los nombres de rol (`vet`, `vet-assistant`, `vet-administrative`, `client-owner`, `client-manager`, `client-administrative`) se hardcodean en constants de cada módulo para filtrar la respuesta de `GET /api/v1/roles`. Los guids se obtienen siempre de la API (no se hardcodean).
  Justificación: Los nombres de rol son parte del contrato del sistema (definidos en `UserProfileService::TENANT_ROLES` y `RoleSeeder`) — no van a cambiar sin una migración. Hardcodear nombres es seguro; hardcodear guids nunca lo es.
  Alternativa descartada: Mostrar todos los roles de la API sin filtrar — descartado, mostraría roles de superadmin (`admin`, `super-admin`) en el selector de miembro tenant.

**DEC-08 — Convención i18n: strings literales en español (en línea con el código real)**
  Decisión: Los templates usan strings literales en español, NO `$t()`. Los módulos existentes `users` y `roles` no usan i18n en absoluto — usan strings hardcodeados en los templates.
  Justificación: El agente `frontend-module-gen.md` indica i18n obligatorio, pero el código real (fuente de verdad) nunca usa `$t()`. Aplicar i18n solo a los módulos nuevos crearía inconsistencia. Se documenta como deuda técnica.
  Alternativa descartada: Agregar i18n en los tres módulos nuevos — descartado por inconsistencia con el código existente. Si se quiere migrar a i18n, debe hacerse en una iteración dedicada para todos los módulos.

**DEC-09 — Acciones de validar/suspender/reactivar: botones en `VetDetailPage`, con confirmación via `useConfirm`**
  Decisión: Los tres botones de acción viven en `VetDetailPage`. Cada uno usa `useConfirm` (el composable global ya existente) antes de ejecutar la mutación, igual que `useDeleteUser` y `useToggleLock`.
  Justificación: El patrón de confirmación con `useConfirm` es el estándar del proyecto. Los botones en la página de detalle (no en la lista) evitan acciones accidentales masivas.
  Alternativa descartada: Modal de confirmación con formulario de razón — fuera de scope del MVP; el backend no acepta un campo `reason`.

**DEC-10 — Badge de estado de vet: lógica derivada de `validated_at` y `suspended_at`**
  Decisión: Crear componente `VetStatusBadge.vue` con lógica: `suspended_at != null` → "Suspendida" (rojo), `validated_at == null` → "Pendiente validación" (amarillo), ambos presentes correctamente → "Activa" (verde). Esto replica la lógica `is_active` del `VetResource` backend.
  Justificación: El backend ya calcula `is_active` pero necesitamos también distinguir "Suspendida" de "Pendiente" — `is_active` es booleano y no distingue. El frontend debe leer los campos raw `validated_at`/`suspended_at`.
  Alternativa descartada: Usar solo `is_active` — descartado porque no permite mostrar los tres estados visuales distintos.

---

## Cambios en BACKEND

No requiere cambios en backend en esta iteración.

Observación: El endpoint `GET /api/v1/vets/{vetSlug}/members` no tiene parámetros de filtro de rol — la separación staff/clients se hace en el frontend (DEC-06). Si en el futuro se necesita, habría que agregar un filtro opcional `role_type=staff|client` al `MemberController@index`.

---

## Cambios en FRONTEND

### Archivos a crear — Store global compartido

#### `front/src/stores/vet.store.ts`
**Propósito:** Store Pinia global que almacena la vet activa del panel tenant, accesible desde cualquier módulo sin prop drilling.
**Firma principal:**
```typescript
import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { VetBasic } from '@/modules/vets/types/vet.types'

export const useVetStore = defineStore('vet', () => {
  const currentVet = ref<VetBasic | null>(null)
  const vetSlug = ref<string | null>(null)

  function setCurrentVet(vet: VetBasic) {
    currentVet.value = vet
    vetSlug.value = vet.slug
  }

  function clearCurrentVet() {
    currentVet.value = null
    vetSlug.value = null
  }

  return { currentVet, vetSlug, setCurrentVet, clearCurrentVet }
})
```
**Nota:** `VetBasic` es un subtipo de `VetResource` con solo `guid`, `name`, `slug` — suficiente para el contexto tenant.

---

### Módulo 1: `front/src/modules/vets/`

#### Estructura de directorios
```
front/src/modules/vets/
├── api/
│   ├── vets.api.ts
│   └── vets.mapper.ts
├── components/
│   ├── VetsTable.vue
│   ├── VetFilters.vue
│   ├── VetStatusBadge.vue
│   ├── VetActionButtons.vue
│   └── forms/
│       └── VetForm.vue
├── composables/
│   ├── useVets.ts
│   ├── useVet.ts
│   ├── useCreateVet.ts
│   ├── useUpdateVet.ts
│   ├── useValidateVet.ts
│   ├── useSuspendVet.ts
│   ├── useUnsuspendVet.ts
│   └── useVetFilters.ts
├── pages/
│   ├── VetsListPage.vue
│   ├── VetCreatePage.vue
│   ├── VetDetailPage.vue
│   └── VetEditPage.vue
├── router/
│   └── vets.routes.ts
├── stores/
│   └── vets-ui.store.ts
├── types/
│   ├── vet.types.ts
│   └── vet.enums.ts
└── validators/
    └── vet.validator.ts
```

#### `front/src/modules/vets/types/vet.types.ts`
```typescript
// Shape del VetResource backend (campos relevantes para el frontend)
export interface CountryItem {
  guid: string
  name: string
  iso_code: string
  phone_prefix: string
}

export interface DocumentTypeItem {
  guid: string
  name: string
  country?: CountryItem
}

// VetBasic: mínimo necesario para el store global del contexto tenant
export interface VetBasic {
  guid: string
  name: string
  slug: string
}

export interface VetItem {
  guid: string
  name: string
  slug: string
  tax_id: string
  registration_number: string | null
  logo_path: string | null
  pdf_title: string | null
  pdf_subtitle: string | null
  validated_at: string | null
  suspended_at: string | null
  is_active: boolean
  country?: CountryItem
  document_type?: DocumentTypeItem
  validated_by?: { guid: string; name: string } | null
  created_at: string
}

export type VetStatus = 'active' | 'pending' | 'suspended'

export interface VetListParams {
  search?: string
  validated?: boolean | ''
  suspended?: boolean | ''
  page?: number
  per_page?: number
}

export interface VetListResponse {
  data: VetItem[]
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface VetCreatePayload {
  name: string
  country_guid: string
  document_type_guid: string
  tax_id: string
  registration_number?: string | null
  logo_path?: string | null
  pdf_title?: string | null
  pdf_subtitle?: string | null
}

export interface VetUpdatePayload {
  name?: string
  document_type_guid?: string
  tax_id?: string
  registration_number?: string | null
  logo_path?: string | null
  pdf_title?: string | null
  pdf_subtitle?: string | null
}

export interface VetFilters {
  search?: string
  validated?: boolean | ''
  suspended?: boolean | ''
  page?: number
  per_page?: number
}
```

#### `front/src/modules/vets/types/vet.enums.ts`
```typescript
export const VET_STATUS_LABELS: Record<string, string> = {
  active:    'Activa',
  pending:   'Pendiente validación',
  suspended: 'Suspendida',
}

export const VET_STATUS_COLORS: Record<string, string> = {
  active:    'success',
  pending:   'warning',
  suspended: 'error',
}
```

#### `front/src/modules/vets/api/vets.api.ts`
```typescript
import { http } from '@/core/api/http'
import type {
  VetItem,
  VetListParams,
  VetListResponse,
  VetCreatePayload,
  VetUpdatePayload,
} from '../types/vet.types'

// --- Admin (superadmin) ---
export async function listVetsApi(params: VetListParams, signal?: AbortSignal): Promise<VetListResponse>
export async function createVetApi(payload: VetCreatePayload): Promise<VetItem>
export async function getVetApi(guid: string): Promise<VetItem>
export async function updateVetApi(guid: string, payload: VetUpdatePayload): Promise<VetItem>
export async function validateVetApi(guid: string): Promise<VetItem>
export async function suspendVetApi(guid: string): Promise<VetItem>
export async function unsuspendVetApi(guid: string): Promise<VetItem>

// Endpoints:
// GET    /v1/admin/vets
// POST   /v1/admin/vets
// GET    /v1/admin/vets/{guid}
// PUT    /v1/admin/vets/{guid}
// PATCH  /v1/admin/vets/{guid}/validate
// PATCH  /v1/admin/vets/{guid}/suspend
// PATCH  /v1/admin/vets/{guid}/unsuspend
```

#### `front/src/modules/vets/api/vets.mapper.ts`
```typescript
// Función auxiliar para derivar el VetStatus a partir de los timestamps raw
export function getVetStatus(vet: Pick<VetItem, 'validated_at' | 'suspended_at'>): VetStatus {
  if (vet.suspended_at) return 'suspended'
  if (!vet.validated_at) return 'pending'
  return 'active'
}
```

#### `front/src/modules/vets/validators/vet.validator.ts`
```typescript
import { z } from 'zod'

export const vetCreateSchema = z.object({
  name:                z.string().min(1, 'El nombre es requerido').max(150, 'Máximo 150 caracteres'),
  country_guid:        z.string().min(1, 'El país es requerido'),
  document_type_guid:  z.string().min(1, 'El tipo de documento es requerido'),
  tax_id:              z.string().min(1, 'El número fiscal es requerido').max(50, 'Máximo 50 caracteres'),
  registration_number: z.string().max(50, 'Máximo 50 caracteres').nullable().optional(),
  logo_path:           z.string().max(500).nullable().optional(),
  pdf_title:           z.string().max(200).nullable().optional(),
  pdf_subtitle:        z.string().max(200).nullable().optional(),
})

export const vetUpdateSchema = z.object({
  name:                z.string().min(1, 'El nombre es requerido').max(150, 'Máximo 150 caracteres').optional(),
  document_type_guid:  z.string().min(1, 'El tipo de documento es requerido').optional(),
  tax_id:              z.string().min(1, 'El número fiscal es requerido').max(50, 'Máximo 50 caracteres').optional(),
  registration_number: z.string().max(50, 'Máximo 50 caracteres').nullable().optional(),
  logo_path:           z.string().max(500).nullable().optional(),
  pdf_title:           z.string().max(200).nullable().optional(),
  pdf_subtitle:        z.string().max(200).nullable().optional(),
})

export type VetCreateForm = z.infer<typeof vetCreateSchema>
export type VetUpdateForm = z.infer<typeof vetUpdateSchema>
```

**Nota importante:** La validación del formato de `tax_id` contra el regex del `DocumentType` la hace el backend. El frontend no puede replicarla sin conocer los regexes de cada tipo de documento. Si el backend retorna 422 con el error de formato, se muestra via `fieldErrors` en el formulario. No agregar validación de formato en el schema Zod.

#### `front/src/modules/vets/stores/vets-ui.store.ts`
```typescript
// Solo UI state — sin datos de servidor
import { defineStore } from 'pinia'
import { ref } from 'vue'

export const useVetsUiStore = defineStore('vets-ui', () => {
  // No hay modales — la edición/creación son páginas separadas
  // Solo persiste los filtros activos mientras el usuario navega en la lista
  const activeFilters = ref<VetFilters>({
    search: '',
    validated: '',
    suspended: '',
    page: 1,
    per_page: 15,
  })

  function resetFilters() { /* ... */ }

  return { activeFilters, resetFilters }
})
```

#### `front/src/modules/vets/composables/useVets.ts`
```typescript
// Sigue el patrón exacto de useRoles.ts / useUsers.ts
export function useVets(filters: Ref<VetFilters> | VetFilters) {
  return useQuery({
    queryKey: ['vets', computed(() => toValue(filters))],
    queryFn: ({ signal }) => listVetsApi(toValue(filters), signal),
    staleTime: 1000 * 30,
  })
}
```

#### `front/src/modules/vets/composables/useVet.ts`
```typescript
export function useVet(guid: Ref<string> | string) {
  return useQuery({
    queryKey: ['vet', computed(() => toValue(guid))],
    queryFn: () => getVetApi(toValue(guid)),
    enabled: computed(() => Boolean(toValue(guid))),
  })
}
```

#### `front/src/modules/vets/composables/useCreateVet.ts`
Patrón idéntico a `useCreateUser.ts`:
- `mutationFn`: llama `createVetApi(payload)`
- `onSuccess`: `queryClient.invalidateQueries({ queryKey: ['vets'] })` + notificación
- `onError`: `parseApiError(err)` → `fieldErrors` y `generalError`
- Retorna: `{ ...mutation, fieldErrors, generalError, resetErrors }`

#### `front/src/modules/vets/composables/useUpdateVet.ts`
Patrón idéntico a `useUpdateUser.ts`:
- `mutationFn`: `({ guid, payload }) => updateVetApi(guid, payload)`
- `onSuccess`: invalida `['vets']` y `['vet', guid]`
- Retorna: `{ ...mutation, fieldErrors, generalError, resetErrors }`

#### `front/src/modules/vets/composables/useValidateVet.ts`
```typescript
export function useValidateVet() {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const confirm = useConfirm()

  const mutation = useMutation({
    mutationFn: (guid: string) => validateVetApi(guid),
    onSuccess: (_, guid) => {
      queryClient.invalidateQueries({ queryKey: ['vets'] })
      queryClient.invalidateQueries({ queryKey: ['vet', guid] })
      success('Veterinaria validada correctamente')
    },
    onError: () => error('Error al validar la veterinaria'),
  })

  async function validateVet(vet: VetItem) {
    await confirm.confirm({
      title: 'Validar veterinaria',
      message: `¿Confirmás la validación de "${vet.name}"? Una vez validada, la veterinaria podrá operar en el sistema.`,
      confirmLabel: 'Validar',
      onConfirm: () => mutation.mutateAsync(vet.guid),
    })
  }

  return { ...mutation, validateVet }
}
```

#### `front/src/modules/vets/composables/useSuspendVet.ts`
Mismo patrón que `useValidateVet` pero llama `suspendVetApi`. Mensaje de confirmación advierte sobre el impacto.

#### `front/src/modules/vets/composables/useUnsuspendVet.ts`
Mismo patrón. Sin confirmación destructiva (reactivar no destruye datos).

#### `front/src/modules/vets/composables/useVetFilters.ts`
Patrón idéntico a `useUserFilters.ts`:
- Usa `useTablePageSize('vets', 15)`
- Estado reactivo de filtros `VetFilters`
- `debouncedSearch` con 400ms
- Función `reset()`

#### `front/src/modules/vets/components/VetStatusBadge.vue`
```vue
<script setup lang="ts">
import { computed } from 'vue'
import { getVetStatus } from '../api/vets.mapper'
import { VET_STATUS_COLORS, VET_STATUS_LABELS } from '../types/vet.enums'

const props = defineProps<{
  validated_at: string | null
  suspended_at: string | null
}>()

const status = computed(() => getVetStatus(props))
const color  = computed(() => VET_STATUS_COLORS[status.value])
const label  = computed(() => VET_STATUS_LABELS[status.value])
</script>

<template>
  <a-tag :color="color">{{ label }}</a-tag>
</template>
```

#### `front/src/modules/vets/components/VetFilters.vue`
Props: `filters: VetFilters`. Emits: `update:filters`.
Controles:
- Input de búsqueda (search)
- Select "Estado de validación": Todos / Solo validadas / Solo sin validar (mapeado a `validated: '' | true | false`)
- Select "Estado de suspensión": Todos / Suspendidas / Activas (mapeado a `suspended: '' | true | false`)

Estructura usando `FiltersRow` / `FiltersCol` / `FiltersWrapper` exactamente como `UserFilters.vue`.

#### `front/src/modules/vets/components/VetsTable.vue`
Props: `vets: VetItem[]`, `loading: boolean`, `columns?: TableColumnDef[]`
Emits: `view: [vet: VetItem]`, `edit: [vet: VetItem]`

Columnas: nombre, país, estado (usa `VetStatusBadge`), fecha creación, acciones.
Acciones: botón Ver (navega a `/admin/vets/{guid}`), botón Editar (navega a `/admin/vets/{guid}/edit`).
Las acciones de validar/suspender/reactivar van en la página de detalle, no en la tabla.

Usa `BaseDataTable`, `BaseTableActions`, `formatDate`.

#### `front/src/modules/vets/components/VetActionButtons.vue`
Componente exclusivo de `VetDetailPage`. Recibe `vet: VetItem` y `loading: boolean`.
Muestra condicionalmente:
- Si `!validated_at && !suspended_at`: botón "Validar" (protegido con `PermissionGuard permission="vets.validate"`)
- Si `validated_at && !suspended_at`: botón "Suspender" (danger, `PermissionGuard`)
- Si `suspended_at`: botón "Reactivar" (warning, `PermissionGuard`)

Emits: `validate`, `suspend`, `unsuspend`

#### `front/src/modules/vets/components/forms/VetForm.vue`
Props:
```typescript
{
  mode: 'create' | 'edit'
  initialValues?: Partial<VetUpdatePayload>
  loading?: boolean
  fieldErrors?: Record<string, string> | null
}
```
Emits: `submit: [values: VetCreateForm | VetUpdateForm]`

Lógica interna:
1. `useForm` con `toTypedSchema(mode === 'create' ? vetCreateSchema : vetUpdateSchema)`
2. Campo `country_guid`: `a-select` con opciones de `useCountries()` (composable de carga única, `staleTime: Infinity`)
3. Campo `document_type_guid`: `a-select` deshabilitado hasta que `country_guid` tenga valor, carga desde `useDocumentTypes(countryGuid)` con `enabled: computed(() => !!countryGuid.value)`. Watch sobre `country_guid` → limpia `document_type_guid` al cambiar.
4. Resto de campos: `a-input` estándar.
5. En modo `edit`: `watch(() => props.initialValues, setValues, { immediate: true, deep: true })`.

#### `front/src/modules/vets/pages/VetsListPage.vue`
Orquesta: `AppHeader`, `VetFilters`, `VetsTable`, `BasePagination`, `ColumnSelectorDrawer`.
Botón "Nueva veterinaria" con `PermissionGuard permission="vets.create"` → navega a `/admin/vets/new`.
Patrón idéntico a `RolesPage.vue`.

#### `front/src/modules/vets/pages/VetCreatePage.vue`
`AppHeader` con título "Nueva veterinaria".
`VetForm mode="create"` con botones Cancelar (→ `/admin/vets`) y Crear.
`useCreateVet()` → `onSuccess: router.push('/admin/vets')`.
`a-alert` para `generalError`.

#### `front/src/modules/vets/pages/VetDetailPage.vue`
Recibe `guid` de la ruta. Usa `useVet(guid)`.
Secciones: datos generales (nombre, país, tipo doc, tax_id, registration_number), datos de PDF (pdf_title, pdf_subtitle), auditoría (validated_at, validated_by, suspended_at, created_at).
`VetActionButtons` con los composables `useValidateVet`, `useSuspendVet`, `useUnsuspendVet`.
`BaseSkeleton` mientras carga.
Botón "Editar" → navega a `/admin/vets/:guid/edit`.

#### `front/src/modules/vets/pages/VetEditPage.vue`
Recibe `guid` de la ruta. Usa `useVet(guid)` para cargar valores iniciales.
`VetForm mode="edit" :initial-values="vetData"` .
`useUpdateVet()` → `onSuccess: router.push(\`/admin/vets/${guid}\`)`.

#### `front/src/modules/vets/router/vets.routes.ts`
```typescript
import type { RouteRecordRaw } from 'vue-router'

export const vetsRoutes: RouteRecordRaw[] = [
  {
    path: '/admin/vets',
    name: 'vets-list',
    component: () => import('@/modules/vets/pages/VetsListPage.vue'),
    meta: { requiresAuth: true, title: 'Veterinarias' },
  },
  {
    path: '/admin/vets/new',
    name: 'vets-create',
    component: () => import('@/modules/vets/pages/VetCreatePage.vue'),
    meta: { requiresAuth: true, title: 'Nueva veterinaria' },
  },
  {
    path: '/admin/vets/:guid',
    name: 'vets-detail',
    component: () => import('@/modules/vets/pages/VetDetailPage.vue'),
    props: true,
    meta: { requiresAuth: true, title: 'Detalle de veterinaria' },
  },
  {
    path: '/admin/vets/:guid/edit',
    name: 'vets-edit',
    component: () => import('@/modules/vets/pages/VetEditPage.vue'),
    props: true,
    meta: { requiresAuth: true, title: 'Editar veterinaria' },
  },
]
```

---

### Módulo de datos compartidos: Countries y DocumentTypes

Estos composables son utilizados por el `VetForm` y deben vivir en un módulo compartido o en `vets/composables/`.

Decisión: Dado que solo `VetForm` los usa en esta iteración, se crean dentro del módulo `vets`. Si otros módulos los necesitan en el futuro, se mueven a `core/` o a un módulo `countries/`.

#### `front/src/modules/vets/composables/useCountries.ts`
```typescript
import { useQuery } from '@tanstack/vue-query'
import { listCountriesApi } from '../api/vets.api'

export function useCountries() {
  return useQuery({
    queryKey: ['countries'],
    queryFn: listCountriesApi,
    staleTime: Infinity, // los países no cambian
  })
}
```

#### `front/src/modules/vets/composables/useDocumentTypes.ts`
```typescript
import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import type { Ref } from 'vue'
import { listDocumentTypesApi } from '../api/vets.api'

export function useDocumentTypes(countryGuid: Ref<string> | string) {
  const guidRef = computed(() => toValue(countryGuid))

  return useQuery({
    queryKey: ['document-types', guidRef],
    queryFn: () => listDocumentTypesApi(guidRef.value),
    enabled: computed(() => Boolean(guidRef.value)),
    staleTime: 1000 * 60 * 10, // tipos de documento cambian raramente
  })
}
```

Agregar en `vets.api.ts`:
```typescript
export async function listCountriesApi(): Promise<CountryItem[]>
// GET /v1/countries

export async function listDocumentTypesApi(countryGuid: string): Promise<DocumentTypeItem[]>
// GET /v1/countries/{guid}/document-types
```

---

### Módulo 2: `front/src/modules/staff/`

#### Estructura de directorios
```
front/src/modules/staff/
├── api/
│   └── staff.api.ts
├── components/
│   ├── StaffTable.vue
│   └── modals/
│       └── AddMemberModal.vue
├── composables/
│   ├── useStaff.ts
│   ├── useAddMember.ts
│   ├── useRemoveMember.ts
│   └── useChangeMemberRole.ts
├── constants/
│   └── staff.constants.ts
├── pages/
│   └── StaffPage.vue
├── router/
│   └── staff.routes.ts
├── stores/
│   └── staff-ui.store.ts
├── types/
│   └── staff.types.ts
└── validators/
    └── staff.validator.ts
```

#### `front/src/modules/staff/types/staff.types.ts`
```typescript
// Reutiliza el shape de UserProfileResource del backend
export interface MemberUser {
  guid: string
  name: string
  first_name: string
  last_name: string
  email: string
}

export interface MemberRole {
  guid: string
  name: string
}

export interface MemberItem {
  guid: string        // guid del UserProfile (para eliminar / cambiar rol)
  user?: MemberUser
  role?: MemberRole
  created_at: string
}

export interface AddMemberPayload {
  user_guid: string   // guid del User (no del UserProfile)
  role_guid: string
}

export interface ChangeMemberRolePayload {
  role_guid: string
}
```

#### `front/src/modules/staff/constants/staff.constants.ts`
```typescript
// Nombres de rol que pertenecen al grupo "staff de veterinaria"
export const STAFF_ROLE_NAMES = ['vet', 'vet-assistant', 'vet-administrative'] as const

export const STAFF_ROLE_LABELS: Record<string, string> = {
  'vet':                'Veterinario',
  'vet-assistant':      'Asistente',
  'vet-administrative': 'Administrativo',
}

// Función de filtro: true si el miembro es del tipo staff
export function isStaffMember(member: { role?: { name: string } }): boolean {
  return member.role?.name?.startsWith('vet-') ?? member.role?.name === 'vet'
  // Más robusto: usar includes:
  // return STAFF_ROLE_NAMES.includes(member.role?.name as typeof STAFF_ROLE_NAMES[number])
}
```

**Nota sobre la función de filtro:** `startsWith('vet-')` no captura el rol `'vet'` (sin guion). Usar `STAFF_ROLE_NAMES.includes(member.role?.name)` es más correcto y mantenible.

#### `front/src/modules/staff/api/staff.api.ts`
```typescript
import { http } from '@/core/api/http'
import type { MemberItem, AddMemberPayload, ChangeMemberRolePayload } from '../types/staff.types'

// Nota: estos endpoints son COMPARTIDOS con el módulo clients.
// Ambos módulos llaman a los mismos endpoints; el filtrado es frontend.

export async function listMembersApi(vetSlug: string): Promise<MemberItem[]>
// GET /v1/vets/{vetSlug}/members

export async function addMemberApi(vetSlug: string, payload: AddMemberPayload): Promise<MemberItem>
// POST /v1/vets/{vetSlug}/members

export async function removeMemberApi(vetSlug: string, memberGuid: string): Promise<void>
// DELETE /v1/vets/{vetSlug}/members/{guid}

export async function changeMemberRoleApi(
  vetSlug: string,
  memberGuid: string,
  payload: ChangeMemberRolePayload
): Promise<MemberItem>
// PATCH /v1/vets/{vetSlug}/members/{guid}/role
```

**Importante:** Las funciones usan `vetSlug` como parámetro, no lo leen del store directamente. El composable que llama a la API es quien obtiene el slug del store. Esto mantiene el api layer puro y testeable.

#### `front/src/modules/staff/validators/staff.validator.ts`
```typescript
import { z } from 'zod'

export const addMemberSchema = z.object({
  user_guid: z.string().min(1, 'El GUID del usuario es requerido')
    .regex(/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i, 'Formato de GUID inválido'),
  role_guid: z.string().min(1, 'El rol es requerido'),
})

export type AddMemberForm = z.infer<typeof addMemberSchema>
```

#### `front/src/modules/staff/stores/staff-ui.store.ts`
```typescript
export const useStaffUiStore = defineStore('staff-ui', () => {
  const isAddMemberModalOpen = ref(false)

  function openAddMemberModal() { isAddMemberModalOpen.value = true }
  function closeAddMemberModal() { isAddMemberModalOpen.value = false }

  return { isAddMemberModalOpen, openAddMemberModal, closeAddMemberModal }
})
```

#### `front/src/modules/staff/composables/useStaff.ts`
```typescript
import { useQuery } from '@tanstack/vue-query'
import { computed } from 'vue'
import { useVetStore } from '@/stores/vet.store'
import { listMembersApi } from '../api/staff.api'
import { isStaffMember } from '../constants/staff.constants'

export function useStaff() {
  const vetStore = useVetStore()

  const { data: rawMembers, isLoading, isError, refetch } = useQuery({
    queryKey: ['members', computed(() => vetStore.vetSlug)],
    queryFn: () => listMembersApi(vetStore.vetSlug!),
    enabled: computed(() => Boolean(vetStore.vetSlug)),
    staleTime: 1000 * 30,
  })

  // Filtrado en frontend: solo miembros con rol vet-*
  const staffMembers = computed(() => (rawMembers.value ?? []).filter(isStaffMember))

  return { data: staffMembers, isLoading, isError, refetch }
}
```

**Clave de query compartida:** Ambos módulos `staff` y `clients` usan la misma queryKey `['members', vetSlug]`. Esto es intencional — cuando se agrega o elimina un miembro desde cualquiera de los dos módulos, la invalidación de esa key refresca la lista en ambos. El filtrado se aplica después de obtener los datos del cache.

#### `front/src/modules/staff/composables/useAddMember.ts`
```typescript
export function useAddMember() {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const vetStore = useVetStore()
  const fieldErrors = ref<Record<string, string> | null>(null)
  const generalError = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: (payload: AddMemberPayload) => addMemberApi(vetStore.vetSlug!, payload),
    onMutate: () => { fieldErrors.value = null; generalError.value = null },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['members', vetStore.vetSlug] })
      success('Miembro agregado correctamente')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      fieldErrors.value = apiError.fieldErrors
      generalError.value = apiError.message ?? 'Error al agregar el miembro.'
      if (apiError.message) error('Error al agregar el miembro')
    },
  })

  function resetErrors() { /* ... */ }

  return { ...mutation, fieldErrors, generalError, resetErrors }
}
```

#### `front/src/modules/staff/composables/useRemoveMember.ts`
```typescript
export function useRemoveMember() {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const confirm = useConfirm()
  const vetStore = useVetStore()

  const mutation = useMutation({
    mutationFn: (memberGuid: string) => removeMemberApi(vetStore.vetSlug!, memberGuid),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['members', vetStore.vetSlug] })
      success('Miembro eliminado correctamente')
    },
    onError: () => error('Error al eliminar el miembro'),
  })

  async function removeMember(member: MemberItem) {
    await confirm.confirm({
      title: 'Eliminar miembro',
      message: `¿Estás seguro de que querés eliminar a "${member.user?.name ?? member.guid}" del equipo? Esta acción no se puede deshacer.`,
      confirmLabel: 'Eliminar',
      danger: true,
      onConfirm: () => mutation.mutateAsync(member.guid),
    })
  }

  return { ...mutation, removeMember }
}
```

#### `front/src/modules/staff/composables/useChangeMemberRole.ts`
```typescript
export function useChangeMemberRole() {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const vetStore = useVetStore()

  const mutation = useMutation({
    mutationFn: ({ memberGuid, roleGuid }: { memberGuid: string; roleGuid: string }) =>
      changeMemberRoleApi(vetStore.vetSlug!, memberGuid, { role_guid: roleGuid }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['members', vetStore.vetSlug] })
      success('Rol actualizado correctamente')
    },
    onError: () => error('Error al cambiar el rol'),
  })

  return mutation
}
```

#### `front/src/modules/staff/components/StaffTable.vue`
Props: `members: MemberItem[]`, `loading: boolean`
Emits: `remove: [member: MemberItem]`, `change-role: [member: MemberItem, roleGuid: string]`

Columnas: nombre completo, email, rol (selector inline `a-select` con opciones de `STAFF_ROLE_LABELS`), fecha de incorporación, acciones (botón eliminar).

El cambio de rol es inline: `a-select` directamente en la celda que emite `change-role` al cambiar. No usa modal separado.

Usa `BaseDataTable`, `BaseTableActions`.

#### `front/src/modules/staff/components/modals/AddMemberModal.vue`
Envuelve `BaseModal` con título "Agregar miembro al equipo".
Usa `useAddMember()`.
Formulario con Vee-Validate + `addMemberSchema`:
- Campo "GUID del usuario" (texto libre, placeholder: "ej: a1b2c3d4-...")
- Campo "Rol" (select con opciones de roles staff, guids obtenidos de `useRoles({ per_page: 100 })` filtrados por `STAFF_ROLE_NAMES`)
- `a-alert` para `generalError`
- Botones Cancelar / Agregar

#### `front/src/modules/staff/pages/StaffPage.vue`
```vue
<script setup lang="ts">
import { computed } from 'vue'
import { PlusOutlined } from '@ant-design/icons-vue'
import StaffTable from '../components/StaffTable.vue'
import AddMemberModal from '../components/modals/AddMemberModal.vue'
import { useStaff } from '../composables/useStaff'
import { useRemoveMember } from '../composables/useRemoveMember'
import { useChangeMemberRole } from '../composables/useChangeMemberRole'
import { useStaffUiStore } from '../stores/staff-ui.store'

const uiStore = useStaffUiStore()
const { data, isLoading } = useStaff()
const { removeMember } = useRemoveMember()
const { mutate: changeRole } = useChangeMemberRole()

const showAddModal = computed({
  get: () => uiStore.isAddMemberModalOpen,
  set: (v) => { if (!v) uiStore.closeAddMemberModal() },
})
</script>

<template>
  <div>
    <AppHeader title="Equipo veterinario" subtitle="Gestioná los miembros del equipo.">
      <template #actions="{ buttonSize }">
        <BaseButton :size="buttonSize" @click="uiStore.openAddMemberModal()">
          <template #icon><PlusOutlined /></template>
          Agregar miembro
        </BaseButton>
      </template>
    </AppHeader>

    <StaffTable
      :members="data"
      :loading="isLoading"
      @remove="removeMember"
      @change-role="({ member, roleGuid }) => changeRole({ memberGuid: member.guid, roleGuid })"
    />

    <AddMemberModal v-model="showAddModal" />
  </div>
</template>
```

#### `front/src/modules/staff/router/staff.routes.ts`
```typescript
import type { RouteRecordRaw } from 'vue-router'

export const staffRoutes: RouteRecordRaw[] = [
  {
    path: '/vet/:vetSlug/staff',
    name: 'staff',
    component: () => import('@/modules/staff/pages/StaffPage.vue'),
    meta: { requiresAuth: true, title: 'Equipo veterinario' },
  },
]
```

---

### Módulo 3: `front/src/modules/clients/`

#### Estructura de directorios
```
front/src/modules/clients/
├── api/
│   └── clients.api.ts     ← reutiliza las mismas funciones que staff.api.ts
├── components/
│   ├── ClientsTable.vue
│   └── modals/
│       └── AddClientModal.vue
├── composables/
│   ├── useClients.ts
│   ├── useAddClient.ts
│   ├── useRemoveClient.ts
│   └── useChangeClientRole.ts
├── constants/
│   └── clients.constants.ts
├── pages/
│   └── ClientsPage.vue
├── router/
│   └── clients.routes.ts
├── stores/
│   └── clients-ui.store.ts
├── types/
│   └── client.types.ts
└── validators/
    └── client.validator.ts
```

El módulo `clients` es estructuralmente idéntico a `staff` con tres diferencias:

1. **`clients.constants.ts`**: roles filtrados son `client-owner`, `client-manager`, `client-administrative`. Labels: Propietario, Gerente, Administrativo.
2. **`useClients.ts`**: filtra por `isClientMember` en lugar de `isStaffMember` (usa `CLIENTS_ROLE_NAMES.includes(role.name)`).
3. **`clients.api.ts`**: re-exporta las mismas funciones de `staff.api.ts` para mantener independencia de módulo (o bien las re-implementa idénticamente — ver nota en Riesgos).

**`front/src/modules/clients/types/client.types.ts`**
Idéntico a `staff.types.ts`. Puede crear un alias o re-exportar, pero dado que son módulos separados para facilitar permisología futura, se duplica el tipo para independencia de módulo.

**`front/src/modules/clients/validators/client.validator.ts`**
Idéntico a `staff.validator.ts`.

**`front/src/modules/clients/router/clients.routes.ts`**
```typescript
export const clientsRoutes: RouteRecordRaw[] = [
  {
    path: '/vet/:vetSlug/clients',
    name: 'clients',
    component: () => import('@/modules/clients/pages/ClientsPage.vue'),
    meta: { requiresAuth: true, title: 'Clientes' },
  },
]
```

---

### Archivo a modificar: `front/src/router/index.ts`

**Cambio:** Importar y registrar las tres rutas nuevas dentro del grupo `AppLayout`.

**Antes (resumido):**
```typescript
import { usersRoutes } from '@/modules/users/router/users.routes'
// ...
children: [
  ...dashboardRoutes,
  ...usersRoutes,
  ...rolesRoutes,
  // ...
]
```

**Después:**
```typescript
import { vetsRoutes } from '@/modules/vets/router/vets.routes'
import { staffRoutes } from '@/modules/staff/router/staff.routes'
import { clientsRoutes } from '@/modules/clients/router/clients.routes'
// ...
children: [
  ...dashboardRoutes,
  ...usersRoutes,
  ...rolesRoutes,
  ...vetsRoutes,
  ...staffRoutes,
  ...clientsRoutes,
  // ...
]
```

### Archivo a modificar: `front/src/core/constants/permissions.ts`

**Cambio:** Agregar las constantes de permisos de vets.

**Agregar:**
```typescript
VETS_READ:     'vets.read',
VETS_CREATE:   'vets.create',
VETS_UPDATE:   'vets.update',
VETS_DELETE:   'vets.delete',
VETS_VALIDATE: 'vets.validate',
```

---

## Orden de implementación

1. Crear `front/src/stores/vet.store.ts` (store global compartido por staff y clients).

2. Crear `front/src/modules/vets/types/vet.types.ts` y `vet.enums.ts`.

3. Crear `front/src/modules/vets/api/vets.api.ts` (incluye `listCountriesApi` y `listDocumentTypesApi`) y `vets.mapper.ts`.

4. Crear `front/src/modules/vets/validators/vet.validator.ts`.

5. Crear `front/src/modules/vets/stores/vets-ui.store.ts`.

6. Crear composables del módulo `vets` en este orden:
   - `useCountries.ts`
   - `useDocumentTypes.ts`
   - `useVetFilters.ts`
   - `useVets.ts`
   - `useVet.ts`
   - `useCreateVet.ts`
   - `useUpdateVet.ts`
   - `useValidateVet.ts`
   - `useSuspendVet.ts`
   - `useUnsuspendVet.ts`

7. Crear componentes del módulo `vets` en este orden:
   - `VetStatusBadge.vue`
   - `VetFilters.vue`
   - `VetsTable.vue`
   - `VetActionButtons.vue`
   - `forms/VetForm.vue` (el más complejo — depende de `useCountries` y `useDocumentTypes`)

8. Crear páginas del módulo `vets`:
   - `VetsListPage.vue`
   - `VetCreatePage.vue`
   - `VetDetailPage.vue`
   - `VetEditPage.vue`

9. Crear `front/src/modules/vets/router/vets.routes.ts`.

10. Actualizar `front/src/core/constants/permissions.ts` con las constantes de vets.

11. Registrar `vetsRoutes` en `front/src/router/index.ts`.

12. Crear `front/src/modules/staff/types/staff.types.ts`.

13. Crear `front/src/modules/staff/constants/staff.constants.ts`.

14. Crear `front/src/modules/staff/api/staff.api.ts`.

15. Crear `front/src/modules/staff/validators/staff.validator.ts`.

16. Crear `front/src/modules/staff/stores/staff-ui.store.ts`.

17. Crear composables del módulo `staff`:
    - `useStaff.ts`
    - `useAddMember.ts`
    - `useRemoveMember.ts`
    - `useChangeMemberRole.ts`

18. Crear componentes del módulo `staff`:
    - `StaffTable.vue`
    - `modals/AddMemberModal.vue`

19. Crear `front/src/modules/staff/pages/StaffPage.vue`.

20. Crear `front/src/modules/staff/router/staff.routes.ts`.

21. Registrar `staffRoutes` en `front/src/router/index.ts`.

22. Crear el módulo `clients` completo, replicando la estructura de `staff` con los cambios indicados:
    - `client.types.ts`, `clients.constants.ts`, `clients.api.ts`, `client.validator.ts`, `clients-ui.store.ts`
    - `useClients.ts`, `useAddClient.ts`, `useRemoveClient.ts`, `useChangeClientRole.ts`
    - `ClientsTable.vue`, `modals/AddClientModal.vue`
    - `ClientsPage.vue`
    - `clients.routes.ts`

23. Registrar `clientsRoutes` en `front/src/router/index.ts`.

24. Verificar compilación TypeScript: `npm run type-check` desde `front/`.

---

## Riesgos y consideraciones

**R01 — Carga del `vetSlug` en el store antes de que `StaffPage` o `ClientsPage` se monten.**
El store `useVetStore` se inicializa vacío. Cuando el usuario navega directamente a `/vet/mi-vet/staff`, el store puede estar vacío si viene de un refresh de página. La solución es que el layout del panel tenant (o un navigation guard en las rutas `staff` y `clients`) hidrate el store leyendo el `vetSlug` del parámetro de ruta y llamando a `GET /api/v1/vets/{vetSlug}` si `currentVet.value` es null. Este plan asume que el layout ya hidrata el store; si no existe esa lógica, debe agregarse como paso previo a las rutas tenant. **Esto es un supuesto crítico que debe verificarse.**

**R02 — QueryKey compartida `['members', vetSlug]` entre staff y clients.**
Ventaja: la invalidación es automática en ambos módulos. Riesgo: si hay dos instancias de la página montadas simultáneamente (improbable), podrían compartir cache incorrectamente. En la arquitectura SPA de SAV con una ruta activa a la vez, esto no es un problema real.

**R03 — Campo `user_guid` en el formulario de agregar miembro (MVP).**
El flujo actual requiere que el operador conozca el GUID del usuario a agregar. Esto es aceptable para un MVP interno donde el superadmin puede obtener el GUID desde el panel `/users`. En producción con múltiples operadores, esto es una UX deficiente que debe resolverse con un endpoint de búsqueda de usuarios accesible desde el contexto tenant. Se documenta como deuda técnica prioritaria.

**R04 — Roles disponibles en el selector de agregar miembro.**
Los guids de los roles `vet-*` y `client-*` se obtienen de `GET /api/v1/roles`. Este endpoint es del panel superadmin y retorna TODOS los roles. El módulo staff/clients debe filtrar por nombre en el resultado. Si el backend cambia la permisología de `GET /api/v1/roles` para que los usuarios tenant no puedan acceder, el selector quedaría vacío. A futuro, conviene un endpoint `GET /api/v1/vets/{slug}/available-roles` o similar.

**R05 — Validación de `tax_id` vs. regex del DocumentType.**
El backend valida el formato del `tax_id` contra el `validation_regex` del `DocumentType` seleccionado. El frontend no puede replicar esta validación sin conocer todos los regexes. Si el backend retorna 422, el error se muestra via `fieldErrors`, pero el usuario puede no entender por qué el formato es inválido. Para mejorar la UX, el `DocumentTypeItem` podría incluir el `validation_regex` en su resource para que el frontend construya la validación en tiempo real. Esto requeriría un cambio en `DocumentTypeResource` — fuera del scope actual.

**R06 — Discrepancia entre agente `frontend-module-gen` e implementación real respecto a i18n.**
El agente oficial del proyecto indica que i18n es obligatorio. El código real no lo usa. Este plan sigue el código real (DEC-08). Si el equipo decide adoptar i18n, debe hacerse en una iteración dedicada para todos los módulos, no de forma incremental en módulos nuevos.

**R07 — `isStaffMember` con el rol `'vet'` (sin prefijo `vet-`).**
El rol `'vet'` no empieza con `'vet-'` sino que es exactamente `'vet'`. La función `startsWith('vet-')` lo excluiría. Usar `STAFF_ROLE_NAMES.includes(role.name)` con la constante tipada es más robusto. Se documenta explícitamente en la constante para que no se pierda en la implementación.

**R08 — Ruta `/vet/:vetSlug/staff` vs. layout activo.**
Las rutas de staff y clients usan `/vet/:vetSlug/...` pero el `router/index.ts` actual tiene un único grupo `AppLayout` para todas las rutas autenticadas. Verificar que el `AppLayout` maneje correctamente el parámetro `vetSlug` en la URL o que se cree un layout específico de panel tenant en el futuro.

**R09 — Módulo `clients` duplica código de `staff`.**
Los dos módulos son casi idénticos. La duplicación es intencional (DEC-06) para facilitar permisología granular futura, pero introduce deuda de mantenimiento. Si los permisos nunca se diferencian, conviene fusionarlos en un módulo `members` con prop `type: 'staff' | 'client'`.

**R10 — El `VetForm` en modo edit no incluye `country_guid` (backend no lo acepta en `UpdateVetRequest`).**
El `UpdateVetRequest` del backend usa `sometimes` para la mayoría de campos y directamente omite `country_guid` — no se puede cambiar el país de una vet existente. El `VetForm` en modo edit NO debe mostrar el select de país ni el de tipo de documento dependiente del país (o mostrarlos como solo lectura). El `vetUpdateSchema` no incluye `country_guid`. El formulario de edición debe aclarar visualmente que el país no es editable.

---

## Pendientes / fuera de alcance

- **Upload de logo**: El campo `logo_path` en el formulario acepta texto (URL). La funcionalidad de subir archivos al storage queda para una iteración posterior.

- **Búsqueda de usuario por email para agregar miembro**: Requiere un nuevo endpoint backend accesible desde el contexto tenant. Ver R03.

- **Guard de permiso por ruta**: Las rutas de vets deberían bloquear el acceso si el usuario no tiene `vets.read`, no solo ocultar botones con `PermissionGuard`. Agregar `beforeEnter` con verificación de permiso es una mejora que queda fuera de este plan.

- **Panel tenant: hidratación del store `useVetStore` desde el layout**: El layout del panel tenant (si existe o cuando se cree) debe hidratar `useVetStore` al montar. Este plan diseña el store pero no implementa el layout.

- **Módulo de perfil de vet**: `GET /api/v1/vets/{vetSlug}` y `PUT /api/v1/vets/{vetSlug}` para que la vet edite sus propios datos desde el panel tenant — fuera de scope, ese es un módulo diferente al panel superadmin de vets.

- **Paginación en staff/clients**: El endpoint `GET /members` no tiene paginación — retorna todos los miembros. Si una vet tiene muchos miembros en el futuro, habrá que agregar paginación al backend y al frontend. Por ahora, la lista completa es aceptable.

- **Tests**: No se diseñaron tests en este plan. Los casos a cubrir para cada módulo serían:
  - `vets`: happy path CRUD, validación de formulario (campos requeridos), estados de badge (3 estados), acciones validate/suspend/unsuspend con confirmación.
  - `staff`/`clients`: filtrado correcto de miembros por tipo de rol, agregar miembro (éxito y 422 con fieldErrors), eliminar con confirmación, cambio de rol inline.
