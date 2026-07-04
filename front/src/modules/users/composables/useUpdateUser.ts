import { ref } from 'vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { updateUserApi } from '@/modules/users/api/users.api'
import { useNotification } from '@/core/composables/useNotification'
import { parseApiError } from '@/core/composables/parseApiError'
import { useAuthStore } from '@/modules/auth/stores/auth.store'
import type { UserUpdatePayload } from '../types/user.types'

export function useUpdateUser() {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const authStore = useAuthStore()
  const fieldErrors = ref<Record<string, string> | null>(null)
  const generalError = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: ({ guid, payload }: { guid: string; payload: UserUpdatePayload }) =>
      updateUserApi(guid, payload),
    onMutate: () => {
      fieldErrors.value = null
      generalError.value = null
    },
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['users'] })
      queryClient.invalidateQueries({ queryKey: ['user', variables.guid] })
      if (authStore.user?.guid === variables.guid) {
        authStore.fetchUser()
      }
      success('Usuario actualizado correctamente')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      
      fieldErrors.value = apiError.fieldErrors      
      generalError.value = apiError.message ?? 'Error al actualizar el usuario.'
      
      if (apiError.message) {
        error('Error al actualizar el usuario')  
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
