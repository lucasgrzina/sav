import { USER_STATUS_COLORS, USER_STATUS_LABELS } from '../types/user.enums'

export const USERS_PER_PAGE = 15

export const USER_STATUS_OPTIONS = [
  { label: 'Verificado', value: 'verified' },
  { label: 'No verificado', value: 'unverified' },
  { label: 'Bloqueado', value: 'locked' },
]

// Re-exportamos para que los componentes importen desde constants
export { USER_STATUS_COLORS, USER_STATUS_LABELS }
