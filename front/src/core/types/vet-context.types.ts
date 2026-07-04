export type VetTenantRole = 'vet' | 'vet-assistant' | 'vet-administrative'

export interface VetUserProfile {
  guid: string
  role: {
    name: VetTenantRole
    permissions: string[]
  }
}
