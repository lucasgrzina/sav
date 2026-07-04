import { http } from '@/core/api/http'
import type { ExportItem, ExportListResponse, InitiateExportPayload } from '../types/export.types'

export async function initiateExportApi(payload: InitiateExportPayload): Promise<ExportItem> {
  const response = await http.post<ExportItem>('/v1/exports', payload)
  return response.data
}

export async function listExportsApi(params?: { per_page?: number; page?: number }): Promise<ExportListResponse> {
  const response = await http.get<ExportListResponse>('/v1/exports', { params })
  return response.data
}

export async function getExportApi(guid: string): Promise<ExportItem> {
  const response = await http.get<ExportItem>(`/v1/exports/${guid}`)
  return response.data
}

/**
 * Descarga el archivo. Como el endpoint retorna binario (no JSON),
 * se usa responseType: 'blob' y se omite el interceptor de desenvuelto.
 * Se maneja la creación del link de descarga aquí.
 */
export async function downloadExportApi(guid: string, fileName: string): Promise<void> {
  const response = await http.get(`/v1/exports/${guid}/download`, {
    responseType: 'blob',
  })
  const url  = window.URL.createObjectURL(new Blob([response.data]))
  const link = document.createElement('a')
  link.href  = url
  link.setAttribute('download', fileName)
  document.body.appendChild(link)
  link.click()
  link.remove()
  window.URL.revokeObjectURL(url)
}
