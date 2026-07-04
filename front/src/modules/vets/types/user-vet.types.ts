import type { VetTenantRole } from '@/core/types/vet-context.types'

export interface UserVetItem {
  guid: string
  name: string
  slug: string
  logo_path: string | null
  is_active: boolean
  role: {
    name: VetTenantRole
    permissions: string[]
  }
}
