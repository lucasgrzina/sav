import { computed, ref } from 'vue'
import type { Ref } from 'vue'

export const ALERT_MESSAGE_SOFT_LIMIT = 200

export function activeProgramsConfirmMessage(count: number): string {
  const subject =
    count === 1 ? 'Este protocolo se usa en 1 programa activo.' : `Este protocolo se usa en ${count} programas activos.`
  const tail =
    count === 1
      ? 'Los avisos pendientes de ese programa se van a actualizar.'
      : 'Los avisos pendientes de esos programas se van a actualizar.'
  return `${subject} ${tail}`
}

/**
 * Gates a protocol edit submit behind a confirmation when the protocol is used by
 * active programs. Informational only (no server-side confirmation until V2).
 */
export function useActiveProgramsConfirm<T>(
  activeProgramsCount: Ref<number | undefined>,
  isEditMode: Ref<boolean>,
  proceed: (values: T) => void,
) {
  const confirmOpen = ref(false)
  const pendingValues = ref<T | null>(null) as Ref<T | null>

  const count = computed(() => activeProgramsCount.value ?? 0)
  const message = computed(() => activeProgramsConfirmMessage(count.value))

  function requestSubmit(values: T) {
    if (isEditMode.value && count.value > 0) {
      pendingValues.value = values
      confirmOpen.value = true
      return
    }
    proceed(values)
  }

  function confirm() {
    const values = pendingValues.value
    confirmOpen.value = false
    pendingValues.value = null
    if (values) proceed(values)
  }

  function cancel() {
    confirmOpen.value = false
    pendingValues.value = null
  }

  return { confirmOpen, message, requestSubmit, confirm, cancel }
}
