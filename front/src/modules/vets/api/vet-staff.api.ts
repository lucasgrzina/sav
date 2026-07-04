import { http } from '@/core/api/http'
import type {
  VetStaffItem,
  AssignStaffPayload,
  ChangeStaffRolePayload,
  VetStaffLookupResult,
  VetStaffCreatePayload,
  VetStaffAssignPayload,
  ContactItem,
  UpdateVetStaffPayload,
} from '../types/vet.types'

export async function adminListVetStaffApi(vetGuid: string): Promise<VetStaffItem[]> {
  const res = await http.get<VetStaffItem[]>(`/v1/admin/vets/${vetGuid}/staff`)
  return res.data
}

export async function adminAssignStaffApi(vetGuid: string, payload: AssignStaffPayload): Promise<VetStaffItem> {
  const res = await http.post<VetStaffItem>(`/v1/admin/vets/${vetGuid}/staff`, payload)
  return res.data
}

export async function adminChangeStaffRoleApi(
  vetGuid: string,
  profileGuid: string,
  payload: ChangeStaffRolePayload,
): Promise<VetStaffItem> {
  const res = await http.patch<VetStaffItem>(
    `/v1/admin/vets/${vetGuid}/staff/${profileGuid}/role`,
    payload,
  )
  return res.data
}

export async function adminGetVetStaffMemberApi(vetGuid: string, profileGuid: string): Promise<VetStaffItem> {
  const res = await http.get<VetStaffItem>(`/v1/admin/vets/${vetGuid}/staff/${profileGuid}`)
  return res.data
}

export async function adminRemoveStaffApi(vetGuid: string, profileGuid: string): Promise<void> {
  await http.delete(`/v1/admin/vets/${vetGuid}/staff/${profileGuid}`)
}

export async function adminUpdateVetStaffApi(
  vetGuid: string,
  profileGuid: string,
  payload: UpdateVetStaffPayload,
): Promise<VetStaffItem> {
  const res = await http.put<VetStaffItem>(
    `/v1/admin/vets/${vetGuid}/staff/${profileGuid}`,
    payload,
  )
  return res.data
}

// --- Panel Tenant ---

export async function lookupVetStaffApi(
  vetGuid: string,
  email: string,
): Promise<VetStaffLookupResult> {
  const res = await http.get<VetStaffLookupResult>(
    `/v1/vets/${vetGuid}/staff/lookup`,
    { params: { email } },
  )
  return res.data
}

export async function createVetStaffApi(
  vetGuid: string,
  payload: VetStaffCreatePayload,
): Promise<VetStaffItem> {
  const res = await http.post<VetStaffItem>(
    `/v1/vets/${vetGuid}/staff/new-user`,
    payload,
  )
  return res.data
}

export async function assignVetStaffApi(
  vetGuid: string,
  payload: VetStaffAssignPayload,
): Promise<VetStaffItem> {
  const res = await http.post<VetStaffItem>(
    `/v1/vets/${vetGuid}/staff`,
    payload,
  )
  return res.data
}

export async function listVetStaffApi(vetGuid: string): Promise<VetStaffItem[]> {
  const res = await http.get<VetStaffItem[]>(`/v1/vets/${vetGuid}/staff`)
  return res.data
}

export async function getVetStaffMemberApi(vetGuid: string, profileGuid: string): Promise<VetStaffItem> {
  const res = await http.get<VetStaffItem>(`/v1/vets/${vetGuid}/staff/${profileGuid}`)
  return res.data
}

export async function removeVetStaffApi(vetGuid: string, profileGuid: string): Promise<void> {
  await http.delete(`/v1/vets/${vetGuid}/staff/${profileGuid}`)
}

export async function toggleBlockVetStaffApi(vetGuid: string, profileGuid: string): Promise<VetStaffItem> {
  const res = await http.patch<VetStaffItem>(`/v1/vets/${vetGuid}/staff/${profileGuid}/toggle-block`)
  return res.data
}

export async function changeVetStaffRoleApi(
  vetGuid: string,
  profileGuid: string,
  roleGuid: string,
): Promise<VetStaffItem> {
  const res = await http.patch<VetStaffItem>(
    `/v1/vets/${vetGuid}/staff/${profileGuid}/role`,
    { role_guid: roleGuid },
  )
  return res.data
}

export async function updateVetStaffApi(
  vetGuid: string,
  profileGuid: string,
  payload: UpdateVetStaffPayload,
): Promise<VetStaffItem> {
  const res = await http.put<VetStaffItem>(
    `/v1/vets/${vetGuid}/staff/${profileGuid}`,
    payload,
  )
  return res.data
}

// --- Contactos de un miembro del staff ---

export async function listStaffContactsApi(vetGuid: string, profileGuid: string): Promise<ContactItem[]> {
  const res = await http.get<ContactItem[]>(`/v1/vets/${vetGuid}/staff/${profileGuid}/contacts`)
  return res.data
}

export async function createStaffContactApi(
  vetGuid: string,
  profileGuid: string,
  payload: Omit<ContactItem, 'guid' | 'created_at'>,
): Promise<ContactItem> {
  const res = await http.post<ContactItem>(`/v1/vets/${vetGuid}/staff/${profileGuid}/contacts`, payload)
  return res.data
}

export async function updateStaffContactApi(
  vetGuid: string,
  profileGuid: string,
  contactGuid: string,
  payload: Partial<Omit<ContactItem, 'guid' | 'created_at'>>,
): Promise<ContactItem> {
  const res = await http.put<ContactItem>(
    `/v1/vets/${vetGuid}/staff/${profileGuid}/contacts/${contactGuid}`,
    payload,
  )
  return res.data
}

export async function deleteStaffContactApi(
  vetGuid: string,
  profileGuid: string,
  contactGuid: string,
): Promise<void> {
  await http.delete(`/v1/vets/${vetGuid}/staff/${profileGuid}/contacts/${contactGuid}`)
}
