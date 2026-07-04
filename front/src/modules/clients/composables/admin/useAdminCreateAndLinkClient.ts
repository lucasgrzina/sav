import { ref } from 'vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { adminCreateClientApi, adminLinkVetToClientApi } from '../../api/admin-clients.api'
import { useNotification } from '@/core/composables/useNotification'
import { parseApiError } from '@/core/composables/parseApiError'
import type { ClientCreatePayload, ClientItem } from '../../types/client.types'

export function useAdminCreateAndLinkClient(vetGuid: string) {
  const queryClient  = useQueryClient()
  const { success, error } = useNotification()
  const fieldErrors  = ref<Record<string, string> | null>(null)
  const generalError = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: async (payload: ClientCreatePayload): Promise<ClientItem> => {
      // Paso 1: crear el cliente (sin vet)
      const newClient = await adminCreateClientApi(payload)
      // Paso 2: vincular a la vet. Si falla aquí, el cliente existe pero no vinculado.
      // El error se propaga y onError lo informa al usuario.
      await adminLinkVetToClientApi(newClient.guid, vetGuid)
      return newClient
    },
    onMutate: () => {
      fieldErrors.value  = null
      generalError.value = null
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-clients'] })
      queryClient.invalidateQueries({ queryKey: ['admin-vet-clients', vetGuid] })
      success('Cliente creado y vinculado correctamente')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      fieldErrors.value  = apiError.fieldErrors
      generalError.value = apiError.message ?? 'Error al crear el cliente.'
      if (apiError.message) error(apiError.message)
    },
  })

  function resetErrors(): void {
    fieldErrors.value  = null
    generalError.value = null
    mutation.reset()
  }

  return { ...mutation, fieldErrors, generalError, resetErrors }
}
