import { ref } from 'vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { createContactApi } from '../api/clients.api'
import { useNotification } from '@/core/composables/useNotification'
import { parseApiError } from '@/core/composables/parseApiError'
import type { ContactCreatePayload } from '../types/client.types'

export function useCreateContact() {
  const queryClient  = useQueryClient()
  const { success, error } = useNotification()
  const route        = useRoute()
  const vetGuid      = computed(() => route.params.vetGuid as string)
  const fieldErrors  = ref<Record<string, string> | null>(null)
  const generalError = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: ({ clientGuid, payload }: { clientGuid: string; payload: ContactCreatePayload }) =>
      createContactApi(vetGuid.value, clientGuid, payload),
    onMutate: () => {
      fieldErrors.value  = null
      generalError.value = null
    },
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['client-contacts', vetGuid.value, variables.clientGuid] })
      queryClient.invalidateQueries({ queryKey: ['client', vetGuid.value, variables.clientGuid] })
      success('Contacto creado correctamente')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      fieldErrors.value  = apiError.fieldErrors
      generalError.value = apiError.message ?? 'Error al crear el contacto.'
      if (apiError.message) {
        error('Error al crear el contacto')
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
