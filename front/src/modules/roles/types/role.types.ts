export type RoleType = 'platform' | 'tenant'

export interface PermissionItem {
  guid: string
  name: string
}

export interface PermissionGroup {
  module: string
  permissions: PermissionItem[]
}

export interface RoleItem {
  guid: string
  name: string
  type: RoleType
  permissions: PermissionItem[]
  users_count?: number
  created_at: string
}

export interface CreateRolePayload {
  name: string
  permissions: string[]
}

export interface UpdateRolePayload {
  name?: string
  permissions: string[]
}

// Aliases según convención del plan
export type RoleCreatePayload = CreateRolePayload
export type RoleUpdatePayload = UpdateRolePayload

export interface RoleListParams {
  search?: string
  page?: number
  per_page?: number
  type?: RoleType
}

export interface RoleListResponse {
  data: RoleItem[]
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface RoleFilters {
  search?: string
  page?: number
  per_page?: number
  type?: RoleType
}
