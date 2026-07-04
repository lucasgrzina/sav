import { http } from '@/core/api/http'
import type {
  ClientItem,
  ClientDetail,
  ClientListParams,
  ClientListResponse,
  ClientCreatePayload,
  ClientUpdatePayload,
} from '../types/client.types'
import type { VetItem } from '@/modules/vets/types/vet.types'

// --- Admin Clients ---

export async function adminListClientsApi(
  params: ClientListParams,
  signal?: AbortSignal,
): Promise<ClientListResponse> {
  const res = await http.get<ClientListResponse>('/v1/admin/clients', { params, signal })
  return res.data
}

export async function adminGetClientApi(
  guid: string,
): Promise<ClientDetail & { vets: VetItem[] }> {
  const res = await http.get<ClientDetail & { vets: VetItem[] }>(`/v1/admin/clients/${guid}`)
  return res.data
}

export async function adminCreateClientApi(payload: ClientCreatePayload): Promise<ClientItem> {
  const res = await http.post<ClientItem>('/v1/admin/clients', payload)
  return res.data
}

export async function adminUpdateClientApi(
  guid: string,
  payload: ClientUpdatePayload,
): Promise<ClientItem> {
  const res = await http.put<ClientItem>(`/v1/admin/clients/${guid}`, payload)
  return res.data
}

export async function adminLinkVetToClientApi(clientGuid: string, vetGuid: string): Promise<void> {
  await http.post(`/v1/admin/clients/${clientGuid}/vets`, { vet_guid: vetGuid })
}

export async function adminUnlinkVetFromClientApi(
  clientGuid: string,
  vetGuid: string,
): Promise<void> {
  await http.delete(`/v1/admin/clients/${clientGuid}/vets/${vetGuid}`)
}

// Tipo de respuesta del lookup
export interface AdminLookupClientResponse {
  found: boolean
  already_linked: boolean
  client: ClientItem | null
}

export async function adminLookupClientApi(
  taxId: string,
  vetGuid: string,
): Promise<AdminLookupClientResponse> {
  const res = await http.get<AdminLookupClientResponse>('/v1/admin/clients/lookup', {
    params: { tax_id: taxId, vet_guid: vetGuid },
  })
  return res.data
}

// --- Clients de una Vet (desde detalle de vet) ---

export async function adminListClientsByVetApi(
  vetGuid: string,
  params: ClientListParams,
  signal?: AbortSignal,
): Promise<ClientListResponse> {
  const res = await http.get<ClientListResponse>(`/v1/admin/vets/${vetGuid}/clients`, {
    params,
    signal,
  })
  return res.data
}
