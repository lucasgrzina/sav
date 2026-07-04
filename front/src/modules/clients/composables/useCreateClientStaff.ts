import { ref, computed } from 'vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { useRoute } from 'vue-router'
import { createClientStaffApi } from '../api/client-staff.api'
import { useNotification } from '@/core/composables/useNotification'
import { parseApiError } from '@/core/composables/parseApiError'
import type { ClientStaffCreatePayload } from '../types/client.types'

export function useCreateClientStaff() {
  const queryClient        = useQueryClient()
  const { success, error } = useNotification()
  const route              = useRoute()
  const vetGuid            = computed(() => route.params.vetGuid as string)
  const clientGuid         = computed(() => route.params.clientGuid as string)
  const fieldErrors        = ref<Record<string, string> | null>(null)
  const generalError       = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: (payload: ClientStaffCreatePayload) =>
      createClientStaffApi(vetGuid.value, clientGuid.value, payload),
    onMutate: () => {
      fieldErrors.value  = null
      generalError.value = null
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['client-staff', vetGuid.value, clientGuid.value] })
      success('Usuario creado e incorporado al equipo correctamente')
    },
    onError: (err: unknown) => {
      const apiError     = parseApiError(err)
      fieldErrors.value  = apiError.fieldErrors
      generalError.value = apiError.message ?? 'Error al crear el usuario.'
      if (apiError.message) error('Error al crear el usuario')
    },
  })

  function resetErrors(): void {
    fieldErrors.value  = null
    generalError.value = null
    mutation.reset()
  }

  return { ...mutation, fieldErrors, generalError, resetErrors }
}
