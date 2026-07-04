import { http } from '@/core/api/http'
import type {
  TechniqueListItem,
  Technique,
  TechniqueDetail,
  TechniqueListParams,
  TechniqueType,
  CreateTechniquePayload,
  UpdateTechniquePayload,
} from '../types/technique.types'
import type { PaginatedResponse } from '@/core/types/pagination.types'

// --- Admin endpoints ---

export async function adminListTechniquesApi(
  params: TechniqueListParams,
  signal?: AbortSignal,
): Promise<PaginatedResponse<TechniqueListItem>> {
  const res = await http.get<PaginatedResponse<TechniqueListItem>>('/v1/admin/techniques', {
    params,
    signal,
  })
  return res.data
}

export async function adminGetTechniqueApi(guid: string): Promise<TechniqueDetail> {
  const res = await http.get<TechniqueDetail>(`/v1/admin/techniques/${guid}`)
  return res.data
}

export async function adminCreateTechniqueApi(payload: CreateTechniquePayload): Promise<Technique> {
  const res = await http.post<Technique>('/v1/admin/techniques', payload)
  return res.data
}

export async function adminUpdateTechniqueApi(
  guid: string,
  payload: UpdateTechniquePayload,
): Promise<Technique> {
  const res = await http.put<Technique>(`/v1/admin/techniques/${guid}`, payload)
  return res.data
}

export async function adminDeleteTechniqueApi(guid: string): Promise<void> {
  await http.delete(`/v1/admin/techniques/${guid}`)
}

// --- API panel Vet (solo lectura) ---

export async function listTechniquesApi(
  type?: TechniqueType,
  signal?: AbortSignal,
): Promise<Technique[]> {
  const res = await http.get<Technique[]>('/v1/techniques', {
    params: type ? { type } : undefined,
    signal,
  })
  return res.data
}
