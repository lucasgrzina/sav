import { http } from '@/core/api/http'
import type { PaginatedResponse } from '@/core/types/pagination.types'
import type {
  ProgramAlertLine,
  ProgramAlertsMeta,
  ProgramAlertsParams,
} from '../types/program-alerts.types'

export type ProgramAlertsResponse = PaginatedResponse<ProgramAlertLine> & { meta: ProgramAlertsMeta }

export async function listProgramAlertsApi(
  vetGuid: string,
  guid: string,
  params: ProgramAlertsParams,
  signal?: AbortSignal,
): Promise<ProgramAlertsResponse> {
  const res = await http.get<ProgramAlertsResponse>(
    `/v1/vets/${vetGuid}/programs/${guid}/alerts`,
    { params, signal },
  )
  return res.data
}
