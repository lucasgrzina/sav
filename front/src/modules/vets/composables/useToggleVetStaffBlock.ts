import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { toggleBlockVetStaffApi } from '../api/vet-staff.api'
import { useNotification } from '@/core/composables/useNotification'
import { useConfirm } from '@/core/composables/useConfirm'
import { parseApiError } from '@/core/composables/parseApiError'
import type { VetStaffItem } from '../types/vet.types'

export function useToggleVetStaffBlock(vetGuid: string) {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const { confirm } = useConfirm()

  const mutation = useMutation({
    mutationFn: (profileGuid: string) => toggleBlockVetStaffApi(vetGuid, profileGuid),
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ['vet-staff', vetGuid] })
      const msg = data.blocked_at
        ? 'Acceso bloqueado para esta veterinaria.'
        : 'Acceso desbloqueado correctamente.'
      success(msg)
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      error(apiError.message ?? 'Error al cambiar el estado del miembro.')
    },
  })

  async function toggleBlock(member: VetStaffItem): Promise<void> {
    const isBlocked = Boolean(member.blocked_at)
    await confirm({
      title:        isBlocked ? 'Desbloquear acceso' : 'Bloquear acceso',
      message:      isBlocked
        ? `¿Querés restablecer el acceso de "${member.user.name}" a esta veterinaria?`
        : `¿Querés bloquear el acceso de "${member.user.name}" a esta veterinaria? El usuario seguirá existiendo en el sistema.`,
      confirmLabel: isBlocked ? 'Desbloquear' : 'Bloquear',
      danger:       !isBlocked,
      onConfirm:    () => mutation.mutateAsync(member.guid),
    })
  }

  return { ...mutation, toggleBlock }
}
