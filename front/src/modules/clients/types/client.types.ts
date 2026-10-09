import type { CountryItem, DocumentTypeItem, VetItem, ContactFormItem } from '@/modules/vets/types/vet.types'
import type { PaginatedResponse } from '@/core/types/pagination.types'

// --- Constante de roles válidos para client staff ---
export const CLIENT_STAFF_ROLES = ['client-owner', 'client-manager', 'client-administrative'] as const
export type ClientStaffRoleName = typeof CLIENT_STAFF_ROLES[number]

// --- Tipo local para contactos en payloads de client staff ---
export interface ClientStaffContactFormItem {
  guid?: string
  type: 'email' | 'phone' | 'whatsapp'
  value: string
  label?: string | null
  is_primary: boolean
  use_for_alerts: boolean
}

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

export interface ClientStaffEstablishmentRef {
  guid: string
  name: string
}

export interface ClientStaffItem {
  guid: string
  user: ClientStaffUserItem
  role: ClientStaffRoleItem
  contacts: ContactItem[]
  // Presente en el listado de staff del cliente (whenLoaded en el backend)
  establishments?: ClientStaffEstablishmentRef[]
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
  contacts: ClientStaffContactFormItem[]
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

// --- Sub-tipos ---

export interface ContactItem {
  guid: string
  type: string
  value: string
  label: string | null
  is_primary: boolean
  use_for_alerts: boolean
}

// Staff embedded in an establishment: personal data without contacts nor the inverse establishments list
export type EstablishmentStaffItem = Omit<ClientStaffItem, 'contacts' | 'establishments'>

export interface ProvinceItem {
  guid: string
  name: string
}

export interface EstablishmentItem {
  guid: string
  name: string
  renspa: string | null
  address: string | null
  city: string | null
  state: string | null
  // Structured province (null for legacy rows that only have the `state` text)
  province?: ProvinceItem | null
  zip_code: string | null
  latitude: number | null
  longitude: number | null
  created_at: string
  // Personal de cliente vinculado. `staff` (datos personales) solo viene si el actor tiene
  // `clients.staff.read`; sin ese permiso el backend devuelve solo `staff_count`.
  staff?: EstablishmentStaffItem[]
  staff_count?: number
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
  vets?: VetItem[]  // solo presente en contexto admin (whenLoaded)
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
  document_type_guid?: string
  tax_id?: string
  address?: string | null
  city?: string | null
  state?: string | null
  zip_code?: string | null
  contacts?: ContactFormItem[]
}

export interface EstablishmentCreatePayload {
  name: string
  renspa?: string | null
  address?: string | null
  city?: string | null
  state?: string | null
  province_guid?: string | null
  zip_code?: string | null
  latitude?: number | null
  longitude?: number | null
}

export type EstablishmentUpdatePayload = Partial<EstablishmentCreatePayload>

// Address parts sent to the geocoding endpoint (at least address or city is required)
export interface GeocodeAddressPayload {
  address?: string | null
  city?: string | null
  state?: string | null
  zip_code?: string | null
}

// Coordinates are null when the address could not be resolved
export interface GeocodeResult {
  latitude: number | null
  longitude: number | null
}

// Sincronización total: estado final deseado del personal vinculado ([] desvincula a todos)
export interface EstablishmentStaffSyncPayload {
  user_profile_guids: string[]
}

export interface ContactCreatePayload {
  type: string
  value: string
  label?: string | null
  is_primary?: boolean
  use_for_alerts?: boolean
}

export type ContactUpdatePayload = Partial<ContactCreatePayload>

// --- Resultado del lookup ---

export type LookupResult =
  | { found: false; client: null }
  | { found: true; already_linked: boolean; client: ClientItem }

// --- Filtros para la lista ---

export interface ClientFilters {
  search?: string
  page?: number
  per_page?: number
}
