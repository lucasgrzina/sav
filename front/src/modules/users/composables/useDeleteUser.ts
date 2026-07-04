import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { deleteUserApi } from '@/modules/users/api/users.api'
import { useNotification } from '@/core/composables/useNotification'
import { useConfirm } from '@/core/composables/useConfirm'
import type { UserItem } from '../types/user.types'

export function useDeleteUser() {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const confirm = useConfirm()

  const mutation = useMutation({
    mutationFn: (guid: string) => deleteUserApi(guid),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['users'] })
      success('Usuario eliminado correctamente')
    },
    onError: () => {
      error('Error al eliminar el usuario')
    },
  })

  async function deleteUser(item: UserItem) {
    await confirm.confirm({
      title: 'Eliminar usuario',
      message: `¿Estás seguro de que querés eliminar el usuario "${item.name}"? Esta acción no se puede deshacer.`,
      confirmLabel: 'Eliminar',
      danger: true,
      onConfirm: () => mutation.mutateAsync(item.guid),
    })
  }

  return { ...mutation, deleteUser }
}
