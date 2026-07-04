# Plan técnico: Módulo Frontend — Clients ABM

## Input procesado

Brief informal del usuario provisto en el chat (2026-06-07).
Módulo Vue 3 + TypeScript para el ABM de Clients dentro del panel de una vet,
con flujo de lookup/create/link, gestión de establecimientos, contactos y owners.

---

## Resumen ejecutivo

Se implementa el módulo `front/src/modules/clients/` completo: tipos, API layer, validators Zod,
composables Vue Query, store Pinia de UI, componentes de form, tabla y páginas.
Las rutas viven bajo `/vets/:vetSlug/clients` y consumen los 19 endpoints del backend ya
implementados. El flujo más complejo es el de creación/vinculación de un client, que maneja
tres estados en un único componente `ClientLookupForm`. Las sub-secciones (establecimientos,
contactos, owners) se muestran dentro de la página de detalle del client usando pestañas o
secciones plegables, sin rutas propias en esta iteración. El módulo también requiere crear
el store global `front/src/stores/vet.store.ts` — verificado que NO existe todavía en el repo.

---

## Decisiones tomadas

### DEC-01 — Sub-secciones (establecimientos, contactos, owners) dentro de ClientDetailPage, no como rutas propias

**Decisión:** Los datos de establecimientos, contactos y owners se muestran como secciones/tabs
dentro de `ClientDetailPage.vue`, no como páginas separadas con rutas propias.

**Justificación:** El volumen de datos es limitado (una vet puede tener decenas de establecimientos
y contactos por client, no miles). Mostrar todo en la página de detalle reduce la fricción de
navegación y es el patrón que ya usa `VetDetailPage` para sus secciones. Rutas separadas
agregarían 6+ entradas de router sin beneficio real para el usuario.

**Alternativa descartada:** Rutas `/clients/:guid/establishments`, `/clients/:guid/contacts`,
`/clients/:guid/owners` — sobreingeniería para el volumen de datos en juego.

---

### DEC-02 — Flujo lookup/create/link como componente único `ClientLookupForm` con estado de máquina

**Decisión:** Se crea un componente `ClientLookupForm.vue` que maneja los tres estados:
`idle` → `searching` → `found` (sub-estados: `link` si no vinculado, `already-linked` si ya lo está)
o `not-found` → formulario de creación completa.
El estado se maneja con un `ref<LookupState>` local al componente, no en un store.

**Justificación:** El flujo es lineal y el estado es efímero (no persiste entre navegaciones).
Un store Pinia para esto sería sobrediseño. Un estado local tipado con una union type es más
legible y testeable.

**Alternativa descartada:** Dos páginas separadas (search + create) — el UX del brief es
explícitamente "en la misma pantalla, el resultado del lookup decide qué mostrar".

---

### DEC-03 — Edición de client: página separada `/clients/:guid/edit`

**Decisión:** La edición de datos básicos del client va en una página separada `ClientEditPage.vue`
(misma convención que `VetEditPage`). No es un modal inline en la página de detalle.

**Justificación:** El formulario de edición tiene los mismos campos que el de creación (nombre,
tax_id, dirección, país, tipo de documento) más la lógica de select dependiente
(país → tipo de documento). Un modal sería estrecho e incómodo. El patrón del proyecto es
páginas separadas para formularios complejos.

**Alternativa descartada:** Modal de edición inline — mismo argumento que DEC-01 del plan
`frontend-modules-vets-staff-clients-plan.md` para VetForm.

---

### DEC-04 — Establecimientos y contactos se crean/editan mediante modales dentro de ClientDetailPage

**Decisión:** Para crear o editar un establecimiento o un contacto se usa un `BaseModal`
(patrón ya existente en el proyecto). El modal recibe el `guid` del client del componente padre.

**Justificación:** Establecimientos y contactos tienen formularios simples (3-5 campos), idóneos
para modal. No justifican páginas dedicadas. Este patrón es el mismo que usa el módulo
`staff` y `support-messages` para acciones acotadas.

**Alternativa descartada:** Páginas separadas para establecimientos — sobreingeniería, los datos
son demasiado simples para merecer su propia ruta.

---

### DEC-05 — Owners se crean mediante modal con formulario email + nombre + apellido

**Decisión:** El modal de creación de owner acepta `email`, `first_name`, `last_name` y llama
a `POST /owners`. No incluye búsqueda previa de usuario (no hay endpoint de búsqueda por email
accesible desde el contexto tenant).

**Justificación:** El contrato del endpoint backend (`clients-module-plan.md`, DEC-06) es
idéntico: si el email no existe crea el User + envía invitación. El frontend no necesita
distinguir ese caso — el backend lo maneja transparentemente.

**Alternativa descartada:** Búsqueda por email previa — requiere un endpoint que no existe en
el backend tenant. Ver R03 del plan `frontend-modules-vets-staff-clients-plan.md`.

---

### DEC-06 — `vetSlug` se lee de `useRoute().params.vetSlug` en los composables, NO del store global

**Decisión:** Los composables de clients leen el `vetSlug` directamente desde `useRoute()`,
no desde un `useVetStore`. El store global `vet.store.ts` se crea igualmente (para otros usos
futuros del panel tenant), pero este módulo no depende de él para el slug.

**Justificación:** El store global `vet.store.ts` NO existe aún en el repo (verificado). Si el
módulo `clients` dependiera de él, tendría un supuesto de hidratación (R01 del plan anterior)
que introduciría un bug silencioso en refresh de página. Leer el slug del router es síncrono,
no requiere hidratación y es la fuente de verdad canónica cuando el slug está en la URL.

**Alternativa descartada:** Depender de `useVetStore` — introduciría el riesgo de store vacío
en refresh de página que el plan anterior documentó como R01 crítico y que aún no tiene
solución implementada.

---

### DEC-07 — QueryKey de clients incluye vetSlug para aislamiento de cache entre tenants

**Decisión:** Todas las queryKeys del módulo clients incluyen el `vetSlug`:
`['clients', vetSlug, filters]`, `['client', vetSlug, guid]`,
`['client-establishments', vetSlug, clientGuid]`, etc.

**Justificación:** Si el usuario navega entre dos vets distintas en la misma sesión sin
hacer full reload, Vue Query podría servir datos del cache de la Vet A para la Vet B si el
slug no forma parte de la key. Incluir el slug garantiza caches aislados por tenant.
Es la misma razón por la que el plan anterior usaba `['members', vetSlug]`.

**Alternativa descartada:** QueryKey sin vetSlug — riesgo de cross-tenant en cache (falla de
seguridad UX, no de API, pero igual grave para multi-tenant).

---

### DEC-08 — Contactos del client: se reutiliza el tipo `ContactItem` definido aquí (no hay módulo contacts)

**Decisión:** Se define `ContactItem` en `client.types.ts` como tipo local del módulo. No se
crea un módulo `contacts/` separado porque los contactos de client son un sub-recurso sin
página propia.

**Justificación:** En el repo no existe ningún módulo `contacts/` ni tipo `ContactItem` en
`core/`. Si en el futuro otros módulos necesitan contactos (ej: establecimientos de otras
entidades), se mueve a `core/`. Por ahora el tipo vive donde se usa.

**Alternativa descartada:** Módulo `contacts/` independiente — sobreingeniería para un tipo
de 5 campos sin lógica de negocio compleja en el frontend.

---

### DEC-09 — DELETE client = "Desvincular de esta vet", con confirmación

**Decisión:** El botón de eliminar client en la tabla y en el detalle llama a
`DELETE /v1/vets/{vet}/clients/{guid}` con confirmación previa via `useConfirm`. El label del
botón y del diálogo dice "Desvincular" (no "Eliminar") para reflejar que el client sigue
existiendo en el sistema.

**Justificación:** `DELETE` en este endpoint es una desvinculación del pivot (DEC-06 del plan
`client-establishment-backend-plan.md`), no un borrado físico. Usar el término "Eliminar"
sería confuso para el usuario.

**Alternativa descartada:** Botón "Eliminar" — terminología incorrecta que puede causar
confusión al operador.

---

### DEC-10 — Paginación en la lista de clients; sub-recursos (establecimientos, contactos, owners) sin paginación

**Decisión:** `GET /clients` (lista principal) usa paginación con `BasePagination`. Los endpoints
`/establishments`, `/contacts` y `/owners` NO usan paginación en el frontend (se muestran todos
en la sección correspondiente dentro del detalle).

**Justificación:** El endpoint de lista tiene paginación en el backend. Los sub-recursos
retornan colecciones pequeñas (sin paginación backend documentada en el brief) y se renderizan
en secciones de la página de detalle donde mostrar todos es el comportamiento esperado.

---

### DEC-11 — `ClientForm` acepta `mode: 'create' | 'edit'` igual que `VetForm`

**Decisión:** Un único `ClientForm.vue` con prop `mode` maneja creación y edición. En modo
`create` es parte del flujo `ClientLookupForm` (aparece cuando lookup retorna `found: false`).
En modo `edit` se usa en `ClientEditPage`.

**Justificación:** Patrón idéntico a `VetForm.vue` — reduce duplicación y mantiene coherencia.

---

## Cambios en BACKEND

No requiere cambios en backend en esta iteración. Todos los endpoints necesarios ya están
implementados o planificados en:
- `client-establishment-backend-plan.md` (CRUD base + establecimientos + contactos)
- `clients-module-plan.md` (lookup + link + owners)

---

## Cambios en FRONTEND

### Archivos a crear

---

#### `front/src/stores/vet.store.ts`

**Propósito:** Store Pinia global que persiste la vet activa del panel tenant. Necesario para
que layouts y otros módulos accedan al contexto actual sin prop drilling.

**Firma principal:**
```typescript
import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { VetBasic } from '@/modules/vets/types/vet.types'

export const useVetStore = defineStore('vet', () => {
  const currentVet = ref<VetBasic | null>(null)

  function setCurrentVet(vet: VetBasic): void {
    currentVet.value = vet
  }

  function clearCurrentVet(): void {
    currentVet.value = null
  }

  return { currentVet, setCurrentVet, clearCurrentVet }
})
```

**Nota:** `VetBasic` (`{ guid, name, slug }`) ya existe en `front/src/modules/vets/types/vet.types.ts`.
Este módulo no lo lee para el slug (ver DEC-06), pero se crea para uso futuro del layout tenant.

---

#### `front/src/modules/clients/types/client.types.ts`

**Propósito:** Todos los tipos TypeScript del módulo clients.

**Shape completo:**
```typescript
import type { CountryItem, DocumentTypeItem } from '@/modules/vets/types/vet.types'
import type { PaginatedResponse } from '@/core/types/pagination.types'

// --- Sub-tipos reutilizados en múltiples interfaces ---

export interface ContactItem {
  guid: string
  type: string           // 'phone' | 'email' | 'whatsapp' | etc. — string libre, backend lo define
  value: string
  label: string | null
  is_primary: boolean
  use_for_alerts: boolean
}

export interface EstablishmentItem {
  guid: string
  name: string
  renspa: string | null   // identificador AR; null en otros países
  address: string | null
  city: string | null
  state: string | null
  zip_code: string | null
  latitude: number | null
  longitude: number | null
  created_at: string
}

export interface OwnerItem {
  guid: string            // guid del UserProfile
  user: {
    guid: string
    name: string
    first_name: string
    last_name: string
    email: string
  }
  role: {
    guid: string
    name: string          // 'client-owner'
  }
  created_at: string
}

// --- Client principal ---

export interface ClientItem {
  guid: string
  name: string
  tax_id: string
  address: string | null
  city: string | null
  state: string | null
  zip_code: string | null
  country?: CountryItem
  document_type?: DocumentTypeItem
  contacts: ContactItem[]
  created_at: string
}

export interface ClientDetail extends ClientItem {
  establishments: EstablishmentItem[]
}

// --- Params y responses ---

export interface ClientListParams {
  search?: string
  page?: number
  per_page?: number
}

export type ClientListResponse = PaginatedResponse<ClientItem>

// --- Payloads para mutaciones ---

export interface ClientCreatePayload {
  name: string
  country_guid: string
  document_type_guid: string
  tax_id: string
  address?: string | null
  city?: string | null
  state?: string | null
  zip_code?: string | null
  contacts?: Array<{
    type: string
    value: string
    label?: string | null
    is_primary?: boolean
    use_for_alerts?: boolean
  }>
}

export interface ClientUpdatePayload {
  name?: string
  tax_id?: string
  address?: string | null
  city?: string | null
  state?: string | null
  zip_code?: string | null
}

export interface EstablishmentCreatePayload {
  name: string
  renspa?: string | null
  address?: string | null
  city?: string | null
  state?: string | null
  zip_code?: string | null
  latitude?: number | null
  longitude?: number | null
}

export type EstablishmentUpdatePayload = Partial<EstablishmentCreatePayload>

export interface ContactCreatePayload {
  type: string
  value: string
  label?: string | null
  is_primary?: boolean
  use_for_alerts?: boolean
}

export type ContactUpdatePayload = Partial<ContactCreatePayload>

export interface OwnerCreatePayload {
  email: string
  first_name: string
  last_name: string
}

// --- Tipo del resultado del lookup ---

export type LookupResult =
  | { found: false; client: null }
  | { found: true; already_linked: boolean; client: ClientItem }

// --- Filtros para la lista ---

export interface ClientFilters {
  search?: string
  page?: number
  per_page?: number
}
```

---

#### `front/src/modules/clients/api/clients.api.ts`

**Propósito:** Todas las funciones de llamada a la API REST del módulo clients. Sin lógica de
negocio — solo http + tipado.

**Firma principal (todas las funciones):**
```typescript
import { http } from '@/core/api/http'
import type {
  ClientItem, ClientDetail, ClientListParams, ClientListResponse,
  ClientCreatePayload, ClientUpdatePayload,
  EstablishmentItem, EstablishmentCreatePayload, EstablishmentUpdatePayload,
  ContactItem, ContactCreatePayload, ContactUpdatePayload,
  OwnerItem, OwnerCreatePayload,
  LookupResult,
} from '../types/client.types'

// Base URL: `/v1/vets/${vetSlug}/clients`

export async function listClientsApi(
  vetSlug: string,
  params: ClientListParams,
  signal?: AbortSignal,
): Promise<ClientListResponse>
// GET /v1/vets/{vetSlug}/clients

export async function getClientApi(
  vetSlug: string,
  guid: string,
): Promise<ClientDetail>
// GET /v1/vets/{vetSlug}/clients/{guid}

export async function createClientApi(
  vetSlug: string,
  payload: ClientCreatePayload,
): Promise<ClientItem>
// POST /v1/vets/{vetSlug}/clients

export async function updateClientApi(
  vetSlug: string,
  guid: string,
  payload: ClientUpdatePayload,
): Promise<ClientItem>
// PUT /v1/vets/{vetSlug}/clients/{guid}

export async function unlinkClientApi(
  vetSlug: string,
  guid: string,
): Promise<void>
// DELETE /v1/vets/{vetSlug}/clients/{guid}

export async function lookupClientApi(
  vetSlug: string,
  taxId: string,
): Promise<LookupResult>
// GET /v1/vets/{vetSlug}/clients/lookup?tax_id={taxId}

export async function linkClientApi(
  vetSlug: string,
  guid: string,
): Promise<ClientItem>
// POST /v1/vets/{vetSlug}/clients/{guid}/link

// --- Establecimientos ---

export async function listEstablishmentsApi(
  vetSlug: string,
  clientGuid: string,
): Promise<EstablishmentItem[]>
// GET /v1/vets/{vetSlug}/clients/{guid}/establishments

export async function createEstablishmentApi(
  vetSlug: string,
  clientGuid: string,
  payload: EstablishmentCreatePayload,
): Promise<EstablishmentItem>
// POST /v1/vets/{vetSlug}/clients/{guid}/establishments

export async function updateEstablishmentApi(
  vetSlug: string,
  clientGuid: string,
  estGuid: string,
  payload: EstablishmentUpdatePayload,
): Promise<EstablishmentItem>
// PUT /v1/vets/{vetSlug}/clients/{guid}/establishments/{estGuid}

export async function deleteEstablishmentApi(
  vetSlug: string,
  clientGuid: string,
  estGuid: string,
): Promise<void>
// DELETE /v1/vets/{vetSlug}/clients/{guid}/establishments/{estGuid}

// --- Contactos ---

export async function listContactsApi(
  vetSlug: string,
  clientGuid: string,
): Promise<ContactItem[]>
// GET /v1/vets/{vetSlug}/clients/{guid}/contacts

export async function createContactApi(
  vetSlug: string,
  clientGuid: string,
  payload: ContactCreatePayload,
): Promise<ContactItem>
// POST /v1/vets/{vetSlug}/clients/{guid}/contacts

export async function updateContactApi(
  vetSlug: string,
  clientGuid: string,
  contactGuid: string,
  payload: ContactUpdatePayload,
): Promise<ContactItem>
// PUT /v1/vets/{vetSlug}/clients/{guid}/contacts/{contactGuid}

export async function deleteContactApi(
  vetSlug: string,
  clientGuid: string,
  contactGuid: string,
): Promise<void>
// DELETE /v1/vets/{vetSlug}/clients/{guid}/contacts/{contactGuid}

// --- Owners ---

export async function listOwnersApi(
  vetSlug: string,
  clientGuid: string,
): Promise<OwnerItem[]>
// GET /v1/vets/{vetSlug}/clients/{guid}/owners

export async function createOwnerApi(
  vetSlug: string,
  clientGuid: string,
  payload: OwnerCreatePayload,
): Promise<OwnerItem>
// POST /v1/vets/{vetSlug}/clients/{guid}/owners
```

**Implementación de `lookupClientApi`:** La respuesta tiene forma `{ success: true, data: { found, already_linked?, client? } }`.
El http layer del proyecto unwrappea `data` automáticamente (verificar con `http.ts`), por lo
que la función retorna directamente el objeto `{ found, ... }`. Si el http layer NO hace
unwrap, usar `res.data.data`.

---

#### `front/src/modules/clients/validators/client.validator.ts`

**Propósito:** Schemas Zod para todos los formularios del módulo.

**Schemas:**
```typescript
import { z } from 'zod'

// Creación de client (usado en ClientLookupForm cuando found=false)
export const clientCreateSchema = z.object({
  name:                z.string().min(1, 'El nombre es requerido').max(200, 'Máximo 200 caracteres'),
  country_guid:        z.string().min(1, 'El país es requerido'),
  document_type_guid:  z.string().min(1, 'El tipo de documento es requerido'),
  tax_id:              z.string().min(1, 'El identificador fiscal es requerido').max(30, 'Máximo 30 caracteres'),
  address:             z.string().max(255, 'Máximo 255 caracteres').nullable().optional(),
  city:                z.string().max(100, 'Máximo 100 caracteres').nullable().optional(),
  state:               z.string().max(100, 'Máximo 100 caracteres').nullable().optional(),
  zip_code:            z.string().max(20, 'Máximo 20 caracteres').nullable().optional(),
})

// Edición de client (todos los campos opcionales)
export const clientUpdateSchema = z.object({
  name:     z.string().min(1, 'El nombre es requerido').max(200, 'Máximo 200 caracteres').optional(),
  tax_id:   z.string().min(1, 'El identificador fiscal es requerido').max(30).optional(),
  address:  z.string().max(255).nullable().optional(),
  city:     z.string().max(100).nullable().optional(),
  state:    z.string().max(100).nullable().optional(),
  zip_code: z.string().max(20).nullable().optional(),
})

// Creación/edición de establecimiento
export const establishmentSchema = z.object({
  name:      z.string().min(1, 'El nombre es requerido').max(200, 'Máximo 200 caracteres'),
  renspa:    z.string().max(50, 'Máximo 50 caracteres').nullable().optional(),
  address:   z.string().max(255).nullable().optional(),
  city:      z.string().max(100).nullable().optional(),
  state:     z.string().max(100).nullable().optional(),
  zip_code:  z.string().max(20).nullable().optional(),
  latitude:  z.number().min(-90).max(90).nullable().optional(),
  longitude: z.number().min(-180).max(180).nullable().optional(),
})

// Creación de owner
export const ownerCreateSchema = z.object({
  email:      z.string().min(1, 'El email es requerido').email('Formato de email inválido').max(255),
  first_name: z.string().min(1, 'El nombre es requerido').max(100),
  last_name:  z.string().min(1, 'El apellido es requerido').max(100),
})

// Creación de contacto
export const contactSchema = z.object({
  type:           z.string().min(1, 'El tipo de contacto es requerido'),
  value:          z.string().min(1, 'El valor es requerido').max(255),
  label:          z.string().max(100).nullable().optional(),
  is_primary:     z.boolean().optional(),
  use_for_alerts: z.boolean().optional(),
})

export type ClientCreateForm      = z.infer<typeof clientCreateSchema>
export type ClientUpdateForm      = z.infer<typeof clientUpdateSchema>
export type EstablishmentForm     = z.infer<typeof establishmentSchema>
export type OwnerCreateForm       = z.infer<typeof ownerCreateSchema>
export type ContactForm           = z.infer<typeof contactSchema>
```

---

#### `front/src/modules/clients/stores/clients-ui.store.ts`

**Propósito:** Estado de UI de la lista de clients (filtros, paginación, debounce de búsqueda).
Patrón idéntico a `vets-ui.store.ts`.

**Firma principal:**
```typescript
import { defineStore } from 'pinia'
import { reactive, toRef, watch, type Ref } from 'vue'
import { useDebounce } from '@/core/composables/useDebounce'
import { useTablePageSize } from '@/modules/settings/composables/useTablePageSize'
import type { ClientFilters } from '../types/client.types'

export const useClientsUiStore = defineStore('clients-ui', () => {
  const { perPage: storedPerPage, setPerPage } = useTablePageSize('clients', 15)

  const filters = reactive<ClientFilters>({
    search: '',
    page: 1,
    per_page: storedPerPage.value,
  })

  const searchRef  = toRef(filters, 'search')
  const debouncedSearch = useDebounce(searchRef as Ref<string>, 400)

  watch(() => filters.per_page, (size) => {
    if (size !== undefined) setPerPage(size)
  })

  function reset(): void {
    filters.search   = ''
    filters.page     = 1
    filters.per_page = storedPerPage.value
  }

  return { filters, debouncedSearch, reset }
})
```

---

#### `front/src/modules/clients/composables/useClients.ts`

**Propósito:** Query paginada de la lista de clients de una vet.

**Firma:**
```typescript
import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import { useRoute } from 'vue-router'
import type { Ref } from 'vue'
import { listClientsApi } from '../api/clients.api'
import type { ClientFilters } from '../types/client.types'

export function useClients(filters: Ref<ClientFilters> | ClientFilters = {}) {
  const route       = useRoute()
  const vetSlug     = computed(() => route.params.vetSlug as string)
  const filtersRef  = computed(() => toValue(filters))

  return useQuery({
    queryKey: ['clients', vetSlug, filtersRef],
    queryFn:  ({ signal }) => listClientsApi(vetSlug.value, filtersRef.value, signal),
    enabled:  computed(() => Boolean(vetSlug.value)),
    staleTime: 1000 * 30,
  })
}
```

---

#### `front/src/modules/clients/composables/useClient.ts`

**Propósito:** Query de detalle de un client (incluye establecimientos en el response).

**Firma:**
```typescript
export function useClient(clientGuid: Ref<string> | string) {
  const route   = useRoute()
  const vetSlug = computed(() => route.params.vetSlug as string)
  const guid    = computed(() => toValue(clientGuid))

  return useQuery({
    queryKey: ['client', vetSlug, guid],
    queryFn:  () => getClientApi(vetSlug.value, guid.value),
    enabled:  computed(() => Boolean(vetSlug.value) && Boolean(guid.value)),
  })
}
```

---

#### `front/src/modules/clients/composables/useClientEstablishments.ts`

**Propósito:** Query de la lista de establecimientos de un client.

**Firma:**
```typescript
export function useClientEstablishments(clientGuid: Ref<string> | string) {
  const route   = useRoute()
  const vetSlug = computed(() => route.params.vetSlug as string)
  const guid    = computed(() => toValue(clientGuid))

  return useQuery({
    queryKey: ['client-establishments', vetSlug, guid],
    queryFn:  () => listEstablishmentsApi(vetSlug.value, guid.value),
    enabled:  computed(() => Boolean(vetSlug.value) && Boolean(guid.value)),
    staleTime: 1000 * 60,
  })
}
```

---

#### `front/src/modules/clients/composables/useClientContacts.ts`

**Propósito:** Query de la lista de contactos de un client.

**Firma:**
```typescript
export function useClientContacts(clientGuid: Ref<string> | string) {
  // Misma estructura que useClientEstablishments
  // queryKey: ['client-contacts', vetSlug, guid]
  // queryFn:  () => listContactsApi(vetSlug.value, guid.value)
  // staleTime: 1000 * 60
}
```

---

#### `front/src/modules/clients/composables/useClientOwners.ts`

**Propósito:** Query de la lista de owners de un client.

**Firma:**
```typescript
export function useClientOwners(clientGuid: Ref<string> | string) {
  // queryKey: ['client-owners', vetSlug, guid]
  // queryFn:  () => listOwnersApi(vetSlug.value, guid.value)
  // staleTime: 1000 * 60
}
```

---

#### `front/src/modules/clients/composables/useLookupClient.ts`

**Propósito:** Encapsula la llamada al endpoint de lookup (búsqueda por tax_id).
Es una query disparada manualmente (no automática al montar), usando `refetch` o `enabled` con trigger.

**Decisión de implementación:** Se modela como una `useQuery` con `enabled: false` y se dispara
via `refetch()` al presionar el botón de búsqueda. Esto aprovecha el cache de Vue Query y evita
llamadas duplicadas si el usuario busca el mismo CUIT dos veces.

**Firma:**
```typescript
import { useQuery } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import { useRoute } from 'vue-router'
import { lookupClientApi } from '../api/clients.api'

export function useLookupClient() {
  const route    = useRoute()
  const vetSlug  = computed(() => route.params.vetSlug as string)
  const taxId    = ref<string>('')
  const enabled  = ref(false)

  const query = useQuery({
    queryKey: ['client-lookup', vetSlug, taxId],
    queryFn:  () => lookupClientApi(vetSlug.value, taxId.value),
    enabled:  computed(() => enabled.value && Boolean(taxId.value)),
    staleTime: 0,   // lookup nunca se sirve desde cache — siempre busca fresco
    retry: false,
  })

  function search(newTaxId: string): void {
    taxId.value   = newTaxId
    enabled.value = true
  }

  function reset(): void {
    taxId.value   = ''
    enabled.value = false
    query.remove()
  }

  return { ...query, taxId, search, reset }
}
```

---

#### `front/src/modules/clients/composables/useCreateClient.ts`

**Propósito:** Mutation para crear un client nuevo y auto-vincularlo a la vet.

**Firma:**
```typescript
export function useCreateClient() {
  const queryClient   = useQueryClient()
  const { success, error } = useNotification()
  const route         = useRoute()
  const vetSlug       = computed(() => route.params.vetSlug as string)
  const fieldErrors   = ref<Record<string, string> | null>(null)
  const generalError  = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: (payload: ClientCreatePayload) => createClientApi(vetSlug.value, payload),
    onMutate:   () => { fieldErrors.value = null; generalError.value = null },
    onSuccess:  () => {
      queryClient.invalidateQueries({ queryKey: ['clients', vetSlug.value] })
      success('Cliente creado correctamente')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      fieldErrors.value  = apiError.fieldErrors
      generalError.value = apiError.message ?? 'Error al crear el cliente.'
      if (apiError.message) error('Error al crear el cliente')
    },
  })

  function resetErrors(): void {
    fieldErrors.value  = null
    generalError.value = null
    mutation.reset()
  }

  return { ...mutation, fieldErrors, generalError, resetErrors }
}
```

---

#### `front/src/modules/clients/composables/useLinkClient.ts`

**Propósito:** Mutation para vincular un client existente a la vet actual.

**Firma:**
```typescript
export function useLinkClient() {
  const queryClient   = useQueryClient()
  const { success, error } = useNotification()
  const route         = useRoute()
  const vetSlug       = computed(() => route.params.vetSlug as string)
  const generalError  = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: (clientGuid: string) => linkClientApi(vetSlug.value, clientGuid),
    onMutate:   () => { generalError.value = null },
    onSuccess:  () => {
      queryClient.invalidateQueries({ queryKey: ['clients', vetSlug.value] })
      success('Cliente vinculado correctamente')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      generalError.value = apiError.message ?? 'Error al vincular el cliente.'
      error(generalError.value)
    },
  })

  function resetErrors(): void {
    generalError.value = null
    mutation.reset()
  }

  return { ...mutation, generalError, resetErrors }
}
```

---

#### `front/src/modules/clients/composables/useUpdateClient.ts`

**Propósito:** Mutation para actualizar datos básicos de un client.

**Firma:**
```typescript
// mutationFn: ({ guid, payload }: { guid: string; payload: ClientUpdatePayload }) =>
//   updateClientApi(vetSlug.value, guid, payload)
// onSuccess: invalida ['clients', vetSlug] y ['client', vetSlug, guid]
// Retorna: { ...mutation, fieldErrors, generalError, resetErrors }
```

---

#### `front/src/modules/clients/composables/useUnlinkClient.ts`

**Propósito:** Mutation para desvincular (DELETE) un client de la vet, con confirmación previa.

**Firma:**
```typescript
export function useUnlinkClient() {
  const queryClient   = useQueryClient()
  const { success, error } = useNotification()
  const confirm       = useConfirm()
  const route         = useRoute()
  const vetSlug       = computed(() => route.params.vetSlug as string)

  const mutation = useMutation({
    mutationFn: (guid: string) => unlinkClientApi(vetSlug.value, guid),
    onSuccess:  () => {
      queryClient.invalidateQueries({ queryKey: ['clients', vetSlug.value] })
      success('Cliente desvinculado correctamente')
    },
    onError: () => error('Error al desvincular el cliente'),
  })

  async function unlinkClient(client: ClientItem): Promise<void> {
    await confirm.confirm({
      title:        'Desvincular cliente',
      message:      `¿Estás seguro de que querés desvincular a "${client.name}" de esta veterinaria? El cliente seguirá existiendo en el sistema.`,
      confirmLabel: 'Desvincular',
      danger:       true,
      onConfirm:    () => mutation.mutateAsync(client.guid),
    })
  }

  return { ...mutation, unlinkClient }
}
```

---

#### `front/src/modules/clients/composables/useCreateEstablishment.ts`

**Propósito:** Mutation para crear un establecimiento dentro de un client.

**Firma:**
```typescript
// mutationFn: ({ clientGuid, payload }) => createEstablishmentApi(vetSlug, clientGuid, payload)
// onSuccess:  invalida ['client-establishments', vetSlug, clientGuid]
//             + invalida ['client', vetSlug, clientGuid] (el detalle incluye establecimientos)
// Retorna: { ...mutation, fieldErrors, generalError, resetErrors }
```

---

#### `front/src/modules/clients/composables/useUpdateEstablishment.ts`

**Propósito:** Mutation para actualizar un establecimiento.

```typescript
// mutationFn: ({ clientGuid, estGuid, payload }) =>
//   updateEstablishmentApi(vetSlug, clientGuid, estGuid, payload)
// onSuccess:  invalida ['client-establishments', vetSlug, clientGuid]
```

---

#### `front/src/modules/clients/composables/useDeleteEstablishment.ts`

**Propósito:** Mutation para eliminar un establecimiento, con confirmación.

```typescript
// Igual que useUnlinkClient pero llama deleteEstablishmentApi
// confirmLabel: 'Eliminar'
// danger: true
```

---

#### `front/src/modules/clients/composables/useCreateContact.ts`

**Propósito:** Mutation para crear un contacto de un client.
```typescript
// mutationFn: ({ clientGuid, payload }) => createContactApi(vetSlug, clientGuid, payload)
// onSuccess:  invalida ['client-contacts', vetSlug, clientGuid]
//             + invalida ['client', vetSlug, clientGuid]
```

---

#### `front/src/modules/clients/composables/useUpdateContact.ts`

```typescript
// mutationFn: ({ clientGuid, contactGuid, payload }) =>
//   updateContactApi(vetSlug, clientGuid, contactGuid, payload)
// onSuccess: invalida ['client-contacts', vetSlug, clientGuid]
```

---

#### `front/src/modules/clients/composables/useDeleteContact.ts`

```typescript
// Con confirmación previa via useConfirm
// mutationFn: ({ clientGuid, contactGuid }) =>
//   deleteContactApi(vetSlug, clientGuid, contactGuid)
```

---

#### `front/src/modules/clients/composables/useCreateOwner.ts`

**Propósito:** Mutation para crear un owner (invitar usuario por email).

```typescript
// mutationFn: ({ clientGuid, payload }) => createOwnerApi(vetSlug, clientGuid, payload)
// onSuccess:  invalida ['client-owners', vetSlug, clientGuid]
//             notificación: 'Owner creado. Se envió una invitación al email indicado.'
// Retorna: { ...mutation, fieldErrors, generalError, resetErrors }
```

---

#### `front/src/modules/clients/components/ClientsTable.vue`

**Propósito:** Tabla paginada de clients con acciones por fila.

**Props:**
```typescript
{
  clients:  ClientItem[]
  loading:  boolean
  columns?: TableColumnDef[]
}
```

**Columnas:** nombre (bold), tax_id, país, fecha de alta, acciones.

**Acciones por fila:**
- Botón "Ver detalle" (icono EyeOutlined) → navega a `/vets/:vetSlug/clients/:guid`
- Botón "Editar" (icono EditOutlined, `PermissionGuard permission="clients.update"`)
  → navega a `/vets/:vetSlug/clients/:guid/edit`
- Botón "Desvincular" (icono UnlinkOutlined o DeleteOutlined, danger,
  `PermissionGuard permission="clients.delete"`) → llama `$emit('unlink', client)`

**Emits:** `unlink: [client: ClientItem]`

Usa `BaseDataTable`, `BaseTableActions`, `PermissionGuard`, `formatDate`.

---

#### `front/src/modules/clients/components/forms/ClientForm.vue`

**Propósito:** Formulario reutilizable para crear y editar un client (modo `'create'` o `'edit'`).
Patrón idéntico a `VetForm.vue`.

**Props:**
```typescript
{
  mode:           'create' | 'edit'
  initialValues?: Partial<ClientItem>
  loading?:       boolean
  fieldErrors?:   Record<string, string> | null
}
```

**Emits:** `submit: [values: ClientCreateForm | ClientUpdateForm]`

**Campos:**
- `name` (a-input, requerido)
- `tax_id` (a-input, requerido; en modo edit es editable)
- `country_guid` (a-select con `useCountries()`, solo en modo `create`; en `edit` se muestra
  como texto readonly — el backend no permite cambiar país)
- `document_type_guid` (a-select dependiente de `country_guid` via `useDocumentTypes()`,
  solo en modo `create`)
- `address`, `city`, `state`, `zip_code` (a-input, opcionales)

**Lógica interna:**
1. `useForm` con `toTypedSchema(mode === 'create' ? clientCreateSchema : clientUpdateSchema)`
2. En modo `edit`: `watch(() => props.initialValues, setValues, { immediate: true, deep: true })`
3. En modo `create`: watch `country_guid` → limpiar `document_type_guid`
4. En modo `edit`: mostrar país como campo de texto no editable (igual que `VetForm` deshabilitaba el select en edit)

Usa `FormSection`, `FormFooter` (componentes globales del proyecto).

---

#### `front/src/modules/clients/components/ClientLookupForm.vue`

**Propósito:** Componente de búsqueda por tax_id que maneja el flujo completo de
lookup → link | create. Es la pieza central de `ClientCreatePage.vue`.

**Estado interno (máquina de estados con union type):**
```typescript
type LookupState =
  | { status: 'idle' }
  | { status: 'searching' }
  | { status: 'found-linkable'; client: ClientItem }
  | { status: 'found-linked'; client: ClientItem }
  | { status: 'not-found'; taxId: string }
  | { status: 'creating' }
  | { status: 'done' }
```

**Lógica del flujo:**
1. Campo de texto con el tax_id + botón "Buscar".
2. Al presionar "Buscar": `state = { status: 'searching' }`, llama `useLookupClient().search(taxId)`.
3. Al recibir resultado del lookup:
   - `found: false` → `state = { status: 'not-found', taxId }` → se muestra `ClientForm mode="create"` con `tax_id` pre-poblado.
   - `found: true, already_linked: false` → `state = { status: 'found-linkable', client }` → se muestra tarjeta con datos del client + botón "Vincular a esta veterinaria".
   - `found: true, already_linked: true` → `state = { status: 'found-linked', client }` → se muestra tarjeta con datos + mensaje "Este cliente ya está vinculado a esta veterinaria." + botón "Ver detalle".
4. Al presionar "Vincular": llama `useLinkClient().mutate(client.guid)`, `onSuccess` navega al detalle.
5. Al enviar `ClientForm` (modo create): llama `useCreateClient().mutate(payload)`, `onSuccess` navega al detalle.
6. Botón "Cancelar búsqueda" en estados `found-*` y `not-found` → resetea al estado `idle`.

**Composables usados internamente:** `useLookupClient`, `useLinkClient`, `useCreateClient`.

**Emits:** `success: [client: ClientItem]` (cuando el create o el link terminan bien).
La página padre puede escuchar este evento para navegar al detalle.

---

#### `front/src/modules/clients/components/modals/EstablishmentFormModal.vue`

**Propósito:** Modal con formulario de crear/editar establecimiento.

**Props:**
```typescript
{
  clientGuid: string
  mode:       'create' | 'edit'
  initial?:   Partial<EstablishmentItem>
}
```

**Emits:** `update:open` (para `v-model:open`), `success`

**Lógica:**
- `useForm` con `toTypedSchema(establishmentSchema)`
- En modo `create`: llama `useCreateEstablishment().mutate({ clientGuid, payload })`
- En modo `edit`: llama `useUpdateEstablishment().mutate({ clientGuid, estGuid, payload })`
- Usa `BaseModal` con título "Nuevo establecimiento" / "Editar establecimiento"

---

#### `front/src/modules/clients/components/modals/ContactFormModal.vue`

**Propósito:** Modal con formulario de crear/editar contacto.

**Props:**
```typescript
{
  clientGuid:   string
  mode:         'create' | 'edit'
  initial?:     Partial<ContactItem>
  contactGuid?: string   // requerido en modo edit
}
```

**Lógica:**
- `useForm` con `toTypedSchema(contactSchema)`
- Selector de `type`: opciones hardcodeadas: `[{ value: 'phone', label: 'Teléfono' }, { value: 'email', label: 'Email' }, { value: 'whatsapp', label: 'WhatsApp' }]`
- `is_primary` y `use_for_alerts`: `a-checkbox`

---

#### `front/src/modules/clients/components/modals/OwnerFormModal.vue`

**Propósito:** Modal para crear un owner (email + nombre + apellido).

**Props:**
```typescript
{
  clientGuid: string
}
```

**Lógica:**
- `useForm` con `toTypedSchema(ownerCreateSchema)`
- Llama `useCreateOwner().mutate({ clientGuid, payload })`
- Nota informativa visible en el modal: "Se enviará una invitación por email al usuario."

---

#### `front/src/modules/clients/components/EstablishmentsSection.vue`

**Propósito:** Sección dentro de `ClientDetailPage` que lista los establecimientos y permite
crear, editar y eliminar.

**Props:** `clientGuid: string`

**Lógica:**
- Usa `useClientEstablishments(clientGuid)` para la lista
- Usa `useDeleteEstablishment()` para eliminar
- Abre `EstablishmentFormModal` en modo `create` o `edit`
- Muestra tabla con columnas: nombre, RENSPA, ciudad/provincia, acciones
- Botón "Nuevo establecimiento" con `PermissionGuard permission="establishments.create"`

---

#### `front/src/modules/clients/components/ContactsSection.vue`

**Propósito:** Sección dentro de `ClientDetailPage` que lista los contactos.

**Props:** `clientGuid: string`

**Lógica:**
- Usa `useClientContacts(clientGuid)`
- Abre `ContactFormModal`
- Lista con tipo, valor, label, badges para `is_primary` y `use_for_alerts`
- Botón "Nuevo contacto" con `PermissionGuard permission="clients.update"`

**Nota de permiso:** No existe un permiso específico de contactos en el backend. Se usa
`clients.update` porque crear/editar contactos es parte de gestionar el client.
Si en el futuro se crean permisos granulares, se actualiza acá.

---

#### `front/src/modules/clients/components/OwnersSection.vue`

**Propósito:** Sección dentro de `ClientDetailPage` que lista los owners del client.

**Props:** `clientGuid: string`

**Lógica:**
- Usa `useClientOwners(clientGuid)`
- Abre `OwnerFormModal`
- Lista con nombre completo, email, rol, fecha
- Botón "Agregar owner" con `PermissionGuard permission="clients.owners.create"`

---

#### `front/src/modules/clients/pages/ClientsListPage.vue`

**Propósito:** Página principal del módulo. Lista paginada de clients de la vet actual.

**Lógica:**
- Lee `vetSlug` de `useRoute().params.vetSlug`
- Usa `useClientsUiStore()` para filtros persistidos
- Usa `useClients(computed(() => ({ ...uiStore.filters, search: uiStore.debouncedSearch })))` para los datos
- Usa `useUnlinkClient()` para desvincular desde la tabla
- Botón "Agregar cliente" con `PermissionGuard permission="clients.create"`
  → navega a `/vets/:vetSlug/clients/new`
- `ClientsTable` con handler `@unlink="handleUnlink"`
- `BasePagination` para la navegación de páginas
- `AppHeader` con título "Clientes" y subtítulo "Clientes vinculados a esta veterinaria."

---

#### `front/src/modules/clients/pages/ClientCreatePage.vue`

**Propósito:** Página de creación/vinculación de un client. Contiene el flujo completo de lookup.

**Lógica:**
- Renderiza `ClientLookupForm`
- Escucha `@success="handleSuccess"` → navega a `/vets/:vetSlug/clients/:client.guid`
- `AppHeader` con título "Agregar cliente" y botón Volver

---

#### `front/src/modules/clients/pages/ClientDetailPage.vue`

**Propósito:** Página de detalle de un client. Muestra datos básicos y las tres secciones.

**Props recibidas desde el router:** `guid: string` (via `props: true`)

**Lógica:**
- Usa `useClient(computed(() => props.guid))`
- Si `isLoading`: muestra skeleton
- Si no hay datos: "Cliente no encontrado."
- Estructura de la página:
  ```
  [AppHeader — nombre del client, botones Editar + Desvincular]
  [Cards con datos básicos: nombre, tax_id, país, dirección]
  [<a-tabs> o secciones expandibles:]
    Tab 1: Establecimientos → <EstablishmentsSection :client-guid="guid" />
    Tab 2: Contactos       → <ContactsSection :client-guid="guid" />
    Tab 3: Owners          → <OwnersSection :client-guid="guid" />
  ```
- Botón "Editar" con `PermissionGuard permission="clients.update"`
  → navega a `/vets/:vetSlug/clients/:guid/edit`
- Botón "Desvincular" con `PermissionGuard permission="clients.delete"` usando `useUnlinkClient()`
  → `onSuccess` navega a `/vets/:vetSlug/clients`

---

#### `front/src/modules/clients/pages/ClientEditPage.vue`

**Propósito:** Página de edición de datos básicos de un client.

**Props:** `guid: string`

**Lógica:**
- Usa `useClient(computed(() => props.guid))` para cargar valores iniciales
- Usa `useUpdateClient()` para la mutation
- Renderiza `ClientForm mode="edit" :initial-values="client.data"`
- `onSuccess`: navega a `/vets/:vetSlug/clients/:guid`
- Patrón idéntico a `VetEditPage.vue`

---

#### `front/src/modules/clients/router/clients.routes.ts`

**Propósito:** Definición de rutas del módulo clients.

**Contenido:**
```typescript
import type { RouteRecordRaw } from 'vue-router'

export const clientsRoutes: RouteRecordRaw[] = [
  {
    path:      '/vets/:vetSlug/clients',
    name:      'clients-list',
    component: () => import('@/modules/clients/pages/ClientsListPage.vue'),
    meta:      { requiresAuth: true, title: 'Clientes' },
  },
  {
    path:      '/vets/:vetSlug/clients/new',
    name:      'clients-create',
    component: () => import('@/modules/clients/pages/ClientCreatePage.vue'),
    meta:      { requiresAuth: true, title: 'Agregar cliente' },
  },
  {
    path:      '/vets/:vetSlug/clients/:guid',
    name:      'clients-detail',
    component: () => import('@/modules/clients/pages/ClientDetailPage.vue'),
    props:     true,
    meta:      { requiresAuth: true, title: 'Detalle del cliente' },
  },
  {
    path:      '/vets/:vetSlug/clients/:guid/edit',
    name:      'clients-edit',
    component: () => import('@/modules/clients/pages/ClientEditPage.vue'),
    props:     true,
    meta:      { requiresAuth: true, title: 'Editar cliente' },
  },
]
```

**Nota de orden:** La ruta `/new` DEBE estar antes que `/:guid` en el array para que Vue Router
no interprete "new" como un guid. Verificar con el router de Vue (usa first-match).

---

### Archivos a modificar

---

#### `front/src/router/index.ts`

**Cambio:** Importar `clientsRoutes` y registrarlas en el grupo `AppLayout`.

**Antes:**
```typescript
import { vetsRoutes } from '@/modules/vets/router/vets.routes'
// ...
children: [
  ...vetsRoutes,
  // ...
]
```

**Después:**
```typescript
import { vetsRoutes }    from '@/modules/vets/router/vets.routes'
import { clientsRoutes } from '@/modules/clients/router/clients.routes'
// ...
children: [
  ...vetsRoutes,
  ...clientsRoutes,
  // ...
]
```

---

#### `front/src/core/constants/permissions.ts`

**Cambio:** Agregar las constantes de permisos del módulo clients y establishments.

**Antes (últimas líneas):**
```typescript
  SUPPORT_MESSAGES_CLOSE: 'support-messages.close',
} as const
```

**Después:**
```typescript
  SUPPORT_MESSAGES_CLOSE:     'support-messages.close',
  // Vets
  VETS_READ:     'vets.read',
  VETS_CREATE:   'vets.create',
  VETS_UPDATE:   'vets.update',
  VETS_DELETE:   'vets.delete',
  VETS_VALIDATE: 'vets.validate',
  // Clients
  CLIENTS_READ:          'clients.read',
  CLIENTS_CREATE:        'clients.create',
  CLIENTS_UPDATE:        'clients.update',
  CLIENTS_DELETE:        'clients.delete',
  CLIENTS_OWNERS_READ:   'clients.owners.read',
  CLIENTS_OWNERS_CREATE: 'clients.owners.create',
  // Establishments
  ESTABLISHMENTS_READ:   'establishments.read',
  ESTABLISHMENTS_CREATE: 'establishments.create',
  ESTABLISHMENTS_UPDATE: 'establishments.update',
  ESTABLISHMENTS_DELETE: 'establishments.delete',
} as const
```

**Nota:** Las constantes de vets tampoco estaban en el archivo (verificado). Se agregan todas juntas.

---

## Estructura de archivos completa del módulo

```
front/src/modules/clients/
├── api/
│   └── clients.api.ts
├── components/
│   ├── ClientsTable.vue
│   ├── EstablishmentsSection.vue
│   ├── ContactsSection.vue
│   ├── OwnersSection.vue
│   ├── forms/
│   │   ├── ClientForm.vue
│   │   └── ClientLookupForm.vue
│   └── modals/
│       ├── EstablishmentFormModal.vue
│       ├── ContactFormModal.vue
│       └── OwnerFormModal.vue
├── composables/
│   ├── useClients.ts
│   ├── useClient.ts
│   ├── useLookupClient.ts
│   ├── useCreateClient.ts
│   ├── useLinkClient.ts
│   ├── useUpdateClient.ts
│   ├── useUnlinkClient.ts
│   ├── useClientEstablishments.ts
│   ├── useCreateEstablishment.ts
│   ├── useUpdateEstablishment.ts
│   ├── useDeleteEstablishment.ts
│   ├── useClientContacts.ts
│   ├── useCreateContact.ts
│   ├── useUpdateContact.ts
│   ├── useDeleteContact.ts
│   ├── useClientOwners.ts
│   └── useCreateOwner.ts
├── pages/
│   ├── ClientsListPage.vue
│   ├── ClientCreatePage.vue
│   ├── ClientDetailPage.vue
│   └── ClientEditPage.vue
├── router/
│   └── clients.routes.ts
├── stores/
│   └── clients-ui.store.ts
├── types/
│   └── client.types.ts
└── validators/
    └── client.validator.ts

front/src/stores/
└── vet.store.ts                    ← store global (nuevo)
```

Total archivos a crear: **32** (31 del módulo + 1 store global)
Total archivos a modificar: **2** (`router/index.ts`, `core/constants/permissions.ts`)

---

## Flujo de lookup/create/link — detalle del componente `ClientLookupForm`

El componente es el corazón del módulo. Su template se estructura así:

```
[Sección de búsqueda — siempre visible]
  <a-input v-model="taxIdInput" placeholder="Ingresá el CUIT / identificador fiscal" />
  <a-button @click="handleSearch" :loading="isSearching">Buscar</a-button>

[Sección de resultado — condicional según LookupState.status]

  IDLE / done: nada

  SEARCHING: <a-spin />

  FOUND-LINKABLE:
    <a-alert type="info" message="Cliente encontrado en el sistema" />
    [Tarjeta con: nombre, tax_id, país, tipo de doc]
    <a-button type="primary" @click="handleLink">Vincular a esta veterinaria</a-button>
    <a-button @click="resetSearch">Cancelar</a-button>

  FOUND-LINKED:
    <a-alert type="warning" message="Este cliente ya está vinculado a esta veterinaria" />
    [Tarjeta con datos del client]
    <a-button @click="router.push(detailRoute)">Ver detalle</a-button>
    <a-button @click="resetSearch">Buscar otro</a-button>

  NOT-FOUND:
    <a-alert type="info" message="No se encontró ningún cliente con ese identificador. Completá los datos para crearlo." />
    <ClientForm mode="create" :initial-values="{ tax_id: taxIdInput }" ... />
    — ClientForm pre-popula tax_id con el valor buscado, el usuario solo completa nombre, país, etc.

  CREATING: <a-spin /> — mientras se procesa el submit del ClientForm
```

**Manejo de errores de lookup:**
- Error de red / 500: `<a-alert type="error" message="Error al buscar. Intentá nuevamente." />`
- 403: El guard de ruta debería haber prevenido esto, pero si ocurre, mensaje de "Sin permisos."

---

## Orden de implementación

1. Crear `front/src/stores/vet.store.ts`.

2. Crear `front/src/modules/clients/types/client.types.ts`.

3. Crear `front/src/modules/clients/api/clients.api.ts`.
   Verificar con una llamada manual (browser devtools o Postman) que `http` unwrappea `data`
   correctamente — en particular para `lookupClientApi` donde la respuesta anidada es
   `{ success, data: { found, ... } }`.

4. Crear `front/src/modules/clients/validators/client.validator.ts`.

5. Crear `front/src/modules/clients/stores/clients-ui.store.ts`.

6. Crear los composables de queries (no mutations) en este orden:
   - `useClients.ts`
   - `useClient.ts`
   - `useClientEstablishments.ts`
   - `useClientContacts.ts`
   - `useClientOwners.ts`
   - `useLookupClient.ts`

7. Crear los composables de mutations:
   - `useCreateClient.ts`
   - `useLinkClient.ts`
   - `useUpdateClient.ts`
   - `useUnlinkClient.ts`
   - `useCreateEstablishment.ts`
   - `useUpdateEstablishment.ts`
   - `useDeleteEstablishment.ts`
   - `useCreateContact.ts`
   - `useUpdateContact.ts`
   - `useDeleteContact.ts`
   - `useCreateOwner.ts`

8. Agregar permisos de clients y vets en `front/src/core/constants/permissions.ts`.

9. Crear componentes base en este orden (de menor a mayor dependencia):
   - `ClientsTable.vue`
   - `forms/ClientForm.vue` (depende de `useCountries` y `useDocumentTypes` del módulo vets)
   - `modals/EstablishmentFormModal.vue`
   - `modals/ContactFormModal.vue`
   - `modals/OwnerFormModal.vue`
   - `EstablishmentsSection.vue` (depende de EstablishmentFormModal)
   - `ContactsSection.vue` (depende de ContactFormModal)
   - `OwnersSection.vue` (depende de OwnerFormModal)
   - `forms/ClientLookupForm.vue` (depende de ClientForm, useLookupClient, useLinkClient, useCreateClient)

10. Crear páginas en este orden:
    - `ClientsListPage.vue`
    - `ClientCreatePage.vue` (depende de ClientLookupForm)
    - `ClientEditPage.vue` (depende de ClientForm)
    - `ClientDetailPage.vue` (depende de EstablishmentsSection, ContactsSection, OwnersSection)

11. Crear `front/src/modules/clients/router/clients.routes.ts`.

12. Registrar `clientsRoutes` en `front/src/router/index.ts`.

13. Verificar compilación TypeScript: `npm run type-check` desde `front/`.

14. Navegación manual happy path:
    a. `/vets/mi-vet/clients` → lista carga con datos
    b. `/vets/mi-vet/clients/new` → buscar CUIT inexistente → crear client
    c. `/vets/mi-vet/clients/new` → buscar CUIT existente → vincular
    d. `/vets/mi-vet/clients/:guid` → detalle carga con tabs
    e. Crear establecimiento desde la pestaña → aparece en la lista
    f. Crear contacto → aparece en la lista
    g. Crear owner → modal cierra, aparece en la lista
    h. `/vets/mi-vet/clients/:guid/edit` → editar, guardar, volver al detalle

---

## Riesgos y consideraciones

### R01 — `useRoute()` dentro de composables requiere que el composable sea llamado en setup()

`useClients`, `useClient` y demás composables llaman a `useRoute()` internamente. Esto es
válido solo si el composable se llama dentro de `setup()` de un componente Vue (o en otro
composable llamado desde `setup()`). Si algún dev instancia el composable fuera del contexto
de Vue (ej: en un store o en un módulo JS puro), `useRoute()` lanzará un error.
**Mitigación:** Documentar en cada composable que requiere contexto de componente, o alternativamente
recibir `vetSlug` como parámetro si se detecta que se necesitan fuera de componentes.

---

### R02 — `lookupClientApi` y el unwrap de la respuesta HTTP

El proyecto usa un `http` layer basado en Axios (verificado en `front/src/core/api/http.ts`).
La respuesta del backend tiene la forma `{ success: true, data: { found, client, already_linked } }`.
Axios retorna `{ data: { success, data: { found, ... } } }`. Es necesario verificar si el
interceptor de `http.ts` hace unwrap automático del campo `data` de la respuesta. Si lo hace,
`lookupClientApi` retorna directamente `{ found, ... }`. Si NO lo hace, debe retornar
`res.data.data`. Verificar leyendo `front/src/core/api/http.ts` antes de implementar el API layer.

---

### R03 — Ruta `/clients/new` vs `/:guid` — orden en Vue Router

Vue Router 4 resuelve rutas por orden de declaración. Si `/:guid` aparece antes de `/new`,
la URL `/vets/mi-vet/clients/new` matcheará con `/:guid` y `props.guid` será `'new'`, causando
un 404 de API. **El array `clientsRoutes` DEBE tener `/new` antes de `/:guid`** (así está
definido en el plan). El dev debe verificar con `router.resolve('/vets/x/clients/new')` que
el nombre de ruta es `'clients-create'` y no `'clients-detail'`.

---

### R04 — `ClientLookupForm`: estado `searching` vs isLoading de Vue Query

`useLookupClient` usa `enabled: false` + trigger manual. Cuando se llama a `search()`,
se cambia `enabled` a `true` y Vue Query empieza a fetchear. El estado `isLoading` de la
query será `true` mientras carga. El componente debe mapear `isLoading` al estado `searching`
de la máquina de estados local. Si hay un race condition (el usuario presiona "Buscar" dos
veces antes de que llegue la respuesta), Vue Query cancela la request anterior automáticamente.

---

### R05 — `ClientForm` en modo `create` dentro de `ClientLookupForm`: pre-poblar `tax_id`

Cuando `found: false`, el `ClientForm` debe recibir `{ tax_id: taxIdBuscado }` como
`initialValues`. Sin embargo, en modo `create` el schema Zod valida `tax_id` como requerido.
El `initialValues` popula el campo via `setValues()` en el `watch` de `VetForm`
(patrón ya implementado). Verificar que el `ClientForm` en modo `create` también hace el
`watch(() => props.initialValues, setValues)` y no solo en modo `edit`.

---

### R06 — Tipo de contacto: string libre vs enum controlado

El tipo `ContactItem.type` es `string` en los tipos TypeScript y el backend lo acepta como
string libre. El `ContactFormModal` hardcodea las opciones `phone | email | whatsapp`.
Si el backend tiene más tipos (ej: `fax`, `telegram`), el selector no los mostrará al editar
un contacto existente con ese tipo. Mitigación a futuro: obtener los tipos de contacto válidos
desde un endpoint de referencia, o al menos mostrar el tipo actual como opción adicional si no
está en la lista hardcodeada.

---

### R07 — Multi-tenant: cache aislado por vetSlug pero el mismo usuario puede tener acceso a múltiples vets

Un usuario autenticado puede ser staff de más de una vet. Si navega entre `/vets/vet-a/clients`
y `/vets/vet-b/clients` en la misma sesión, Vue Query mantiene caches separados por vetSlug
(DEC-07). Esto es correcto. El riesgo es que si el dev olvida incluir `vetSlug` en alguna
queryKey (ej: en una query de un subcomponente), podría haber contaminación de cache entre vets.
Todos los composables del módulo DEBEN incluir `vetSlug` en la queryKey.

---

### R08 — Dependencia de composables de `vets/` para países y tipos de documento

`ClientForm.vue` usa `useCountries()` y `useDocumentTypes()` importados desde
`@/modules/vets/composables/`. Si en el futuro esos composables se mueven a `core/` (como
recomienda el plan anterior en Pendientes), las importaciones de `ClientForm` deben actualizarse.
No es un bug actual, pero es deuda técnica documentada.

---

### R09 — `useDeleteEstablishment` y `useDeleteContact` invalidan también la query `['client', ...]`

El detalle del client (`GET /clients/:guid`) retorna los establecimientos inline en su response
según el tipo `ClientDetail`. Si el dev elimina un establecimiento y solo invalida
`['client-establishments', ...]` pero no `['client', ...]`, la página de detalle seguirá
mostrando el establecimiento eliminado hasta el próximo refetch. Ambas invalidaciones son
necesarias en los composables de delete de establecimientos y contactos.

---

### R10 — El permiso de owners aún no está asignado a roles tenant

Según `clients-module-plan.md` (Pendientes, ítem 2): los roles `vet`, `vet-assistant`,
`client-owner` no tienen `clients.owners.read` ni `clients.owners.create` asignados en el
seeder backend. El `PermissionGuard permission="clients.owners.create"` en `OwnersSection`
ocultará el botón para todos los usuarios tenant hasta que se asignen esos permisos en backend.
Documentar para el equipo backend que esta asignación está pendiente.

---

## Supuestos hechos

- El módulo `clients` NO existe en el frontend (confirmado: ningún archivo en
  `front/src/modules/clients/`).
- El store `front/src/stores/vet.store.ts` NO existe (confirmado: el glob no lo encontró).
- Los composables `useCountries` y `useDocumentTypes` están en `front/src/modules/vets/composables/`
  y son importables desde el módulo clients (confirmado en código real).
- El tipo `PaginatedResponse<T>` existe en `front/src/core/types/pagination.types.ts` (confirmado).
- El componente `BaseModal` existe en el proyecto como componente global (no verificado
  directamente — en el plan anterior se mencionó su uso en staff/clients. Si no existe,
  usar `a-modal` de Ant Design directamente).
- Los componentes `FormSection`, `FormFooter`, `AppHeader`, `BasePagination`, `BaseDataTable`,
  `BaseTableActions`, `PermissionGuard`, `ColumnSelectorDrawer`, `EmptyState` son componentes
  globales registrados. Si alguno no existe, adaptar usando Ant Design directamente.
- El HTTP interceptor NO hace unwrap automático de `data.data` (supuesto conservador — verificar
  en el paso 3 del orden de implementación).

---

## Pendientes / fuera de alcance

1. **Guard de ruta por permiso:** Las rutas no tienen `beforeEnter` verificando `clients.read`.
   Solo se ocultan botones con `PermissionGuard`. Un guard de ruta es una mejora futura.

2. **Eliminación de owner (DELETE /owners/:ownerGuid):** No está en el scope del brief. El
   endpoint no existe en el backend aún (marcado como pendiente en `clients-module-plan.md`).

3. **Re-invitación de owner sin cuenta activa:** Si el owner fue invitado pero nunca activó su
   cuenta, no hay flujo de reenvío de invitación. Requiere decisión de negocio y endpoint nuevo.

4. **Edición de contacto en `ContactsSection`:** Se planifica `mode: 'edit'` en `ContactFormModal`
   pero el detalle del client que retorna el backend incluye los contactos inline. Si el backend
   devuelve los contactos actualizados en el response de `GET /clients/:guid`, invalidar esa
   query es suficiente. Verificar que el endpoint `GET /clients/:guid` incluye `contacts` en el
   response (el type `ClientDetail` lo modela así — confirmar con el backend).

5. **Navegación activa en sidebar:** La ruta activa del módulo clients no se refleja
   automáticamente en el sidebar/menu del panel tenant (ese componente no fue analizado en este
   plan). Requiere que el sidebar tenga items configurados para `/vets/:vetSlug/clients`.

6. **Layout del panel tenant:** El plan anterior documentó que las rutas bajo `/vets/:vetSlug/...`
   comparten el mismo `AppLayout` que el panel superadmin. Si en el futuro se crea un layout
   tenant específico, el registro de rutas cambia. Por ahora, se asume `AppLayout` para todas.

7. **Búsqueda avanzada en lista de clients:** El endpoint acepta `search` pero el módulo no
   tiene un componente `ClientFilters.vue` dedicado. Se incluye un input de búsqueda básico
   dentro de la `ClientsListPage` directamente (sin componente separado, dado que hay menos
   filtros que en `VetsListPage`). Si se necesitan filtros por país, estado, etc., crear el
   componente en una iteración posterior.

8. **Pantalla de onboarding para owners invitados:** El email apunta a `/invitacion?token=...`
   que no existe en el frontend. Plan separado en el módulo de auth.
