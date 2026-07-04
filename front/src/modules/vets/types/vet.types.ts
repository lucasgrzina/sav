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

export interface ContactItem {
  guid: string
  type: 'email' | 'phone' | 'whatsapp'
  label: string | null
  value: string
  is_primary: boolean
  use_for_alerts: boolean
  created_at: string
}

export interface ContactFormItem {
  guid?: string
  type: 'email' | 'phone' | 'whatsapp'
  value: string
  label?: string | null
  is_primary: boolean
  use_for_alerts: boolean
}

export interface UpdateVetStaffPayload {
  role_guid: string
  contacts: ContactFormItem[]
}

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
  contacts?: ContactItem[]
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
  contacts?: ContactFormItem[]
}

export interface VetUpdatePayload {
  name?: string
  document_type_guid?: string
  tax_id?: string
  registration_number?: string | null
  logo_path?: string | null
  pdf_title?: string | null
  pdf_subtitle?: string | null
  contacts?: ContactFormItem[]
}

export interface VetFilters {
  search?: string
  validated?: boolean | ''
  suspended?: boolean | ''
  page?: number
  per_page?: number
}

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
  contacts: ContactItem[]
  blocked_at: string | null
  created_at: string
}

export interface AssignStaffPayload {
  user_guid: string
  role_guid: string
}

export interface ChangeStaffRolePayload {
  role_guid: string
}

// --- Flujo de incorporación de staff (panel tenant) ---

/** Shape del lookup result de GET /v1/vets/{vet}/staff/lookup */
export interface VetStaffLookupResult {
  found: boolean
  already_linked: boolean | null
  user: {
    guid: string
    first_name: string
    last_name: string
    email: string
  } | null
}

/** Payload para POST /v1/vets/{vet}/staff/new-user */
export interface VetStaffCreatePayload {
  first_name: string
  last_name: string
  email: string
  role_guid: string
  contacts?: ContactFormItem[]
}

/** Payload para POST /v1/vets/{vet}/staff (asignar usuario existente) */
export interface VetStaffAssignPayload {
  user_guid: string
  role_guid: string
  contacts?: ContactFormItem[]
}

/** Payload para PUT /v1/vets/{vet}/my-profile */
export interface UpdateMyVetProfilePayload {
  first_name: string
  last_name: string
  contacts: ContactFormItem[]
}
