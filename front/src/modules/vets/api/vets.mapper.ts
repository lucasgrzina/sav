import type { VetItem, VetStatus } from '../types/vet.types'

export function getVetStatus(vet: VetItem): VetStatus {
  if (vet.suspended_at !== null) return 'suspended'
  if (vet.validated_at === null) return 'pending'
  return 'active'
}
