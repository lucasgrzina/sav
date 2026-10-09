import { http } from '@/core/api/http'
import type { PaginatedResponse } from '@/core/types/pagination.types'
import type { HealthPlanCategory } from '@/modules/health/types/health.types'

/**
 * Catálogo global de solo lectura (DEC-NEG-03): el vet no crea/edita `HealthPlanCategory`,
 * solo la consume para armar sus propias plantillas de plan sanitario.
 */
export async function listAllHealthPlanCategoriesCatalogApi(vetGuid: string): Promise<HealthPlanCategory[]> {
  const res = await http.get<PaginatedResponse<HealthPlanCategory>>(`/v1/vets/${vetGuid}/health-plan-categories`, {
    params: { per_page: 100 },
  })
  return res.data.data
}
