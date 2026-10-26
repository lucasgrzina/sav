import type { AlertState, AlertType, FailureCode, LocalDateTime } from '@/modules/programs/types/program-alerts.types'

// Contract of GET /v1/vets/{vet}/home (VetHomeResource). Defined by the backend.

export interface HomeRef {
  guid: string
  name: string
}

export interface HomeProgramSummary {
  guid: string
  client: HomeRef
  establishment: HomeRef
  technique: HomeRef
  protocol: HomeRef
}

export interface HomeUpcomingProgram extends HomeProgramSummary {
  /** Y-m-d, calendar date in the vet timezone. */
  next_target_date: string
}

export interface HomeAlertItem {
  key: string
  type: AlertType
  state: AlertState
  recipient: { profile_guid: string; name: string | null; role: string | null }
  task_description: string | null
  scheduled_at_local: LocalDateTime
  sent_at: string | null
  sent_at_local: LocalDateTime | null
  destination_masked: string | null
  program: HomeProgramSummary
}

export interface HomeProblemAlertItem extends HomeAlertItem {
  failure_code: FailureCode
}

export interface VetHomeData {
  generated_at: string
  timezone: string
  upcoming_programs: HomeUpcomingProgram[]
  /** null when the user lacks programs.alerts.read. */
  recent_alerts: HomeAlertItem[] | null
  /** null when the user lacks programs.alerts.read. */
  problem_alerts: HomeProblemAlertItem[] | null
  /** True when the list was cut at its limit. Alert flags are null when the user lacks programs.alerts.read. */
  has_more: {
    upcoming_programs: boolean
    recent_alerts: boolean | null
    problem_alerts: boolean | null
  }
}
