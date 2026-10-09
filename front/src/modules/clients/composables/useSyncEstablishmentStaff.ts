import { computed, ref } from 'vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { useRoute } from 'vue-router'
import { syncEstablishmentStaffApi } from '../api/clients.api'
import { PROGRAM_MANAGER_OPTIONS_KEY } from '@/modules/programs/composables/useClientManagerOptions'
import { useNotification } from '@/core/composables/useNotification'
import { parseApiError } from '@/core/composables/parseApiError'
import type { EstablishmentStaffSyncPayload } from '../types/client.types'

interface RawApiError {
  status?: number
  message?: string
  errors?: Record<string, unknown> | null
}

export function useSyncEstablishmentStaff() {
  const queryClient        = useQueryClient()
  const { success, error } = useNotification()
  const route              = useRoute()
  const vetGuid            = computed(() => route.params.vetGuid as string)
  const fieldErrors        = ref<Record<string, string> | null>(null)
  const generalError       = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: ({
      clientGuid,
      estGuid,
      payload,
    }: {
      clientGuid: string
      estGuid: string
      payload: EstablishmentStaffSyncPayload
    }) => syncEstablishmentStaffApi(vetGuid.value, clientGuid, estGuid, payload),
    onMutate: () => {
      fieldErrors.value  = null
      generalError.value = null
    },
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['client-establishments', vetGuid.value, variables.clientGuid] })
      queryClient.invalidateQueries({ queryKey: ['client-staff', vetGuid.value, variables.clientGuid] })
      // Program form: client manager options depend on linked/unblocked staff
      queryClient.invalidateQueries({ queryKey: [PROGRAM_MANAGER_OPTIONS_KEY, vetGuid.value, variables.clientGuid] })
      queryClient.invalidateQueries({ queryKey: ['client', vetGuid.value, variables.clientGuid] })
      // Desvincular a alguien lo quita como responsable de programas activos del establecimiento
      queryClient.invalidateQueries({ queryKey: ['programs'] })
      queryClient.invalidateQueries({ queryKey: ['program'] })
      success('Personal del establecimiento actualizado correctamente')
    },
    onError: (err: unknown) => {
      const raw = err as RawApiError
      if (raw?.status === 422 && raw.errors && raw.errors.reason === 'staff_client_mismatch') {
        generalError.value = raw.message ?? 'Uno o más perfiles seleccionados no son personal de este cliente.'
        error(generalError.value)
        return
      }
      const apiError = parseApiError(err)
      fieldErrors.value  = apiError.fieldErrors
      generalError.value = apiError.message ?? 'Error al actualizar el personal del establecimiento.'
      error('Error al actualizar el personal del establecimiento')
    },
  })

  function resetErrors(): void {
    fieldErrors.value  = null
    generalError.value = null
    mutation.reset()
  }

  return { ...mutation, fieldErrors, generalError, resetErrors }
}
