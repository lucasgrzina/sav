import { http } from '@/core/api/http'
import type { VetStaffItem, UpdateMyVetProfilePayload } from '../types/vet.types'

export async function getMyVetProfileApi(vetGuid: string): Promise<VetStaffItem> {
  const res = await http.get(`/v1/vets/${vetGuid}/my-profile`)
  return res.data
}

export async function updateMyVetProfileApi(
  vetGuid: string,
  payload: UpdateMyVetProfilePayload,
): Promise<VetStaffItem> {
  const res = await http.put(`/v1/vets/${vetGuid}/my-profile`, payload)
  return res.data
}
