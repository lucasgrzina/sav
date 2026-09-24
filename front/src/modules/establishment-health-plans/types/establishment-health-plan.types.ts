export interface EstablishmentHealthPlanRef {
  guid: string
  name: string
}

export interface EstablishmentHealthPlanActivity {
  guid: string
  health_activity: { guid: string; name: string }
  month: number
  due_date: string
  status: 'pending' | 'confirmed'
  require_confirmation: boolean
  confirmed_at: string | null
  confirmed_by: { guid: string; name: string } | null
  sort_order: number
}

export interface EstablishmentHealthPlanListItem {
  guid: string
  client: EstablishmentHealthPlanRef
  establishment: EstablishmentHealthPlanRef
  template: EstablishmentHealthPlanRef
  year: number
  starts_on: string
  ends_on: string
  cancelled_at: string | null
  editable: boolean
  activities_count: number
  pending_count: number
  created_at: string
}

export interface EstablishmentHealthPlanDetail extends EstablishmentHealthPlanListItem {
  activities: EstablishmentHealthPlanActivity[]
}

export interface EstablishmentHealthPlanListParams {
  client_id?: string
  establishment_id?: string
  cancelled?: boolean
  search?: string
  page?: number
  per_page?: number
}

export interface CreateEstablishmentHealthPlanPayload {
  establishment_id: string
  health_plan_template_id: string
  year: number
}

export interface EstablishmentHealthPlanNotEditableError {
  reason: 'not_editable'
}

export interface EstablishmentHealthPlanDuplicateError {
  reason: 'duplicate_plan'
}

export interface EstablishmentHealthPlanRoleNotAllowedError {
  reason: 'role_not_allowed'
}

// Subconjunto mínimo usado por EstablishmentHealthPlanCancelModal — tanto
// EstablishmentHealthPlanListItem como EstablishmentHealthPlanDetail lo satisfacen.
export interface EstablishmentHealthPlanCancelTarget {
  guid: string
  client: EstablishmentHealthPlanRef
  establishment: EstablishmentHealthPlanRef
}
