import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { adminDeleteEstablishmentApi } from '../../api/clients.api'
import { useNotification } from '@/core/composables/useNotification'
import { useConfirm } from '@/core/composables/useConfirm'
import type { EstablishmentItem } from '../../types/client.types'

export function useAdminDeleteEstablishment(clientGuid: string) {
  const queryClient        = useQueryClient()
  const { success, error } = useNotification()
  const confirm            = useConfirm()

  const mutation = useMutation({
    mutationFn: (estGuid: string) => adminDeleteEstablishmentApi(clientGuid, estGuid),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-client-establishments', clientGuid] })
      queryClient.invalidateQueries({ queryKey: ['admin-client', clientGuid] })
      success('Establecimiento eliminado correctamente')
    },
    onError: () => {
      error('Error al eliminar el establecimiento')
    },
  })

  async function deleteEstablishment(establishment: EstablishmentItem): Promise<void> {
    await confirm.confirm({
      title:        'Eliminar establecimiento',
      message:      `¿Estás seguro de que querés eliminar "${establishment.name}"? Esta acción no se puede deshacer.`,
      confirmLabel: 'Eliminar',
      danger:       true,
      onConfirm:    () => mutation.mutateAsync(establishment.guid),
    })
  }

  return { ...mutation, deleteEstablishment }
}
