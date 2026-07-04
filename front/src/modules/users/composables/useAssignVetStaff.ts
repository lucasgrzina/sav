import { ref, computed } from 'vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { useRoute } from 'vue-router'
import { assignVetStaffApi } from '@/modules/vets/api/vet-staff.api'
import { useNotification } from '@/core/composables/useNotification'
import { parseApiError } from '@/core/composables/parseApiError'
import type { VetStaffAssignPayload } from '@/modules/vets/types/vet.types'

export function useAssignVetStaff() {
  const queryClient        = useQueryClient()
  const { success, error } = useNotification()
  const route              = useRoute()
  const vetGuid            = computed(() => route.params.vetGuid as string)
  const fieldErrors        = ref<Record<string, string> | null>(null)
  const generalError       = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: (payload: VetStaffAssignPayload) => assignVetStaffApi(vetGuid.value, payload),
    onMutate: () => {
      fieldErrors.value  = null
      generalError.value = null
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['users'] })
      queryClient.invalidateQueries({ queryKey: ['staff', vetGuid.value] })
      success('Personal incorporado al equipo correctamente')
    },
    onError: (err: unknown) => {
      const apiError     = parseApiError(err)
      fieldErrors.value  = apiError.fieldErrors
      generalError.value = apiError.message ?? 'Error al incorporar al personal.'
      if (apiError.message) error('Error al incorporar al personal')
    },
  })

  function resetErrors(): void {
    fieldErrors.value  = null
    generalError.value = null
    mutation.reset()
  }

  return { ...mutation, fieldErrors, generalError, resetErrors }
}
