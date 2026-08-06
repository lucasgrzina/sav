import { http } from '@/core/api/http'
import type {
  ProtocolListItem,
  ProtocolDetail,
  ProtocolListParams,
  CreateProtocolPayload,
  UpdateProtocolPayload,
  ProtocolSimulation,
} from '../types/protocol.types'
import type { PaginatedResponse } from '@/core/types/pagination.types'

export async function adminListProtocolsApi(
  params: ProtocolListParams,
  signal?: AbortSignal,
): Promise<PaginatedResponse<ProtocolListItem>> {
  const res = await http.get<PaginatedResponse<ProtocolListItem>>('/v1/admin/protocols', {
    params,
    signal,
  })
  return res.data
}

export async function adminGetProtocolApi(guid: string): Promise<ProtocolDetail> {
  const res = await http.get<ProtocolDetail>(`/v1/admin/protocols/${guid}`)
  return res.data
}

export async function adminCreateProtocolApi(payload: CreateProtocolPayload): Promise<ProtocolDetail> {
  const res = await http.post<ProtocolDetail>('/v1/admin/protocols', payload)
  return res.data
}

export async function adminUpdateProtocolApi(
  guid: string,
  payload: UpdateProtocolPayload,
): Promise<ProtocolDetail> {
  const res = await http.put<ProtocolDetail>(`/v1/admin/protocols/${guid}`, payload)
  return res.data
}

export async function adminDeleteProtocolApi(guid: string): Promise<void> {
  await http.delete(`/v1/admin/protocols/${guid}`)
}

export async function adminReplicateProtocolApi(guid: string): Promise<ProtocolDetail> {
  const res = await http.post<ProtocolDetail>(`/v1/admin/protocols/${guid}/replicate`)
  return res.data
}

export async function adminSimulateProtocolApi(guid: string, baseDate: string): Promise<ProtocolSimulation> {
  const res = await http.get<ProtocolSimulation>(`/v1/admin/protocols/${guid}/simulate`, {
    params: { base_date: baseDate },
  })
  return res.data
}
