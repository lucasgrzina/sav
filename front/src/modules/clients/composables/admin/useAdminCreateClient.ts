import { ref } from 'vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { adminCreateClientApi } from '../../api/admin-clients.api'
import { useNotification } from '@/core/composables/useNotification'
import { parseApiError } from '@/core/composables/parseApiError'
import type { ClientCreatePayload } from '../../types/client.types'

export function useAdminCreateClient() {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const fieldErrors  = ref<Record<string, string> | null>(null)
  const generalError = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: (payload: ClientCreatePayload) => adminCreateClientApi(payload),
    onMutate: () => {
      fieldErrors.value  = null
      generalError.value = null
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-clients'] })
      success('Cliente creado correctamente')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      fieldErrors.value  = apiError.fieldErrors
      generalError.value = apiError.message ?? 'Error al crear el cliente.'
      if (apiError.message) error('Error al crear el cliente')
    },
  })

  function resetErrors(): void {
    fieldErrors.value  = null
    generalError.value = null
    mutation.reset()
  }

  return { ...mutation, fieldErrors, generalError, resetErrors }
}
