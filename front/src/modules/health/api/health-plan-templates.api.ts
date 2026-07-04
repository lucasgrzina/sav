import { http } from '@/core/api/http'
import type { PaginatedResponse } from '@/core/types/pagination.types'
import type {
  HealthPlanTemplate,
  HealthPlanTemplateListItem,
  HealthPlanTemplateListParams,
  CreateHealthPlanTemplatePayload,
  UpdateHealthPlanTemplatePayload,
} from '../types/health.types'

export async function listHealthPlanTemplatesApi(
  params: HealthPlanTemplateListParams,
  signal?: AbortSignal,
): Promise<PaginatedResponse<HealthPlanTemplateListItem>> {
  const res = await http.get<PaginatedResponse<HealthPlanTemplateListItem>>(
    '/v1/admin/health-plan-templates', { params, signal }
  )
  return res.data
}

export async function getHealthPlanTemplateApi(guid: string): Promise<HealthPlanTemplate> {
  const res = await http.get<HealthPlanTemplate>(`/v1/admin/health-plan-templates/${guid}`)
  return res.data
}

export async function createHealthPlanTemplateApi(
  payload: CreateHealthPlanTemplatePayload,
): Promise<HealthPlanTemplate> {
  const res = await http.post<HealthPlanTemplate>('/v1/admin/health-plan-templates', payload)
  return res.data
}

export async function updateHealthPlanTemplateApi(
  guid: string,
  payload: UpdateHealthPlanTemplatePayload,
): Promise<HealthPlanTemplate> {
  const res = await http.put<HealthPlanTemplate>(`/v1/admin/health-plan-templates/${guid}`, payload)
  return res.data
}

export async function deleteHealthPlanTemplateApi(guid: string): Promise<void> {
  await http.delete(`/v1/admin/health-plan-templates/${guid}`)
}
