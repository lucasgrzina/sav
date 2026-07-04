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

export interface EstablishmentItem {
  guid: string
  name: string
  renspa: string | null
  address: string | null
  city: string | null
  state: string | null
  zip_code: string | null
  latitude: number | null
  longitude: number | null
  created_at: string
}

export interface OwnerItem {
  guid: string
  user: {
    guid: string
    name: string
    first_name: string
    last_name: string
    email: string
  }
  role: {
    guid: string
    name: string
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
