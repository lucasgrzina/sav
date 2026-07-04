import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { closeThreadApi } from '../api/support-messages.api'
import { useNotification } from '@/core/composables/useNotification'
import { parseApiError } from '@/core/composables/parseApiError'

export function useCloseThread() {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()

  const mutation = useMutation({
    mutationFn: (guid: string) => closeThreadApi(guid),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['support-messages'] })
      success('Mensaje cerrado correctamente')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      error(apiError.message ?? 'Error al cerrar el mensaje')
    },
  })

  return { ...mutation }
}
