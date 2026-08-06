import { http } from '@/core/api/http'
import type {
  VetProtocolListItem,
  VetProtocolDetail,
  VetProtocolListParams,
  CreateVetProtocolPayload,
  UpdateVetProtocolPayload,
} from '../types/vet-protocol.types'
import type { PaginatedResponse } from '@/core/types/pagination.types'

export async function listVetProtocolsApi(
  vetGuid: string,
  params: VetProtocolListParams,
  signal?: AbortSignal,
): Promise<PaginatedResponse<VetProtocolListItem>> {
  const res = await http.get<PaginatedResponse<VetProtocolListItem>>(
    `/v1/vets/${vetGuid}/protocols`,
    { params, signal },
  )
  return res.data
}

export async function getVetProtocolApi(vetGuid: string, guid: string): Promise<VetProtocolDetail> {
  const res = await http.get<VetProtocolDetail>(`/v1/vets/${vetGuid}/protocols/${guid}`)
  return res.data
}

export async function createVetProtocolApi(
  vetGuid: string,
  payload: CreateVetProtocolPayload,
): Promise<VetProtocolDetail> {
  const res = await http.post<VetProtocolDetail>(`/v1/vets/${vetGuid}/protocols`, payload)
  return res.data
}

export async function updateVetProtocolApi(
  vetGuid: string,
  guid: string,
  payload: UpdateVetProtocolPayload,
): Promise<VetProtocolDetail> {
  const res = await http.put<VetProtocolDetail>(`/v1/vets/${vetGuid}/protocols/${guid}`, payload)
  return res.data
}

export async function deleteVetProtocolApi(vetGuid: string, guid: string): Promise<void> {
  await http.delete(`/v1/vets/${vetGuid}/protocols/${guid}`)
}
