export interface ProgramShareRecipient {
  guid: string
  name: string
  role: string
  has_whatsapp: boolean
}

export interface ProgramShareResult {
  alert_guid: string
  recipients_count: number
}
