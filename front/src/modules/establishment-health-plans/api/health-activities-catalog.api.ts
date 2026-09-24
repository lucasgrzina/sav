import { http } from '@/core/api/http'
import type { PaginatedResponse } from '@/core/types/pagination.types'
import type { HealthActivity } from '@/modules/health/types/health.types'

/**
 * Catálogo global de solo lectura (DEC-NEG-03): el vet no crea/edita `HealthActivity`,
 * solo la consume para armar sus propias plantillas de plan sanitario.
 */
export async function listAllHealthActivitiesCatalogApi(vetGuid: string): Promise<HealthActivity[]> {
  const res = await http.get<PaginatedResponse<HealthActivity>>(`/v1/vets/${vetGuid}/health-activities`, {
    params: { per_page: 100 },
  })
  return res.data.data
}
