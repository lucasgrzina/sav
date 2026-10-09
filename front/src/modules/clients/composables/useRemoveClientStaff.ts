import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { PROGRAM_MANAGER_OPTIONS_KEY } from '@/modules/programs/composables/useClientManagerOptions'
import { removeClientStaffApi } from '../api/client-staff.api'
import { useNotification } from '@/core/composables/useNotification'
import { useConfirm } from '@/core/composables/useConfirm'
import { parseApiError } from '@/core/composables/parseApiError'
import type { ClientStaffItem } from '../types/client.types'

export function useRemoveClientStaff(vetGuid: string, clientGuid: string) {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const { confirm } = useConfirm()

  const mutation = useMutation({
    mutationFn: (profileGuid: string) => removeClientStaffApi(vetGuid, clientGuid, profileGuid),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['client-staff', vetGuid, clientGuid] })
      // Staff changes affect the staff shown per establishment
      queryClient.invalidateQueries({ queryKey: ['client-establishments', vetGuid, clientGuid] })
      // Program form: client manager options depend on linked/unblocked staff
      queryClient.invalidateQueries({ queryKey: [PROGRAM_MANAGER_OPTIONS_KEY, vetGuid, clientGuid] })
      success('Miembro eliminado correctamente.')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      error(apiError.message ?? 'Error al eliminar el miembro.')
    },
  })

  async function removeStaff(member: ClientStaffItem): Promise<void> {
    await confirm({
      title:        'Eliminar miembro',
      message:      `¿Estás seguro de que querés eliminar a "${member.user.name}" de este cliente? El usuario seguirá existiendo en el sistema.`,
      confirmLabel: 'Eliminar',
      danger:       true,
      onConfirm:    () => mutation.mutateAsync(member.guid),
    })
  }

  return { ...mutation, removeStaff }
}
