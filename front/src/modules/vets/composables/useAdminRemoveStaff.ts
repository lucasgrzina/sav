import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { adminRemoveStaffApi } from '../api/vet-staff.api'
import { useNotification } from '@/core/composables/useNotification'
import { useConfirm } from '@/core/composables/useConfirm'
import { parseApiError } from '@/core/composables/parseApiError'
import type { VetStaffItem } from '../types/vet.types'

export function useAdminRemoveStaff(vetGuid: string) {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const { confirm } = useConfirm()

  const mutation = useMutation({
    mutationFn: (profileGuid: string) => adminRemoveStaffApi(vetGuid, profileGuid),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-vet-staff', vetGuid] })
      success('Miembro eliminado correctamente')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      error(apiError.message ?? 'Error al eliminar el miembro.')
    },
  })

  async function removeStaff(member: VetStaffItem): Promise<void> {
    await confirm({
      title:        'Eliminar miembro',
      message:      `¿Estás seguro de que querés eliminar a "${member.user.name}" del staff de esta veterinaria?`,
      confirmLabel: 'Eliminar',
      danger:       true,
      onConfirm:    () => mutation.mutateAsync(member.guid),
    })
  }

  return { ...mutation, removeStaff }
}
