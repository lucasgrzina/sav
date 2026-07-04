import { ref } from 'vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { updateContactApi } from '../api/clients.api'
import { useNotification } from '@/core/composables/useNotification'
import { parseApiError } from '@/core/composables/parseApiError'
import type { ContactUpdatePayload } from '../types/client.types'

export function useUpdateContact() {
  const queryClient  = useQueryClient()
  const { success, error } = useNotification()
  const route        = useRoute()
  const vetGuid      = computed(() => route.params.vetGuid as string)
  const fieldErrors  = ref<Record<string, string> | null>(null)
  const generalError = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: ({
      clientGuid,
      contactGuid,
      payload,
    }: {
      clientGuid: string
      contactGuid: string
      payload: ContactUpdatePayload
    }) => updateContactApi(vetGuid.value, clientGuid, contactGuid, payload),
    onMutate: () => {
      fieldErrors.value  = null
      generalError.value = null
    },
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['client-contacts', vetGuid.value, variables.clientGuid] })
      queryClient.invalidateQueries({ queryKey: ['client', vetGuid.value, variables.clientGuid] })
      success('Contacto actualizado correctamente')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      fieldErrors.value  = apiError.fieldErrors
      generalError.value = apiError.message ?? 'Error al actualizar el contacto.'
      if (apiError.message) {
        error('Error al actualizar el contacto')
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
