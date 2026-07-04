import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { adminRemoveClientStaffApi } from '../../api/client-staff.api'
import { useNotification } from '@/core/composables/useNotification'
import { useConfirm } from '@/core/composables/useConfirm'
import { parseApiError } from '@/core/composables/parseApiError'
import type { ClientStaffItem } from '../../types/client.types'

export function useAdminRemoveClientStaff(clientGuid: string) {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const { confirm } = useConfirm()

  const mutation = useMutation({
    mutationFn: (profileGuid: string) => adminRemoveClientStaffApi(clientGuid, profileGuid),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-client-staff', clientGuid] })
      success('Miembro eliminado correctamente')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      error(apiError.message ?? 'Error al eliminar el miembro.')
    },
  })

  async function removeStaff(member: ClientStaffItem): Promise<void> {
    await confirm({
      title:        'Eliminar miembro',
      message:      `¿Estás seguro de que querés eliminar a "${member.user.name}" del staff de este cliente?`,
      confirmLabel: 'Eliminar',
      danger:       true,
      onConfirm:    () => mutation.mutateAsync(member.guid),
    })
  }

  return { ...mutation, removeStaff }
}
