import { http } from '@/core/api/http'
import type {
  NotificationListParams,
  NotificationListResponse,
  NotificationLatestResponse,
} from '../types/notification.types'

export async function listNotificationsApi(
  params: NotificationListParams = {},
  signal?: AbortSignal,
): Promise<NotificationListResponse> {
  const response = await http.get<NotificationListResponse>('/v1/notifications', { params, signal })
  return response.data
}

export async function getLatestNotificationsApi(): Promise<NotificationLatestResponse> {
  const response = await http.get<NotificationLatestResponse>('/v1/notifications/latest')
  return response.data
}

export async function markAsReadApi(guid: string): Promise<void> {
  await http.patch(`/v1/notifications/${guid}/read`)
}

export async function markAllAsReadApi(): Promise<void> {
  await http.patch('/v1/notifications/read-all')
}
