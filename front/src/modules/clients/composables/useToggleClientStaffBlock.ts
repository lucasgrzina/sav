import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { toggleBlockClientStaffApi } from '../api/client-staff.api'
import { useNotification } from '@/core/composables/useNotification'
import { useConfirm } from '@/core/composables/useConfirm'
import { parseApiError } from '@/core/composables/parseApiError'
import type { ClientStaffItem } from '../types/client.types'

export function useToggleClientStaffBlock(vetGuid: string, clientGuid: string) {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const { confirm } = useConfirm()

  const mutation = useMutation({
    mutationFn: (profileGuid: string) => toggleBlockClientStaffApi(vetGuid, clientGuid, profileGuid),
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ['client-staff', vetGuid, clientGuid] })
      const msg = data.blocked_at
        ? 'Acceso bloqueado para este cliente.'
        : 'Acceso desbloqueado correctamente.'
      success(msg)
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      error(apiError.message ?? 'Error al cambiar el estado del miembro.')
    },
  })

  async function toggleBlock(member: ClientStaffItem): Promise<void> {
    const isBlocked = Boolean(member.blocked_at)
    await confirm({
      title:        isBlocked ? 'Desbloquear acceso' : 'Bloquear acceso',
      message:      isBlocked
        ? `¿Querés restablecer el acceso de "${member.user.name}" a este cliente?`
        : `¿Querés bloquear el acceso de "${member.user.name}" a este cliente? El usuario seguirá existiendo en el sistema.`,
      confirmLabel: isBlocked ? 'Desbloquear' : 'Bloquear',
      danger:       !isBlocked,
      onConfirm:    () => mutation.mutateAsync(member.guid),
    })
  }

  return { ...mutation, toggleBlock }
}
