import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { deleteSupportMessageApi } from '../api/support-messages.api'
import { useNotification } from '@/core/composables/useNotification'
import { useConfirm } from '@/core/composables/useConfirm'
import type { SupportMessage } from '../types/support-message.types'

export function useDeleteSupportMessage() {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const confirm = useConfirm()

  const mutation = useMutation({
    mutationFn: (guid: string) => deleteSupportMessageApi(guid),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['support-messages'] })
      success('Mensaje eliminado correctamente')
    },
    onError: () => {
      error('Error al eliminar el mensaje')
    },
  })

  async function deleteMessage(message: SupportMessage) {
    await confirm.confirm({
      title: 'Eliminar mensaje',
      message: `¿Estás seguro de que querés eliminar el mensaje "${message.subject}"? Esta acción no se puede deshacer.`,
      confirmLabel: 'Eliminar',
      danger: true,
      onConfirm: () => mutation.mutateAsync(message.guid),
    })
  }

  return { ...mutation, deleteMessage }
}
