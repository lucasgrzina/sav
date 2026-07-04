import { http } from '@/core/api/http'
import type { PaginatedResponse } from '@/core/types/pagination.types'
import type {
  HealthPlanCategory,
  HealthPlanCategoryListParams,
  CreateHealthPlanCategoryPayload,
  UpdateHealthPlanCategoryPayload,
} from '../types/health.types'

export async function listHealthPlanCategoriesApi(
  params: HealthPlanCategoryListParams,
  signal?: AbortSignal,
): Promise<PaginatedResponse<HealthPlanCategory>> {
  const res = await http.get<PaginatedResponse<HealthPlanCategory>>('/v1/admin/health-plan-categories', { params, signal })
  return res.data
}

export async function listAllHealthPlanCategoriesApi(): Promise<HealthPlanCategory[]> {
  const res = await http.get<PaginatedResponse<HealthPlanCategory>>('/v1/admin/health-plan-categories', {
    params: { per_page: 100 },
  })
  return res.data.data
}

export async function createHealthPlanCategoryApi(payload: CreateHealthPlanCategoryPayload): Promise<HealthPlanCategory> {
  const res = await http.post<HealthPlanCategory>('/v1/admin/health-plan-categories', payload)
  return res.data
}

export async function updateHealthPlanCategoryApi(
  guid: string,
  payload: UpdateHealthPlanCategoryPayload,
): Promise<HealthPlanCategory> {
  const res = await http.put<HealthPlanCategory>(`/v1/admin/health-plan-categories/${guid}`, payload)
  return res.data
}

export async function deleteHealthPlanCategoryApi(guid: string): Promise<void> {
  await http.delete(`/v1/admin/health-plan-categories/${guid}`)
}
