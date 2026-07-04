import { http } from '@/core/api/http'
import type { PaginatedResponse } from '@/core/types/pagination.types'
import type {
  HealthActivity,
  HealthActivityListParams,
  CreateHealthActivityPayload,
  UpdateHealthActivityPayload,
} from '../types/health.types'

export async function listHealthActivitiesApi(
  params: HealthActivityListParams,
  signal?: AbortSignal,
): Promise<PaginatedResponse<HealthActivity>> {
  const res = await http.get<PaginatedResponse<HealthActivity>>('/v1/admin/health-activities', { params, signal })
  return res.data
}

export async function listAllHealthActivitiesApi(): Promise<HealthActivity[]> {
  const res = await http.get<PaginatedResponse<HealthActivity>>('/v1/admin/health-activities', {
    params: { per_page: 100 },
  })
  return res.data.data
}

export async function createHealthActivityApi(payload: CreateHealthActivityPayload): Promise<HealthActivity> {
  const res = await http.post<HealthActivity>('/v1/admin/health-activities', payload)
  return res.data
}

export async function updateHealthActivityApi(
  guid: string,
  payload: UpdateHealthActivityPayload,
): Promise<HealthActivity> {
  const res = await http.put<HealthActivity>(`/v1/admin/health-activities/${guid}`, payload)
  return res.data
}

export async function deleteHealthActivityApi(guid: string): Promise<void> {
  await http.delete(`/v1/admin/health-activities/${guid}`)
}
