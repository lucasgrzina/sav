import { computed, toValue } from 'vue'
import type { MaybeRef } from 'vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { adminUpdateVetStaffApi } from '../api/vet-staff.api'
import { useNotification } from '@/core/composables/useNotification'
import { parseApiError } from '@/core/composables/parseApiError'
import type { UpdateVetStaffPayload } from '../types/vet.types'

export function useAdminUpdateVetStaff(vetGuid: MaybeRef<string>) {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const vGuid = computed(() => toValue(vetGuid))

  const mutation = useMutation({
    mutationFn: ({ profileGuid, payload }: { profileGuid: string; payload: UpdateVetStaffPayload }) =>
      adminUpdateVetStaffApi(vGuid.value, profileGuid, payload),
    onSuccess: (_, vars) => {
      queryClient.invalidateQueries({ queryKey: ['admin-vet-staff', vGuid.value] })
      queryClient.invalidateQueries({ queryKey: ['admin-vet-staff-member', vGuid.value, vars.profileGuid] })
      success('Perfil actualizado correctamente.')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      error(apiError.message ?? 'Error al actualizar el perfil.')
    },
  })

  return mutation
}
