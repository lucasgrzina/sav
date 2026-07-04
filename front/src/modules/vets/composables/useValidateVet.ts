import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { validateVetApi } from '../api/vets.api'
import { useNotification } from '@/core/composables/useNotification'
import { useConfirm } from '@/core/composables/useConfirm'
import type { VetItem } from '../types/vet.types'

export function useValidateVet() {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const confirm = useConfirm()

  const mutation = useMutation({
    mutationFn: (guid: string) => validateVetApi(guid),
    onSuccess: (_, guid) => {
      queryClient.invalidateQueries({ queryKey: ['vets'] })
      queryClient.invalidateQueries({ queryKey: ['vet', guid] })
      success('Veterinaria validada correctamente')
    },
    onError: () => {
      error('Error al validar la veterinaria')
    },
  })

  async function validateVet(vet: VetItem) {
    await confirm.confirm({
      title: 'Validar veterinaria',
      message: `¿Confirmás la validación de "${vet.name}"? Esto le permitirá operar en el sistema.`,
      confirmLabel: 'Validar',
      danger: false,
      onConfirm: () => mutation.mutateAsync(vet.guid),
    })
  }

  return { ...mutation, validateVet }
}
