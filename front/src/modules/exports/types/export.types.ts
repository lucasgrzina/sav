export type ExportStatus = 'pending' | 'processing' | 'completed' | 'failed'
export type ExportFormat = 'xlsx' | 'csv' | 'txt' | 'pdf'
export type ExportType   = 'users' | 'roles'

export interface ExportItem {
  guid:            string
  type:            ExportType
  type_label:      string
  format:          ExportFormat
  status:          ExportStatus
  file_name:       string | null
  is_downloadable: boolean
  error_message:   string | null
  expires_at:      string | null
  created_at:      string
}

export interface InitiateExportPayload {
  type:     ExportType
  format:   ExportFormat
  async?:   boolean
  filters?: Record<string, string | undefined>
  columns?: string[]
}

export interface ExportListResponse {
  data:         ExportItem[]
  current_page: number
  last_page:    number
  per_page:     number
  total:        number
}
