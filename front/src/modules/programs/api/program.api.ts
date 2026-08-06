import { http } from '@/core/api/http'
import type {
  ProgramListItem,
  ProgramDetail,
  ProgramListParams,
  CreateProgramPayload,
  UpdateProgramPayload,
} from '../types/program.types'
import type { PaginatedResponse } from '@/core/types/pagination.types'

export async function listProgramsApi(
  vetGuid: string,
  params: ProgramListParams,
  signal?: AbortSignal,
): Promise<PaginatedResponse<ProgramListItem>> {
  const res = await http.get<PaginatedResponse<ProgramListItem>>(
    `/v1/vets/${vetGuid}/programs`,
    { params, signal },
  )
  return res.data
}

export async function getProgramApi(vetGuid: string, guid: string): Promise<ProgramDetail> {
  const res = await http.get<ProgramDetail>(`/v1/vets/${vetGuid}/programs/${guid}`)
  return res.data
}

export async function createProgramApi(
  vetGuid: string,
  payload: CreateProgramPayload,
): Promise<ProgramDetail> {
  const res = await http.post<ProgramDetail>(`/v1/vets/${vetGuid}/programs`, payload)
  return res.data
}

export async function updateProgramApi(
  vetGuid: string,
  guid: string,
  payload: UpdateProgramPayload,
): Promise<ProgramDetail> {
  const res = await http.put<ProgramDetail>(`/v1/vets/${vetGuid}/programs/${guid}`, payload)
  return res.data
}

export async function cancelProgramApi(vetGuid: string, guid: string): Promise<ProgramDetail> {
  const res = await http.post<ProgramDetail>(`/v1/vets/${vetGuid}/programs/${guid}/cancel`)
  return res.data
}
