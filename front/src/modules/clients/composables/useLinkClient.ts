import { ref } from 'vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { linkClientApi } from '../api/clients.api'
import { useNotification } from '@/core/composables/useNotification'
import { parseApiError } from '@/core/composables/parseApiError'

export function useLinkClient() {
  const queryClient  = useQueryClient()
  const { success, error } = useNotification()
  const route        = useRoute()
  const vetGuid      = computed(() => route.params.vetGuid as string)
  const generalError = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: (clientGuid: string) => linkClientApi(vetGuid.value, clientGuid),
    onMutate: () => {
      generalError.value = null
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['clients', vetGuid.value] })
      success('Cliente vinculado correctamente')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      generalError.value = apiError.message ?? 'Error al vincular el cliente.'
      error(generalError.value)
    },
  })

  function resetErrors(): void {
    generalError.value = null
    mutation.reset()
  }

  return { ...mutation, generalError, resetErrors }
}
