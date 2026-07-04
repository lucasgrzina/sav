import { ref } from 'vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { adminUnlinkVetFromClientApi } from '../../api/admin-clients.api'
import { useNotification } from '@/core/composables/useNotification'
import { useConfirm } from '@/core/composables/useConfirm'
import { parseApiError } from '@/core/composables/parseApiError'
import type { VetItem } from '@/modules/vets/types/vet.types'

export function useAdminUnlinkVet(clientGuid: string) {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const confirm      = useConfirm()
  const generalError = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: (vetGuid: string) => adminUnlinkVetFromClientApi(clientGuid, vetGuid),
    onMutate: () => {
      generalError.value = null
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-client', clientGuid] })
      success('Veterinaria desvinculada correctamente')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      generalError.value = apiError.message ?? 'Error al desvincular la veterinaria.'
      error(generalError.value)
    },
  })

  async function unlinkVet(vet: VetItem): Promise<void> {
    await confirm.confirm({
      title:        'Desvincular veterinaria',
      message:      `¿Estás seguro de que querés desvincular a "${vet.name}" de este cliente?`,
      confirmLabel: 'Desvincular',
      danger:       true,
      onConfirm:    () => mutation.mutateAsync(vet.guid),
    })
  }

  return { ...mutation, generalError, unlinkVet }
}
