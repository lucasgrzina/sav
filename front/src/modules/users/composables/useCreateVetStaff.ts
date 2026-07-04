import { ref, computed } from 'vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { useRoute } from 'vue-router'
import { createVetStaffApi } from '@/modules/vets/api/vet-staff.api'
import { useNotification } from '@/core/composables/useNotification'
import { parseApiError } from '@/core/composables/parseApiError'
import type { VetStaffCreatePayload } from '@/modules/vets/types/vet.types'

export function useCreateVetStaff() {
  const queryClient        = useQueryClient()
  const { success, error } = useNotification()
  const route              = useRoute()
  const vetGuid            = computed(() => route.params.vetGuid as string)
  const fieldErrors        = ref<Record<string, string> | null>(null)
  const generalError       = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: (payload: VetStaffCreatePayload) => createVetStaffApi(vetGuid.value, payload),
    onMutate: () => {
      fieldErrors.value  = null
      generalError.value = null
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['users'] })
      queryClient.invalidateQueries({ queryKey: ['staff', vetGuid.value] })
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
