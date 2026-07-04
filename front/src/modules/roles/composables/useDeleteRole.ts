import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { deleteRoleApi } from '../api/roles.api'
import { useNotification } from '@/core/composables/useNotification'
import { useConfirm } from '@/core/composables/useConfirm'
import type { RoleItem } from '../types/role.types'

export function useDeleteRole() {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const confirm = useConfirm()

  const mutation = useMutation({
    mutationFn: (guid: string) => deleteRoleApi(guid),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['roles'] })
      success('Rol eliminado correctamente')
    },
    onError: () => {
      error('Error al eliminar el rol')
    },
  })

  async function deleteRole(role: RoleItem) {
    await confirm.confirm({
      title: 'Eliminar rol',
      message: `¿Estás seguro de que querés eliminar el rol "${role.name}"? Esta acción no se puede deshacer.`,
      confirmLabel: 'Eliminar',
      danger: true,
      onConfirm: () => mutation.mutateAsync(role.guid),
    })
  }

  return { ...mutation, deleteRole }
}
