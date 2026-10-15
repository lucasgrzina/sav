export type DashboardChannel = 'whatsapp' | 'sms' | 'email' | 'push'

export interface SentPerDay {
  date: string
  count: number
}

export interface ChannelDelivery {
  channel: DashboardChannel
  ok: number
  failed: number
  rate: number | null
}

export interface AdminDashboardData {
  generated_at: string
  vets: {
    active: number
    pending: number
    suspended: number
    total: number
  }
  totals: {
    clients: number
    establishments: number
    animals: number
    active_programs: number
  }
  sent_last_7d: SentPerDay[]
  delivery_30d: {
    rate: number | null
    by_channel: ChannelDelivery[]
  }
}
