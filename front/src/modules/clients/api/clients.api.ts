import { http } from '@/core/api/http'
import type {
  ClientItem,
  ClientDetail,
  ClientListParams,
  ClientListResponse,
  ClientCreatePayload,
  ClientUpdatePayload,
  EstablishmentItem,
  EstablishmentCreatePayload,
  EstablishmentUpdatePayload,
  ContactItem,
  ContactCreatePayload,
  ContactUpdatePayload,
  OwnerItem,
  OwnerCreatePayload,
  LookupResult,
} from '../types/client.types'

// --- Clients ---

export async function listClientsApi(
  vetGuid: string,
  params: ClientListParams,
  signal?: AbortSignal,
): Promise<ClientListResponse> {
  const res = await http.get<ClientListResponse>(`/v1/vets/${vetGuid}/clients`, { params, signal })
  return res.data
}

export async function getClientApi(
  vetGuid: string,
  guid: string,
): Promise<ClientDetail> {
  const res = await http.get<ClientDetail>(`/v1/vets/${vetGuid}/clients/${guid}`)
  return res.data
}

export async function createClientApi(
  vetGuid: string,
  payload: ClientCreatePayload,
): Promise<ClientItem> {
  const res = await http.post<ClientItem>(`/v1/vets/${vetGuid}/clients`, payload)
  return res.data
}

export async function updateClientApi(
  vetGuid: string,
  guid: string,
  payload: ClientUpdatePayload,
): Promise<ClientItem> {
  const res = await http.put<ClientItem>(`/v1/vets/${vetGuid}/clients/${guid}`, payload)
  return res.data
}

export async function unlinkClientApi(
  vetGuid: string,
  guid: string,
): Promise<void> {
  await http.delete(`/v1/vets/${vetGuid}/clients/${guid}`)
}

export async function lookupClientApi(
  vetGuid: string,
  taxId: string,
): Promise<LookupResult> {
  const res = await http.get<LookupResult>(`/v1/vets/${vetGuid}/clients/lookup`, {
    params: { tax_id: taxId },
  })
  return res.data
}

export async function linkClientApi(
  vetGuid: string,
  guid: string,
): Promise<ClientItem> {
  const res = await http.post<ClientItem>(`/v1/vets/${vetGuid}/clients/${guid}/link`)
  return res.data
}

// --- Establecimientos ---

export async function listEstablishmentsApi(
  vetGuid: string,
  clientGuid: string,
): Promise<EstablishmentItem[]> {
  const res = await http.get<EstablishmentItem[]>(
    `/v1/vets/${vetGuid}/clients/${clientGuid}/establishments`,
  )
  return res.data
}

export async function createEstablishmentApi(
  vetGuid: string,
  clientGuid: string,
  payload: EstablishmentCreatePayload,
): Promise<EstablishmentItem> {
  const res = await http.post<EstablishmentItem>(
    `/v1/vets/${vetGuid}/clients/${clientGuid}/establishments`,
    payload,
  )
  return res.data
}

export async function updateEstablishmentApi(
  vetGuid: string,
  clientGuid: string,
  estGuid: string,
  payload: EstablishmentUpdatePayload,
): Promise<EstablishmentItem> {
  const res = await http.put<EstablishmentItem>(
    `/v1/vets/${vetGuid}/clients/${clientGuid}/establishments/${estGuid}`,
    payload,
  )
  return res.data
}

export async function deleteEstablishmentApi(
  vetGuid: string,
  clientGuid: string,
  estGuid: string,
): Promise<void> {
  await http.delete(`/v1/vets/${vetGuid}/clients/${clientGuid}/establishments/${estGuid}`)
}

// --- Establecimientos (panel admin) ---

export async function adminListEstablishmentsApi(
  clientGuid: string,
): Promise<EstablishmentItem[]> {
  const res = await http.get<EstablishmentItem[]>(
    `/v1/admin/clients/${clientGuid}/establishments`,
  )
  return res.data
}

export async function adminCreateEstablishmentApi(
  clientGuid: string,
  payload: EstablishmentCreatePayload,
): Promise<EstablishmentItem> {
  const res = await http.post<EstablishmentItem>(
    `/v1/admin/clients/${clientGuid}/establishments`,
    payload,
  )
  return res.data
}

export async function adminUpdateEstablishmentApi(
  clientGuid: string,
  estGuid: string,
  payload: EstablishmentUpdatePayload,
): Promise<EstablishmentItem> {
  const res = await http.put<EstablishmentItem>(
    `/v1/admin/clients/${clientGuid}/establishments/${estGuid}`,
    payload,
  )
  return res.data
}

export async function adminDeleteEstablishmentApi(
  clientGuid: string,
  estGuid: string,
): Promise<void> {
  await http.delete(`/v1/admin/clients/${clientGuid}/establishments/${estGuid}`)
}

// --- Contactos ---

export async function listContactsApi(
  vetGuid: string,
  clientGuid: string,
): Promise<ContactItem[]> {
  const res = await http.get<ContactItem[]>(
    `/v1/vets/${vetGuid}/clients/${clientGuid}/contacts`,
  )
  return res.data
}

export async function createContactApi(
  vetGuid: string,
  clientGuid: string,
  payload: ContactCreatePayload,
): Promise<ContactItem> {
  const res = await http.post<ContactItem>(
    `/v1/vets/${vetGuid}/clients/${clientGuid}/contacts`,
    payload,
  )
  return res.data
}

export async function updateContactApi(
  vetGuid: string,
  clientGuid: string,
  contactGuid: string,
  payload: ContactUpdatePayload,
): Promise<ContactItem> {
  const res = await http.put<ContactItem>(
    `/v1/vets/${vetGuid}/clients/${clientGuid}/contacts/${contactGuid}`,
    payload,
  )
  return res.data
}

export async function deleteContactApi(
  vetGuid: string,
  clientGuid: string,
  contactGuid: string,
): Promise<void> {
  await http.delete(`/v1/vets/${vetGuid}/clients/${clientGuid}/contacts/${contactGuid}`)
}

// --- Owners ---

export async function listOwnersApi(
  vetGuid: string,
  clientGuid: string,
): Promise<OwnerItem[]> {
  const res = await http.get<OwnerItem[]>(
    `/v1/vets/${vetGuid}/clients/${clientGuid}/owners`,
  )
  return res.data
}

export async function createOwnerApi(
  vetGuid: string,
  clientGuid: string,
  payload: OwnerCreatePayload,
): Promise<OwnerItem> {
  const res = await http.post<OwnerItem>(
    `/v1/vets/${vetGuid}/clients/${clientGuid}/owners`,
    payload,
  )
  return res.data
}
