export interface ProgramTargetAnimalRef {
  guid: string
  rp: string
  name: string | null
}

// DEC-15/DEC-16: proyección de tareas/alertas del protocolo, presente solo en el detalle
// (ProgramDetail), nunca en el listado. Es una vista de solo lectura/simulación.
export interface ProgramAlertRecipient {
  guid: string
  name: string
  role: string
}

export interface ProgramProjectedAlert {
  protocol_task_alert_guid: string
  occurs_on: string
  occurs_at: string
  roles: string[]
  message: string
  require_confirmation: boolean
  recipients: ProgramAlertRecipient[]
}

export interface ProgramProjectedTask {
  protocol_task_guid: string
  description: string
  occurs_on: string
  occurs_at: string
  important: boolean
  alerts: ProgramProjectedAlert[]
}

export interface ProgramTarget {
  guid?: string
  target_date: string
  animals: ProgramTargetAnimalRef[]
  // Solo presente en el detalle (DEC-15/DEC-16).
  tasks?: ProgramProjectedTask[]
}

export interface ProgramRef {
  guid: string
  name: string
}

// Opción de responsable consumida por ProgramManagerCheckboxGroup — `role` es el
// nombre técnico del rol (ej. 'vet', 'client-owner'), usado por RoleChip.
export interface ProgramManagerOption {
  guid: string
  label: string
  role: string
}

export interface ProgramManagerRef {
  guid: string
  name: string
  role: string
  // DEC-16: evita que el frontend duplique la lista de roles vet vs. cliente
  // que ya vive en UserProfileService::VET_STAFF_ROLES (backend).
  origin: 'vet' | 'client'
}

export interface ProgramListItem {
  guid: string
  client: ProgramRef
  establishment: ProgramRef
  technique: ProgramRef
  protocol: ProgramRef
  cancelled_at: string | null
  editable: boolean
  targets_count: number
  next_target_date: string | null
  created_at: string
}

export interface ProgramDetail extends Omit<ProgramListItem, 'targets_count' | 'next_target_date'> {
  comments: string | null
  targets: ProgramTarget[]
  managers: ProgramManagerRef[]
  updated_at: string
}

export interface ProgramListParams {
  technique_id?: string
  client_id?: string
  establishment_id?: string
  cancelled?: boolean
  search?: string
  page?: number
  per_page?: number
}

// DEC-07/DEC-10 (ver .claude/docs/plans/program-module-plan.md): contrato de animal por
// target — { id?: guid de Animal existente, rp: string } alimentado por un a-select mode="tags".
export interface AnimalInput {
  id?: string
  rp: string
}

export interface ProgramTargetPayload {
  guid?: string
  target_date: string
  animals: AnimalInput[]
}

export interface CreateProgramPayload {
  client_id: string
  establishment_id: string
  protocol_id: string
  comments: string | null
  targets: ProgramTargetPayload[]
  manager_profile_ids: string[]
}

export type UpdateProgramPayload = CreateProgramPayload

// Item devuelto por el endpoint de búsqueda de animales (DEC-10), consumido por el a-select.
export interface AnimalOption {
  guid: string
  rp: string
  name: string | null
}

// Subconjunto mínimo usado por ProgramCancelModal — tanto ProgramListItem (lista) como
// ProgramDetail (detalle) lo satisfacen estructuralmente, así el modal se reutiliza en ambas vistas.
export interface ProgramCancelTarget {
  guid: string
  client: ProgramRef
  establishment: ProgramRef
}

export interface ProgramNotEditableError {
  reason: 'not_editable'
}

export interface ProgramMustHaveOneTargetError {
  reason: 'must_have_one_target'
}
