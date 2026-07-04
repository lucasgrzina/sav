import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { deleteContactApi } from '../api/clients.api'
import { useNotification } from '@/core/composables/useNotification'
import { useConfirm } from '@/core/composables/useConfirm'
import type { ContactItem } from '../types/client.types'

export function useDeleteContact(clientGuid: string) {
  const queryClient  = useQueryClient()
  const { success, error } = useNotification()
  const confirm      = useConfirm()
  const route        = useRoute()
  const vetGuid      = computed(() => route.params.vetGuid as string)

  const mutation = useMutation({
    mutationFn: (contactGuid: string) => deleteContactApi(vetGuid.value, clientGuid, contactGuid),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['client-contacts', vetGuid.value, clientGuid] })
      queryClient.invalidateQueries({ queryKey: ['client', vetGuid.value, clientGuid] })
      success('Contacto eliminado correctamente')
    },
    onError: () => error('Error al eliminar el contacto'),
  })

  async function deleteContact(contact: ContactItem): Promise<void> {
    await confirm.confirm({
      title:        'Eliminar contacto',
      message:      `¿Estás seguro de que querés eliminar el contacto "${contact.value}"?`,
      confirmLabel: 'Eliminar',
      danger:       true,
      onConfirm:    () => mutation.mutateAsync(contact.guid),
    })
  }

  return { ...mutation, deleteContact }
}
