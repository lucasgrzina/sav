import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { suspendVetApi } from '../api/vets.api'
import { useNotification } from '@/core/composables/useNotification'
import { useConfirm } from '@/core/composables/useConfirm'
import type { VetItem } from '../types/vet.types'

export function useSuspendVet() {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const confirm = useConfirm()

  const mutation = useMutation({
    mutationFn: (guid: string) => suspendVetApi(guid),
    onSuccess: (_, guid) => {
      queryClient.invalidateQueries({ queryKey: ['vets'] })
      queryClient.invalidateQueries({ queryKey: ['vet', guid] })
      success('Empresa suspendida correctamente')
    },
    onError: () => {
      error('Error al suspender la empresa')
    },
  })

  async function suspendVet(vet: VetItem) {
    await confirm.confirm({
      title: 'Suspender empresa',
      message: `¿Estás seguro de que querés suspender "${vet.name}"? No podrá operar hasta que sea reactivada.`,
      confirmLabel: 'Suspender',
      danger: true,
      onConfirm: async () => { await mutation.mutateAsync(vet.guid) },
    })
  }

  return { ...mutation, suspendVet }
}
