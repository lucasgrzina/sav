export type NotificationType = 'info' | 'warning' | 'error' | 'success'

export type NotificationSubtype =
  | 'export_started'
  | 'export_ready'
  | 'export_failed'
  | 'new_support_message'
  | 'support_message_replied'
  | 'support_message_closed'

export interface NotificationPayload {
  title: string
  description: string
  url?: string
  type?: NotificationType
  subtype?: NotificationSubtype
  [key: string]: unknown
}

export interface NotificationItem {
  guid: string
  payload: NotificationPayload
  read_at: string | null
  created_at: string
}

export interface NotificationListResponse {
  notifications: {
    data: NotificationItem[]
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
  unread_count: number
}

export interface NotificationLatestResponse {
  notifications: NotificationItem[]
  unread_count: number
}

export interface NotificationRealtimePayload {
  guid: string
  data: NotificationPayload
  user_id: number
  is_read: boolean
}

export interface NotificationListParams {
  per_page?: number
  page?: number
}
