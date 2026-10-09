import { http } from '@/core/api/http'
import type { PaginatedResponse } from '@/core/types/pagination.types'
import type {
  HealthPlanTemplate,
  HealthPlanTemplateListItem,
  HealthPlanTemplateListParams,
  CreateHealthPlanTemplatePayload,
  UpdateHealthPlanTemplatePayload,
} from '@/modules/health/types/health.types'

export async function listHealthPlanTemplatesCatalogApi(
  vetGuid: string,
  params: HealthPlanTemplateListParams,
  signal?: AbortSignal,
): Promise<PaginatedResponse<HealthPlanTemplateListItem>> {
  const res = await http.get<PaginatedResponse<HealthPlanTemplateListItem>>(
    `/v1/vets/${vetGuid}/health-plan-templates`,
    { params, signal },
  )
  return res.data
}

export async function getHealthPlanTemplateCatalogApi(
  vetGuid: string,
  guid: string,
): Promise<HealthPlanTemplate> {
  const res = await http.get<HealthPlanTemplate>(`/v1/vets/${vetGuid}/health-plan-templates/${guid}`)
  return res.data
}

export async function createHealthPlanTemplateCatalogApi(
  vetGuid: string,
  payload: CreateHealthPlanTemplatePayload,
): Promise<HealthPlanTemplate> {
  const res = await http.post<HealthPlanTemplate>(`/v1/vets/${vetGuid}/health-plan-templates`, payload)
  return res.data
}

export async function updateHealthPlanTemplateCatalogApi(
  vetGuid: string,
  guid: string,
  payload: UpdateHealthPlanTemplatePayload,
): Promise<HealthPlanTemplate> {
  const res = await http.put<HealthPlanTemplate>(`/v1/vets/${vetGuid}/health-plan-templates/${guid}`, payload)
  return res.data
}

export async function deleteHealthPlanTemplateCatalogApi(vetGuid: string, guid: string): Promise<void> {
  await http.delete(`/v1/vets/${vetGuid}/health-plan-templates/${guid}`)
}
