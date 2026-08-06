import type { ProtocolTask, ProtocolTaskPayload } from './protocol.types'

export interface VetProtocolTechniqueRef {
  guid: string
  name: string
}

export interface VetProtocolListItem {
  guid: string
  name: string
  color: string | null
  technique: VetProtocolTechniqueRef
  is_global: boolean
  is_own: boolean
  tasks_count: number
  created_at: string
}

export interface VetProtocolDetail extends VetProtocolListItem {
  updated_at: string
  tasks: ProtocolTask[]
}

export interface VetProtocolListParams {
  technique_id?: string
  search?: string
  page?: number
  per_page?: number
}

export interface CreateVetProtocolPayload {
  technique_id: string
  name: string
  color: string | null
  tasks: ProtocolTaskPayload[]
}

export type UpdateVetProtocolPayload = CreateVetProtocolPayload
