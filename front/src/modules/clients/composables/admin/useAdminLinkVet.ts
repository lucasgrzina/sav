import { ref } from 'vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { adminLinkVetToClientApi } from '../../api/admin-clients.api'
import { useNotification } from '@/core/composables/useNotification'
import { parseApiError } from '@/core/composables/parseApiError'

export function useAdminLinkVet(clientGuid: string) {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const generalError = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: (vetGuid: string) => adminLinkVetToClientApi(clientGuid, vetGuid),
    onMutate: () => {
      generalError.value = null
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-client', clientGuid] })
      success('Veterinaria vinculada correctamente')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      generalError.value = apiError.message ?? 'Error al vincular la veterinaria.'
      error(generalError.value)
    },
  })

  return { ...mutation, generalError }
}
