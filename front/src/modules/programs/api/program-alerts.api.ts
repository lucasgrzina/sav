import { http } from '@/core/api/http'
import type { PaginatedResponse } from '@/core/types/pagination.types'
import type {
  ProgramAlertLine,
  ProgramAlertsMeta,
  ProgramAlertsParams,
  ProgramPreview,
  PreviewProgramPayload,
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

// Read-only: computes what would be scheduled without persisting, queueing or notifying.
export async function previewProgramApi(
  vetGuid: string,
  payload: PreviewProgramPayload,
  guid?: string | null,
): Promise<ProgramPreview> {
  const url = guid
    ? `/v1/vets/${vetGuid}/programs/${guid}/preview`
    : `/v1/vets/${vetGuid}/programs/preview`
  const res = await http.post<ProgramPreview>(url, payload)
  return res.data
}
