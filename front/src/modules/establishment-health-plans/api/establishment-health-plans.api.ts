import { http } from '@/core/api/http'
import type { PaginatedResponse } from '@/core/types/pagination.types'
import type {
  EstablishmentHealthPlanListItem,
  EstablishmentHealthPlanDetail,
  EstablishmentHealthPlanListParams,
  EstablishmentHealthPlanActivity,
  CreateEstablishmentHealthPlanPayload,
} from '../types/establishment-health-plan.types'

export async function listEstablishmentHealthPlansApi(
  vetGuid: string,
  params: EstablishmentHealthPlanListParams,
  signal?: AbortSignal,
): Promise<PaginatedResponse<EstablishmentHealthPlanListItem>> {
  const res = await http.get<PaginatedResponse<EstablishmentHealthPlanListItem>>(
    `/v1/vets/${vetGuid}/establishment-health-plans`,
    { params, signal },
  )
  return res.data
}

export async function getEstablishmentHealthPlanApi(
  vetGuid: string,
  guid: string,
): Promise<EstablishmentHealthPlanDetail> {
  const res = await http.get<EstablishmentHealthPlanDetail>(
    `/v1/vets/${vetGuid}/establishment-health-plans/${guid}`,
  )
  return res.data
}

export async function createEstablishmentHealthPlanApi(
  vetGuid: string,
  payload: CreateEstablishmentHealthPlanPayload,
): Promise<EstablishmentHealthPlanDetail> {
  const res = await http.post<EstablishmentHealthPlanDetail>(
    `/v1/vets/${vetGuid}/establishment-health-plans`,
    payload,
  )
  return res.data
}

export async function cancelEstablishmentHealthPlanApi(
  vetGuid: string,
  guid: string,
): Promise<EstablishmentHealthPlanDetail> {
  const res = await http.post<EstablishmentHealthPlanDetail>(
    `/v1/vets/${vetGuid}/establishment-health-plans/${guid}/cancel`,
  )
  return res.data
}

export async function confirmEstablishmentHealthPlanActivityApi(
  vetGuid: string,
  planGuid: string,
  activityGuid: string,
): Promise<EstablishmentHealthPlanActivity> {
  const res = await http.post<EstablishmentHealthPlanActivity>(
    `/v1/vets/${vetGuid}/establishment-health-plans/${planGuid}/activities/${activityGuid}/confirm`,
  )
  return res.data
}
