import { ref } from 'vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { createSupportMessageApi } from '../api/support-messages.api'
import { useNotification } from '@/core/composables/useNotification'
import { parseApiError } from '@/core/composables/parseApiError'
import type { CreateSupportMessagePayload } from '../types/support-message.types'

export function useCreateSupportMessage() {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const fieldErrors = ref<Record<string, string> | null>(null)
  const generalError = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: (payload: CreateSupportMessagePayload) => createSupportMessageApi(payload),
    onMutate: () => {
      fieldErrors.value = null
      generalError.value = null
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['support-messages'] })
      success('Mensaje enviado correctamente')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      fieldErrors.value = apiError.fieldErrors
      generalError.value = apiError.message ?? 'Error al enviar el mensaje.'
      if (apiError.message) {
        error('Error al enviar el mensaje')
      }
    },
  })

  function resetErrors() {
    fieldErrors.value = null
    generalError.value = null
    mutation.reset()
  }

  return { ...mutation, fieldErrors, generalError, resetErrors }
}
