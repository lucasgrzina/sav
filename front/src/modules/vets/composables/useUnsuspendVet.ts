import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { unsuspendVetApi } from '../api/vets.api'
import { useNotification } from '@/core/composables/useNotification'
import { useConfirm } from '@/core/composables/useConfirm'
import type { VetItem } from '../types/vet.types'

export function useUnsuspendVet() {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const confirm = useConfirm()

  const mutation = useMutation({
    mutationFn: (guid: string) => unsuspendVetApi(guid),
    onSuccess: (_, guid) => {
      queryClient.invalidateQueries({ queryKey: ['vets'] })
      queryClient.invalidateQueries({ queryKey: ['vet', guid] })
      success('Veterinaria reactivada correctamente')
    },
    onError: () => {
      error('Error al reactivar la veterinaria')
    },
  })

  async function unsuspendVet(vet: VetItem) {
    await confirm.confirm({
      title: 'Reactivar veterinaria',
      message: `¿Confirmás la reactivación de "${vet.name}"?`,
      confirmLabel: 'Reactivar',
      danger: false,
      onConfirm: () => mutation.mutateAsync(vet.guid),
    })
  }

  return { ...mutation, unsuspendVet }
}
