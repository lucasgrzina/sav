import { http } from '@/core/api/http'
import type {
  SupportMessage,
  SupportMessageReply,
  SupportMessageListParams,
  SupportMessageListResponse,
  CreateSupportMessagePayload,
  AddReplyPayload,
} from '../types/support-message.types'

export async function listSupportMessagesApi(
  params: SupportMessageListParams,
  signal?: AbortSignal,
): Promise<SupportMessageListResponse> {
  const response = await http.get<SupportMessageListResponse>('/v1/support-messages', { params, signal })
  return response.data
}

export async function getSupportMessageApi(guid: string): Promise<SupportMessage> {
  const response = await http.get<SupportMessage>(`/v1/support-messages/${guid}`)
  return response.data
}

export async function createSupportMessageApi(payload: CreateSupportMessagePayload): Promise<SupportMessage> {
  const response = await http.post<SupportMessage>('/v1/support-messages', payload)
  return response.data
}

export async function deleteSupportMessageApi(guid: string): Promise<void> {
  await http.delete(`/v1/support-messages/${guid}`)
}

export async function addReplyApi(guid: string, payload: AddReplyPayload): Promise<SupportMessageReply> {
  const response = await http.post<SupportMessageReply>(`/v1/support-messages/${guid}/replies`, payload)
  return response.data
}

export async function closeThreadApi(guid: string): Promise<SupportMessage> {
  const response = await http.patch<SupportMessage>(`/v1/support-messages/${guid}/close`)
  return response.data
}
