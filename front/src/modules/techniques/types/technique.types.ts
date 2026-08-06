export type TechniqueType = 'technique' | 'vaccine'

export interface TechniqueChild {
  guid?: string
  name: string
  protocols_name: string | null
  // Opcional: el shape de escritura (formulario de alta/edición de técnica) no lo incluye,
  // solo lo expone TechniqueChildResource en lectura (DEC-13, program-module-plan.md).
  target_date_name?: string | null
}

export interface Technique {
  guid: string
  name: string
  type: TechniqueType
  target_date_name: string | null
  protocols_name: string | null
  parent_id: null
  is_root: true
  children: TechniqueChild[]
  children_count: number
  created_at: string
  updated_at: string
}

export interface TechniqueListItem {
  guid: string
  name: string
  type: TechniqueType
  target_date_name: string | null
  protocols_name: string | null
  children_count: number
  created_at: string
  updated_at: string
}

export interface ProgramsStub {
  data: []
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface TechniqueDetail {
  technique: Technique
  programs: ProgramsStub
}

export interface TechniqueFormData {
  name: string
  type: TechniqueType
  target_date_name: string
  protocols_name: string
  children: TechniqueChild[]
}

export interface CreateTechniquePayload {
  name: string
  type: TechniqueType
  target_date_name: string | null
  protocols_name: string | null
  children: Array<{ name: string; protocols_name: string | null }>
}

export interface UpdateTechniquePayload {
  name: string
  type: TechniqueType
  target_date_name: string | null
  protocols_name: string | null
  children: Array<{ guid?: string; name: string; protocols_name: string | null }>
}

export interface TechniqueListParams {
  search?: string
  type?: TechniqueType
  page?: number
  per_page?: number
}

export interface TechniqueDeleteError {
  reason: 'has_programs' | 'has_protocols' | 'children_have_programs'
  count: number
}

export interface TechniqueChildConflict {
  guid: string
  name: string
  programs_count: number
}
