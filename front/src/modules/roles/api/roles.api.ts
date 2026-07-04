import { http } from '@/core/api/http'
import type {
  RoleItem,
  RoleListParams,
  RoleListResponse,
  CreateRolePayload,
  UpdateRolePayload,
} from '../types/role.types'

export async function listRolesApi(params: RoleListParams = {}, signal?: AbortSignal): Promise<RoleListResponse> {
  const res = await http.get<RoleListResponse>('/v1/roles', { params, signal })
  return res.data
}

export async function getRoleApi(guid: string): Promise<RoleItem> {
  const res = await http.get<RoleItem>(`/v1/roles/${guid}`)
  return res.data
}

export async function createRoleApi(payload: CreateRolePayload): Promise<RoleItem> {
  const res = await http.post<RoleItem>('/v1/roles', payload)
  return res.data
}

export async function updateRoleApi(guid: string, payload: UpdateRolePayload): Promise<RoleItem> {
  const res = await http.put<RoleItem>(`/v1/roles/${guid}`, payload)
  return res.data
}

export async function deleteRoleApi(guid: string): Promise<void> {
  await http.delete(`/v1/roles/${guid}`)
}

export async function syncUserRolesApi(userGuid: string, roleGuids: string[]): Promise<void> {
  await http.put(`/v1/users/${userGuid}/roles`, { roles: roleGuids })
}
