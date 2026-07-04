import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { toggleLockUserApi } from '@/modules/users/api/users.api'
import { useNotification } from '@/core/composables/useNotification'
import { useConfirm } from '@/core/composables/useConfirm'
import type { UserItem } from '../types/user.types'

export function useToggleLock() {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const confirm = useConfirm()

  const mutation = useMutation({
    mutationFn: (guid: string) => toggleLockUserApi(guid),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['users'] })
      success('Estado del usuario actualizado')
    },
    onError: () => {
      error('Error al cambiar el estado')
    },
  })

  async function toggleLockUser(user: UserItem) {
    await confirm.confirm({
      title: user.locked_at ? 'Desbloquear usuario' : 'Bloquear usuario',
      message: '¿Estás seguro de que querés realizar esta acción?',
      onConfirm: () => mutation.mutateAsync(user.guid),
    })
  }

  return { ...mutation, toggleLockUser }
}
