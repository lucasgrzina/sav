import { ref } from 'vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { createTenantUserApi } from '@/modules/users/api/users.api'
import { useNotification } from '@/core/composables/useNotification'
import { parseApiError } from '@/core/composables/parseApiError'
import type { TenantUserCreatePayload } from '../types/user.types'

export function useCreateTenantUser() {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const fieldErrors = ref<Record<string, string> | null>(null)
  const generalError = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: (payload: TenantUserCreatePayload) => createTenantUserApi(payload),
    onMutate: () => {
      fieldErrors.value = null
      generalError.value = null
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['users'] })
      success('Usuario de tenant creado correctamente')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      fieldErrors.value = apiError.fieldErrors
      generalError.value = apiError.message ?? 'Error al crear el usuario.'
      if (apiError.message) {
        error('Error al crear el usuario de tenant')
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
