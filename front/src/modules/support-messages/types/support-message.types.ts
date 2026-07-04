export type SupportMessageStatus = 'open' | 'in_progress' | 'closed'
export type SupportMessagePriority = 'low' | 'medium' | 'high'
export type SupportMessageCategory = 'bug' | 'question' | 'feature_request' | 'other'

export interface SupportMessageReply {
  guid: string
  body: string
  created_at: string
  author: {
    guid: string
    name: string
  }
}

export interface SupportMessage {
  guid: string
  subject: string
  body: string
  status: SupportMessageStatus
  priority: SupportMessagePriority
  category: SupportMessageCategory
  closed_at: string | null
  created_at: string
  sender?: {
    guid: string
    name: string
  }
  closer?: {
    guid: string
    name: string
  } | null
  replies?: SupportMessageReply[]
}

export interface SupportMessageListParams {
  page?: number
  per_page?: number
}

export interface SupportMessageListResponse {
  data: SupportMessage[]
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface CreateSupportMessagePayload {
  subject: string
  body: string
  priority: SupportMessagePriority
  category: SupportMessageCategory
}

export interface AddReplyPayload {
  body: string
}
