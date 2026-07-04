import { http } from '@/core/api/http'
import type {
  VetItem,
  VetListParams,
  VetListResponse,
  VetCreatePayload,
  VetUpdatePayload,
  CountryItem,
  DocumentTypeItem,
} from '../types/vet.types'

export async function listVetsApi(params: VetListParams = {}, signal?: AbortSignal): Promise<VetListResponse> {
  const res = await http.get<VetListResponse>('/v1/admin/vets', { params, signal })
  return res.data
}

export async function getVetApi(guid: string): Promise<VetItem> {
  const res = await http.get<VetItem>(`/v1/admin/vets/${guid}`)
  return res.data
}

export async function createVetApi(payload: VetCreatePayload): Promise<VetItem> {
  const res = await http.post<VetItem>('/v1/admin/vets', payload)
  return res.data
}

export async function updateVetApi(guid: string, payload: VetUpdatePayload): Promise<VetItem> {
  const res = await http.put<VetItem>(`/v1/admin/vets/${guid}`, payload)
  return res.data
}

export async function validateVetApi(guid: string): Promise<VetItem> {
  const res = await http.patch<VetItem>(`/v1/admin/vets/${guid}/validate`)
  return res.data
}

export async function suspendVetApi(guid: string): Promise<VetItem> {
  const res = await http.patch<VetItem>(`/v1/admin/vets/${guid}/suspend`)
  return res.data
}

export async function unsuspendVetApi(guid: string): Promise<VetItem> {
  const res = await http.patch<VetItem>(`/v1/admin/vets/${guid}/unsuspend`)
  return res.data
}

export async function listCountriesApi(): Promise<CountryItem[]> {
  const res = await http.get<CountryItem[]>('/v1/countries')
  return res.data
}

export async function listDocumentTypesApi(countryGuid: string): Promise<DocumentTypeItem[]> {
  const res = await http.get<DocumentTypeItem[]>(`/v1/countries/${countryGuid}/document-types`)
  return res.data
}

export async function fetchVetByGuid(guid: string): Promise<VetItem> {
  const res = await http.get<VetItem>(`/v1/vets/${guid}`)
  return res.data
}

export async function updateVetTenantApi(guid: string, payload: VetUpdatePayload): Promise<VetItem> {
  const res = await http.put<VetItem>(`/v1/vets/${guid}`, payload)
  return res.data
}
