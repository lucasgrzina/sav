import { ref } from 'vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { updateTutorialApi } from '../api/tutorials.api'
import { useNotification } from '@/core/composables/useNotification'
import { parseApiError } from '@/core/composables/parseApiError'
import type { UpdateTutorialPayload } from '../types/tutorial.types'

export function useUpdateTutorial() {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const fieldErrors = ref<Record<string, string> | null>(null)
  const generalError = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: ({ guid, payload }: { guid: string; payload: UpdateTutorialPayload }) =>
      updateTutorialApi(guid, payload),
    onMutate: () => {
      fieldErrors.value = null
      generalError.value = null
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['tutorials'] })
      success('Tutorial actualizado correctamente')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      fieldErrors.value = apiError.fieldErrors
      generalError.value = apiError.message ?? 'Error al actualizar el tutorial.'
      if (apiError.message) {
        error('Error al actualizar el tutorial')
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
