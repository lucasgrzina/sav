import { ref } from 'vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { adminUpdateEstablishmentApi } from '../../api/clients.api'
import { useNotification } from '@/core/composables/useNotification'
import { parseApiError } from '@/core/composables/parseApiError'
import type { EstablishmentUpdatePayload } from '../../types/client.types'

export function useAdminUpdateEstablishment() {
  const queryClient          = useQueryClient()
  const { success, error }   = useNotification()
  const fieldErrors          = ref<Record<string, string> | null>(null)
  const generalError         = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: ({
      clientGuid,
      estGuid,
      payload,
    }: { clientGuid: string; estGuid: string; payload: EstablishmentUpdatePayload }) =>
      adminUpdateEstablishmentApi(clientGuid, estGuid, payload),
    onMutate: () => {
      fieldErrors.value  = null
      generalError.value = null
    },
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['admin-client-establishments', variables.clientGuid] })
      queryClient.invalidateQueries({ queryKey: ['admin-client', variables.clientGuid] })
      success('Establecimiento actualizado correctamente')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      fieldErrors.value  = apiError.fieldErrors
      generalError.value = apiError.message ?? 'Error al actualizar el establecimiento.'
      if (apiError.message) {
        error('Error al actualizar el establecimiento')
      }
    },
  })

  function resetErrors(): void {
    fieldErrors.value  = null
    generalError.value = null
    mutation.reset()
  }

  return { ...mutation, fieldErrors, generalError, resetErrors }
}
