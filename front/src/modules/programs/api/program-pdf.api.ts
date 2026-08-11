import { http } from '@/core/api/http'
import type { ExportItem } from '@/modules/exports/types/export.types'
import type { ProgramShareRecipient, ProgramShareResult } from '../types/program-pdf.types'

export async function requestProgramPdfApi(vetGuid: string, guid: string): Promise<ExportItem> {
  const res = await http.post<ExportItem>(`/v1/vets/${vetGuid}/programs/${guid}/pdf`)
  return res.data
}

export async function getShareRecipientsApi(
  vetGuid: string,
  guid: string,
): Promise<ProgramShareRecipient[]> {
  const res = await http.get<ProgramShareRecipient[]>(
    `/v1/vets/${vetGuid}/programs/${guid}/share-recipients`,
  )
  return res.data
}

export async function shareProgramPdfApi(
  vetGuid: string,
  guid: string,
  payload: { manager_profile_ids: string[] },
): Promise<ProgramShareResult> {
  const res = await http.post<ProgramShareResult>(
    `/v1/vets/${vetGuid}/programs/${guid}/share`,
    payload,
  )
  return res.data
}
