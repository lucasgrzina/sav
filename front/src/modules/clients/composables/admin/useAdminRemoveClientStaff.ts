import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { adminRemoveClientStaffApi } from '../../api/client-staff.api'
import { useNotification } from '@/core/composables/useNotification'
import { useConfirm } from '@/core/composables/useConfirm'
import { parseApiError } from '@/core/composables/parseApiError'
import type { ClientStaffItem } from '../../types/client.types'
import { PROGRAM_MANAGER_OPTIONS_KEY } from '@/modules/programs/composables/useClientManagerOptions'

export function useAdminRemoveClientStaff(clientGuid: string) {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const { confirm } = useConfirm()

  const mutation = useMutation({
    mutationFn: (profileGuid: string) => adminRemoveClientStaffApi(clientGuid, profileGuid),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-client-staff', clientGuid] })
      // Staff changes affect the staff shown per establishment
      queryClient.invalidateQueries({ queryKey: ['admin-client-establishments', clientGuid] })
      // Program form: client manager options (any vet) depend on linked/unblocked staff
      queryClient.invalidateQueries({ queryKey: [PROGRAM_MANAGER_OPTIONS_KEY] })
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
