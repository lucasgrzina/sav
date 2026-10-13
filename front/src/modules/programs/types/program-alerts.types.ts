export interface LocalDateTime {
  iso: string
  date: string
  time: string
  weekday: string
  weekday_short: string
  label: string
}

export type AlertState =
  | 'scheduled'
  | 'in_process'
  | 'sent'
  | 'delivered'
  | 'read'
  | 'failed'
  | 'not_sent'
  | 'cancelled'

export type AlertBucket = 'scheduled' | 'sent' | 'problem' | 'cancelled'

export type FailureCode =
  | 'no_whatsapp_contact'
  | 'no_email_contact'
  | 'invalid_number'
  | 'opted_out'
  | 'provider_unreachable'
  | 'provider_rejected'
  | 'service_misconfigured'
  | 'no_longer_applicable'
  | 'duplicate'
  | 'rate_limited'
  | 'quiet_hours'
  | 'unknown'

export type AlertCancelReason =
  | 'rescheduled'
  | 'protocol_changed'
  | 'recipient_removed'
  | 'group_removed'
  | 'program_cancelled'
  | 'superseded'

export type AlertType =
  | 'program.task_due'
  | 'program.created'
  | 'program.cancelled'
  | 'program.pdf_shared'

export type AlertStatusFilter = 'all' | 'scheduled' | 'sent' | 'problem' | 'cancelled'

export type ContactStatus = 'ok' | 'email_only' | 'missing' | 'invalid_number' | 'opted_out'

export interface ProgramAlertAttempt {
  recipient_guid: string
  channel: 'whatsapp' | 'email' | 'push'
  state: string
  failure_code: FailureCode | null
  destination_masked: string | null
  sent_at: string | null
  delivered_at: string | null
  attempts: number
}

export interface ProgramAlertRetryAdvice {
  allowed: boolean
  recipient_guid: string | null
  blocked_reason: string | null
}

export interface ProgramAlertLine {
  key: string
  alert_guid: string
  type: AlertType
  scheduled_at_local: LocalDateTime
  scheduled_at_utc: string
  recipient: { profile_guid: string; name: string | null; role: string | null }
  channel: 'whatsapp' | 'email_fallback'
  state: AlertState
  bucket: AlertBucket
  failure_code: FailureCode | null
  cancel_reason: AlertCancelReason | null
  fallback: { used: boolean; email_state: string | null }
  destination_masked: string | null
  sent_at: string | null
  delivered_at: string | null
  task: {
    protocol_task_guid: string | null
    description: string | null
    task_date: string | null
    task_time: string | null
  } | null
  message: string | null
  rendered_body: string | null
  group: { target_guid: string | null; target_date: string | null; label: string | null } | null
  contact: { status: ContactStatus; masked: string | null } | null
  attempts: ProgramAlertAttempt[]
  retry: ProgramAlertRetryAdvice | null
}

export type WarningSeverity = 'critical' | 'warning' | 'info'

export type WarningCode =
  | 'ALERT_ROLE_WITHOUT_RECIPIENT'
  | 'RECIPIENT_WITHOUT_WHATSAPP'
  | 'RECIPIENT_INVALID_NUMBER'
  | 'RECIPIENT_OPTED_OUT'
  | 'ALERT_IN_PAST'
  | 'NO_MANAGERS_SELECTED'
  | 'RECIPIENT_EMAIL_ONLY'
  | 'DUPLICATE_TARGET_DATE'
  | 'HIGH_VOLUME_FOR_RECIPIENT'
  | 'TASK_WITHOUT_ALERTS'

export interface ProgramWarning {
  code: WarningCode
  severity: WarningSeverity
  context: Record<string, unknown>
}

export interface ProgramAlertsCounters {
  scheduled: number
  sent: number
  problem: number
  cancelled: number
  total: number
}

export interface ProgramAlertsMeta {
  counters: ProgramAlertsCounters
  warnings: ProgramWarning[]
  timezone: string
  out_of_sync: boolean
  multi_group: boolean
  program_cancelled_at: string | null
  last_reconciled_at: string | null
}

export interface ProgramAlertsFilters {
  status: AlertStatusFilter
  profile: string | null
  target: string | null
  type: AlertType | null
}

export interface ProgramAlertsParams {
  status?: AlertStatusFilter
  profile?: string
  target?: string
  type?: AlertType
  page?: number
  per_page?: number
}

export interface ReconciliationSummary {
  kept: number
  rescheduled: number
  created: number
  cancelled: number
  already_sent_not_modified: number
}

export type RetryBlockedReason =
  | 'program_cancelled'
  | 'alert_cancelled'
  | 'not_retryable'
  | 'opted_out'
  | 'already_delivered'
  | 'recently_retried'
  | 'contact_still_missing'

export interface RetryProgramAlertResult {
  recipient_guid: string
  state: string
  retry_count: number
  last_retry_at: string | null
}

export interface RebuildProgramAlertsResult {
  reconciliation: ReconciliationSummary
  warnings: ProgramWarning[]
}
