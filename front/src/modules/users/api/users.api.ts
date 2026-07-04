import { http } from '@/core/api/http'
import type {
  UserItem,
  UserListParams,
  UserListResponse,
  UserCreatePayload,
  UserUpdatePayload,
  ChangePasswordPayload,
  TenantUserCreatePayload,
} from '../types/user.types'

export async function listUsersApi(params: UserListParams, signal?: AbortSignal): Promise<UserListResponse> {
  const response = await http.get<UserListResponse>('/v1/users', { params, signal })
  return response.data
}

export async function createUserApi(payload: UserCreatePayload): Promise<UserItem> {
  const response = await http.post<UserItem>('/v1/users', payload)
  return response.data
}

export async function getUserApi(guid: string): Promise<UserItem> {
  const response = await http.get<UserItem>(`/v1/users/${guid}`)
  return response.data
}

export async function updateUserApi(guid: string, payload: UserUpdatePayload): Promise<UserItem> {
  const response = await http.put<UserItem>(`/v1/users/${guid}`, payload)
  return response.data
}

export async function deleteUserApi(guid: string): Promise<void> {
  await http.delete(`/v1/users/${guid}`)
}

export async function toggleLockUserApi(guid: string): Promise<UserItem> {
  const response = await http.patch<UserItem>(`/v1/users/${guid}/toggle-lock`)
  return response.data
}

export async function changePasswordUserApi(guid: string, payload: ChangePasswordPayload): Promise<void> {
  await http.patch(`/v1/users/${guid}/change-password`, payload)
}

export async function resetPasswordUserApi(guid: string): Promise<{ password: string }> {
  const response = await http.post<{ password: string }>(`/v1/users/${guid}/reset-password`)
  return response.data
}

export async function createTenantUserApi(payload: TenantUserCreatePayload): Promise<UserItem> {
  const response = await http.post<UserItem>('/v1/users/tenant', payload)
  return response.data
}

export async function addUserProfileApi(
  userGuid: string,
  payload: { role_guid: string; vet_guid?: string; client_guid?: string },
): Promise<UserItem> {
  const response = await http.post<UserItem>(`/v1/users/${userGuid}/profiles`, payload)
  return response.data
}

export async function updateUserProfileRoleApi(
  userGuid: string,
  profileGuid: string,
  roleGuid: string,
): Promise<UserItem> {
  const response = await http.patch<UserItem>(
    `/v1/users/${userGuid}/profiles/${profileGuid}/role`,
    { role_guid: roleGuid },
  )
  return response.data
}
