import { ref } from 'vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { updateClientApi } from '../api/clients.api'
import { useNotification } from '@/core/composables/useNotification'
import { parseApiError } from '@/core/composables/parseApiError'
import type { ClientUpdatePayload } from '../types/client.types'

export function useUpdateClient() {
  const queryClient  = useQueryClient()
  const { success, error } = useNotification()
  const route        = useRoute()
  const vetGuid      = computed(() => route.params.vetGuid as string)
  const fieldErrors  = ref<Record<string, string> | null>(null)
  const generalError = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: ({ guid, payload }: { guid: string; payload: ClientUpdatePayload }) =>
      updateClientApi(vetGuid.value, guid, payload),
    onMutate: () => {
      fieldErrors.value  = null
      generalError.value = null
    },
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['clients', vetGuid.value] })
      queryClient.invalidateQueries({ queryKey: ['client', vetGuid.value, variables.guid] })
      success('Cliente actualizado correctamente')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      fieldErrors.value  = apiError.fieldErrors
      generalError.value = apiError.message ?? 'Error al actualizar el cliente.'
      if (apiError.message) {
        error('Error al actualizar el cliente')
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
