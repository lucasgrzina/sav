import { ref } from 'vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { updateRoleApi } from '../api/roles.api'
import { useNotification } from '@/core/composables/useNotification'
import { parseApiError } from '@/core/composables/parseApiError'
import type { UpdateRolePayload } from '../types/role.types'

export function useUpdateRole() {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const fieldErrors = ref<Record<string, string> | null>(null)
  const generalError = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: ({ guid, payload }: { guid: string; payload: UpdateRolePayload }) =>
      updateRoleApi(guid, payload),
    onMutate: () => {
      fieldErrors.value = null
      generalError.value = null
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['roles'] })
      queryClient.invalidateQueries({ queryKey: ['role'] })
      success('Rol actualizado correctamente')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      fieldErrors.value = apiError.fieldErrors
      generalError.value = apiError.message ?? 'Error al actualizar el rol.'
      if (apiError.message) {
        error('Error al actualizar el rol')
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
