import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { deleteEstablishmentApi } from '../api/clients.api'
import { useNotification } from '@/core/composables/useNotification'
import { useConfirm } from '@/core/composables/useConfirm'
import type { EstablishmentItem } from '../types/client.types'

export function useDeleteEstablishment(clientGuid: string) {
  const queryClient  = useQueryClient()
  const { success, error } = useNotification()
  const confirm      = useConfirm()
  const route        = useRoute()
  const vetGuid      = computed(() => route.params.vetGuid as string)

  const mutation = useMutation({
    mutationFn: (estGuid: string) => deleteEstablishmentApi(vetGuid.value, clientGuid, estGuid),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['client-establishments', vetGuid.value, clientGuid] })
      queryClient.invalidateQueries({ queryKey: ['client', vetGuid.value, clientGuid] })
      success('Establecimiento eliminado correctamente')
    },
    onError: () => error('Error al eliminar el establecimiento'),
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
