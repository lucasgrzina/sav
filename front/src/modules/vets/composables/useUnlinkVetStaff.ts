import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { removeVetStaffApi } from '../api/vet-staff.api'
import { useNotification } from '@/core/composables/useNotification'
import { useConfirm } from '@/core/composables/useConfirm'
import { parseApiError } from '@/core/composables/parseApiError'
import type { VetStaffItem } from '../types/vet.types'

export function useUnlinkVetStaff(vetGuid: string) {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const { confirm } = useConfirm()

  const mutation = useMutation({
    mutationFn: (profileGuid: string) => removeVetStaffApi(vetGuid, profileGuid),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['vet-staff', vetGuid] })
      success('Miembro desvinculado correctamente.')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      error(apiError.message ?? 'Error al desvincular el miembro.')
    },
  })

  async function unlinkStaff(member: VetStaffItem): Promise<void> {
    await confirm({
      title:        'Desvincular usuario',
      message:      `¿Estás seguro de que querés desvincular a "${member.user.name}" de esta veterinaria? El usuario seguirá existiendo en el sistema.`,
      confirmLabel: 'Desvincular',
      danger:       true,
      onConfirm:    () => mutation.mutateAsync(member.guid),
    })
  }

  return { ...mutation, unlinkStaff }
}
