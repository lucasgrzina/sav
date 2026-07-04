import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { unlinkClientApi } from '../api/clients.api'
import { useNotification } from '@/core/composables/useNotification'
import { useConfirm } from '@/core/composables/useConfirm'
import type { ClientItem } from '../types/client.types'

export function useUnlinkClient() {
  const queryClient  = useQueryClient()
  const { success, error } = useNotification()
  const confirm      = useConfirm()
  const route        = useRoute()
  const vetGuid      = computed(() => route.params.vetGuid as string)

  const mutation = useMutation({
    mutationFn: (guid: string) => unlinkClientApi(vetGuid.value, guid),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['clients', vetGuid.value] })
      success('Cliente desvinculado correctamente')
    },
    onError: () => error('Error al desvincular el cliente'),
  })

  async function unlinkClient(client: ClientItem): Promise<void> {
    await confirm.confirm({
      title:        'Desvincular cliente',
      message:      `¿Estás seguro de que querés desvincular a "${client.name}" de esta veterinaria? El cliente seguirá existiendo en el sistema.`,
      confirmLabel: 'Desvincular',
      danger:       true,
      onConfirm:    () => mutation.mutateAsync(client.guid),
    })
  }

  return { ...mutation, unlinkClient }
}
