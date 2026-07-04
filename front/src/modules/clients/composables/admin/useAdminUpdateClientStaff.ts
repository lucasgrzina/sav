import { computed, ref, toValue } from 'vue'
import type { MaybeRef } from 'vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { adminUpdateClientStaffApi } from '../../api/client-staff.api'
import { useNotification } from '@/core/composables/useNotification'
import { parseApiError } from '@/core/composables/parseApiError'
import type { UpdateClientStaffPayload } from '../../types/client.types'

export function useAdminUpdateClientStaff(clientGuid: MaybeRef<string>) {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const cGuid = computed(() => toValue(clientGuid))

  const fieldErrors = ref<Record<string, string> | null>(null)
  const generalError = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: ({ profileGuid, payload }: { profileGuid: string; payload: UpdateClientStaffPayload }) =>
      adminUpdateClientStaffApi(cGuid.value, profileGuid, payload),
    onMutate: () => {
      fieldErrors.value = null
      generalError.value = null
    },
    onSuccess: (_, vars) => {
      queryClient.invalidateQueries({ queryKey: ['admin-client-staff', cGuid.value] })
      queryClient.invalidateQueries({ queryKey: ['admin-client-staff-member', cGuid.value, vars.profileGuid] })
      success('Perfil actualizado correctamente.')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      fieldErrors.value = apiError.fieldErrors ?? null
      generalError.value = apiError.message ?? 'Error al actualizar el perfil.'
      if (apiError.message) error(apiError.message)
    },
  })

  function resetErrors() {
    fieldErrors.value = null
    generalError.value = null
    mutation.reset()
  }

  return { ...mutation, fieldErrors, generalError, resetErrors }
}
