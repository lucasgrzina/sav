export type TimeOfDay = 'before' | 'after'
export type TenantRole =
  | 'vet'
  | 'vet-assistant'
  | 'vet-administrative'
  | 'client-owner'
  | 'client-manager'
  | 'client-administrative'

export interface ProtocolTaskAlert {
  guid: string
  offset_days: number
  time_of_day: TimeOfDay
  time: string
  roles: TenantRole[]
  message: string
  require_confirmation: boolean
  sort_order: number
}

export interface ProtocolTask {
  guid: string
  description: string
  days_offset: number
  time_of_day: TimeOfDay
  time: string
  important: boolean
  sort_order: number
  alerts: ProtocolTaskAlert[]
}

export interface ProtocolTechniqueRef {
  guid: string
  name: string
}

export interface ProtocolCountryRef {
  guid: string
  name: string
}

export interface ProtocolListItem {
  guid: string
  name: string
  color: string | null
  technique: ProtocolTechniqueRef
  country: ProtocolCountryRef | null
  is_global: boolean
  tasks_count: number
  created_at: string
}

export interface ProtocolDetail extends ProtocolListItem {
  created_by_type: 'superadmin' | 'vet'
  updated_at: string
  tasks: ProtocolTask[]
}

export interface ProtocolListParams {
  root_guid: string
  technique_id?: string
  country_id?: string | null
  search?: string
  page?: number
  per_page?: number
}

export interface ProtocolTaskAlertPayload {
  guid?: string
  offset_days: number
  time_of_day: TimeOfDay
  time: string
  roles: TenantRole[]
  message: string
  require_confirmation: boolean
}

export interface ProtocolTaskPayload {
  guid?: string
  description: string
  days_offset: number
  time_of_day: TimeOfDay
  time: string
  important: boolean
  alerts: ProtocolTaskAlertPayload[]
}

export interface CreateProtocolPayload {
  technique_id: string
  name: string
  color: string | null
  country_id: string | null
  tasks: ProtocolTaskPayload[]
}

export type UpdateProtocolPayload = CreateProtocolPayload

export interface ProtocolDeleteError {
  reason: 'has_programs'
  count: number
}

export interface ProtocolTechniqueLockedError {
  reason: 'technique_locked'
  count: number
}

export interface SimulatedAlert {
  guid: string
  task_description: string
  message: string
  roles: TenantRole[]
  require_confirmation: boolean
  computed_date: string
  computed_time: string
}

export interface SimulatedTask {
  guid: string
  description: string
  important: boolean
  computed_date: string
  computed_time: string
  alerts: Omit<SimulatedAlert, 'task_description'>[]
}

export interface ProtocolSimulation {
  protocol: ProtocolTechniqueRef
  base_date: string
  tasks: SimulatedTask[]
  alerts: SimulatedAlert[]
}
