import type { VetStatus } from './vet.types'

export const VET_STATUS_LABELS: Record<VetStatus, string> = {
  active: 'Activa',
  pending: 'Pendiente validación',
  suspended: 'Suspendida',
}

export const VET_STATUS_COLORS: Record<VetStatus, string> = {
  active: 'success',
  pending: 'warning',
  suspended: 'error',
}
