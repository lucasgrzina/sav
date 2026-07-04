// ─── Health Activity ───────────────────────────────────────────────────────

export interface HealthActivity {
  guid: string
  name: string
  description: string | null
  created_at: string
  updated_at: string
}

export interface HealthActivityListParams {
  search?: string
  page?: number
  per_page?: number
}

export interface CreateHealthActivityPayload {
  name: string
  description: string | null
}

export type UpdateHealthActivityPayload = CreateHealthActivityPayload

// ─── Health Plan Category ──────────────────────────────────────────────────

export interface HealthPlanCategory {
  guid: string
  name: string
  description: string | null
  templates_count: number
  created_at: string
  updated_at: string
}

export interface HealthPlanCategoryListParams {
  search?: string
  page?: number
  per_page?: number
}

export interface CreateHealthPlanCategoryPayload {
  name: string
  description: string | null
}

export type UpdateHealthPlanCategoryPayload = CreateHealthPlanCategoryPayload

// ─── Health Plan Template ──────────────────────────────────────────────────

export interface ActivityAssignment {
  health_activity_guid: string
  months: number[]
}

export interface TemplateActivity {
  guid: string
  name: string
  months: number[]
}

export interface HealthPlanTemplateCategory {
  guid: string
  name: string
}

export interface HealthPlanTemplate {
  guid: string
  name: string
  category: HealthPlanTemplateCategory
  activities: TemplateActivity[]
  activities_count: number
  created_at: string
  updated_at: string
}

export interface HealthPlanTemplateListItem {
  guid: string
  name: string
  category: HealthPlanTemplateCategory
  activities_count: number
  created_at: string
  updated_at: string
}

export interface HealthPlanTemplateListParams {
  search?: string
  health_plan_category_guid?: string
  page?: number
  per_page?: number
}

export interface CreateHealthPlanTemplatePayload {
  name: string
  health_plan_category_guid: string
  activities: ActivityAssignment[]
}

export type UpdateHealthPlanTemplatePayload = CreateHealthPlanTemplatePayload
