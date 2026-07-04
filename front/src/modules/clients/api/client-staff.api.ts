import { http } from '@/core/api/http'
import type {
  ClientStaffItem,
  ClientStaffAssignPayload,
  ClientStaffCreatePayload,
  ClientStaffLookupResult,
  UpdateClientStaffPayload,
  ChangeClientStaffRolePayload,
} from '../types/client.types'

// --- Panel Admin ---

export async function adminListClientStaffApi(clientGuid: string): Promise<ClientStaffItem[]> {
  const res = await http.get<ClientStaffItem[]>(`/v1/admin/clients/${clientGuid}/staff`)
  return res.data
}

export async function adminGetClientStaffMemberApi(clientGuid: string, profileGuid: string): Promise<ClientStaffItem> {
  const res = await http.get<ClientStaffItem>(`/v1/admin/clients/${clientGuid}/staff/${profileGuid}`)
  return res.data
}

export async function adminAssignClientStaffApi(clientGuid: string, payload: ClientStaffAssignPayload): Promise<ClientStaffItem> {
  const res = await http.post<ClientStaffItem>(`/v1/admin/clients/${clientGuid}/staff`, payload)
  return res.data
}

export async function adminChangeClientStaffRoleApi(
  clientGuid: string,
  profileGuid: string,
  payload: ChangeClientStaffRolePayload,
): Promise<ClientStaffItem> {
  const res = await http.patch<ClientStaffItem>(
    `/v1/admin/clients/${clientGuid}/staff/${profileGuid}/role`,
    payload,
  )
  return res.data
}

export async function adminUpdateClientStaffApi(
  clientGuid: string,
  profileGuid: string,
  payload: UpdateClientStaffPayload,
): Promise<ClientStaffItem> {
  const res = await http.put<ClientStaffItem>(
    `/v1/admin/clients/${clientGuid}/staff/${profileGuid}`,
    payload,
  )
  return res.data
}

export async function adminRemoveClientStaffApi(clientGuid: string, profileGuid: string): Promise<void> {
  await http.delete(`/v1/admin/clients/${clientGuid}/staff/${profileGuid}`)
}

// --- Panel Tenant ---

export async function listClientStaffApi(vetGuid: string, clientGuid: string): Promise<ClientStaffItem[]> {
  const res = await http.get<ClientStaffItem[]>(`/v1/vets/${vetGuid}/clients/${clientGuid}/staff`)
  return res.data
}

export async function getClientStaffMemberApi(vetGuid: string, clientGuid: string, profileGuid: string): Promise<ClientStaffItem> {
  const res = await http.get<ClientStaffItem>(`/v1/vets/${vetGuid}/clients/${clientGuid}/staff/${profileGuid}`)
  return res.data
}

export async function lookupClientStaffApi(
  vetGuid: string,
  clientGuid: string,
  email: string,
): Promise<ClientStaffLookupResult> {
  const res = await http.get<ClientStaffLookupResult>(
    `/v1/vets/${vetGuid}/clients/${clientGuid}/staff/lookup`,
    { params: { email } },
  )
  return res.data
}

export async function createClientStaffApi(
  vetGuid: string,
  clientGuid: string,
  payload: ClientStaffCreatePayload,
): Promise<ClientStaffItem> {
  const res = await http.post<ClientStaffItem>(
    `/v1/vets/${vetGuid}/clients/${clientGuid}/staff/new-user`,
    payload,
  )
  return res.data
}

export async function assignClientStaffApi(
  vetGuid: string,
  clientGuid: string,
  payload: ClientStaffAssignPayload,
): Promise<ClientStaffItem> {
  const res = await http.post<ClientStaffItem>(
    `/v1/vets/${vetGuid}/clients/${clientGuid}/staff`,
    payload,
  )
  return res.data
}

export async function removeClientStaffApi(vetGuid: string, clientGuid: string, profileGuid: string): Promise<void> {
  await http.delete(`/v1/vets/${vetGuid}/clients/${clientGuid}/staff/${profileGuid}`)
}

export async function toggleBlockClientStaffApi(vetGuid: string, clientGuid: string, profileGuid: string): Promise<ClientStaffItem> {
  const res = await http.patch<ClientStaffItem>(
    `/v1/vets/${vetGuid}/clients/${clientGuid}/staff/${profileGuid}/toggle-block`,
  )
  return res.data
}

export async function changeClientStaffRoleApi(
  vetGuid: string,
  clientGuid: string,
  profileGuid: string,
  roleGuid: string,
): Promise<ClientStaffItem> {
  const res = await http.patch<ClientStaffItem>(
    `/v1/vets/${vetGuid}/clients/${clientGuid}/staff/${profileGuid}/role`,
    { role_guid: roleGuid },
  )
  return res.data
}

export async function updateClientStaffApi(
  vetGuid: string,
  clientGuid: string,
  profileGuid: string,
  payload: UpdateClientStaffPayload,
): Promise<ClientStaffItem> {
  const res = await http.put<ClientStaffItem>(
    `/v1/vets/${vetGuid}/clients/${clientGuid}/staff/${profileGuid}`,
    payload,
  )
  return res.data
}
