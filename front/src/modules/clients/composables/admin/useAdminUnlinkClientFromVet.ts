import { ref } from 'vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { adminUnlinkVetFromClientApi } from '../../api/admin-clients.api'
import { useNotification } from '@/core/composables/useNotification'
import { useConfirm } from '@/core/composables/useConfirm'
import { parseApiError } from '@/core/composables/parseApiError'
import type { ClientItem } from '../../types/client.types'

export function useAdminUnlinkClientFromVet(vetGuid: string) {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const confirm      = useConfirm()
  const generalError = ref<string | null>(null)

  // El endpoint es DELETE /v1/admin/clients/{clientGuid}/vets/{vetGuid}
  // Mismo endpoint que useAdminUnlinkVet, roles invertidos en la UI
  const mutation = useMutation({
    mutationFn: (clientGuid: string) => adminUnlinkVetFromClientApi(clientGuid, vetGuid),
    onMutate: () => {
      generalError.value = null
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-vet-clients', vetGuid] })
      success('Cliente desvinculado correctamente')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      generalError.value = apiError.message ?? 'Error al desvincular el cliente.'
      error(generalError.value)
    },
  })

  async function unlinkClient(client: ClientItem): Promise<void> {
    await confirm.confirm({
      title:        'Desvincular cliente',
      message:      `¿Estás seguro de que querés desvincular a "${client.name}" de esta veterinaria?`,
      confirmLabel: 'Desvincular',
      danger:       true,
      onConfirm:    () => mutation.mutateAsync(client.guid),
    })
  }

  return { ...mutation, generalError, unlinkClient }
}
