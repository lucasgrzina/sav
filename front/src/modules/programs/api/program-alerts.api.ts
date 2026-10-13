import { http } from '@/core/api/http'
import type { PaginatedResponse } from '@/core/types/pagination.types'
import type {
  ProgramAlertLine,
  ProgramAlertsMeta,
  ProgramAlertsParams,
  RebuildProgramAlertsResult,
  RetryProgramAlertResult,
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

export async function retryProgramAlertApi(
  vetGuid: string,
  guid: string,
  recipientGuid: string,
): Promise<RetryProgramAlertResult> {
  const res = await http.post<RetryProgramAlertResult>(
    `/v1/vets/${vetGuid}/programs/${guid}/alerts/${recipientGuid}/retry`,
  )
  return res.data
}

export async function rebuildProgramAlertsApi(
  vetGuid: string,
  guid: string,
): Promise<RebuildProgramAlertsResult> {
  const res = await http.post<RebuildProgramAlertsResult>(
    `/v1/vets/${vetGuid}/programs/${guid}/alerts/rebuild`,
  )
  return res.data
}
