// Status real del backend: verified | unverified | locked
export type UserStatus = 'verified' | 'unverified' | 'locked'

export type UserType = 'platform' | 'tenant'

export interface UserRole {
  guid: string
  name: string
}

export interface User {
  guid: string
  first_name: string
  last_name: string
  name: string
  email: string
  status: UserStatus
  email_verified_at: string | null
  locked_at: string | null
  last_login_at: string | null
  created_at: string
  roles?: UserRole[]
  profiles?: UserProfileItem[]
}

// UserItem es el mismo shape que devuelve la API de listado
export type UserItem = User

export interface UserListParams {
  search?: string
  status?: UserStatus | ''
  date_from?: string
  date_to?: string
  page?: number
  per_page?: number
  vet_guid?: string
  type?: UserType
  role_guid?: string
}

export interface UserListResponse {
  data: UserItem[]
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface UserCreatePayload {
  first_name: string
  last_name: string
  email: string
  password: string
  password_confirmation: string
  role_guids?: string[]
}

export interface UserUpdatePayload {
  first_name: string
  last_name: string
  email: string
  role_guids?: string[]
}

export interface ChangePasswordPayload {
  password: string
  password_confirmation: string
}

export interface UserFilters {
  search?: string
  status?: UserStatus | '' | null
  date_from?: string
  date_to?: string
  page?: number
  per_page?: number
  vet_guid?: string
  type?: UserType
  role_guid?: string
}

export interface TenantUserProfilePayload {
  role_guid: string
  vet_guid?: string
  client_guid?: string
}

export interface TenantUserCreatePayload {
  first_name: string
  last_name: string
  email: string
  password: string
  password_confirmation: string
  profiles: TenantUserProfilePayload[]
}

export interface UserProfileItem {
  guid: string
  role: { guid: string; name: string }
  tenant_type: 'vet' | 'client'
  tenant_guid: string
  tenant_name: string
}
