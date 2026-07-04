import { computed, toValue } from 'vue'
import type { MaybeRef } from 'vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { updateMyVetProfileApi } from '../api/my-vet-profile.api'
import { useNotification } from '@/core/composables/useNotification'
import { parseApiError } from '@/core/composables/parseApiError'
import { useAuthStore } from '@/modules/auth/stores/auth.store'
import type { UpdateMyVetProfilePayload } from '../types/vet.types'

export function useUpdateMyVetProfile(vetGuid: MaybeRef<string>) {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const authStore = useAuthStore()
  const vGuid = computed(() => toValue(vetGuid))

  return useMutation({
    mutationFn: (payload: UpdateMyVetProfilePayload) =>
      updateMyVetProfileApi(vGuid.value, payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['my-vet-profile', vGuid.value] })
      authStore.fetchUser()
      success('Tu perfil fue actualizado correctamente.')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      error(apiError.message ?? 'Error al actualizar el perfil.')
    },
  })
}
