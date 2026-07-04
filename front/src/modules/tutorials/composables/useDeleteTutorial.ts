import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { deleteTutorialApi } from '../api/tutorials.api'
import { useNotification } from '@/core/composables/useNotification'
import { useConfirm } from '@/core/composables/useConfirm'
import type { Tutorial } from '../types/tutorial.types'

export function useDeleteTutorial() {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const confirm = useConfirm()

  const mutation = useMutation({
    mutationFn: (guid: string) => deleteTutorialApi(guid),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['tutorials'] })
      success('Tutorial eliminado correctamente')
    },
    onError: () => {
      error('Error al eliminar el tutorial')
    },
  })

  async function deleteTutorial(tutorial: Tutorial) {
    await confirm.confirm({
      title: 'Eliminar tutorial',
      message: `¿Estás seguro de que querés eliminar el tutorial "${tutorial.title}"? Esta acción no se puede deshacer.`,
      confirmLabel: 'Eliminar',
      danger: true,
      onConfirm: () => mutation.mutateAsync(tutorial.guid),
    })
  }

  return { ...mutation, deleteTutorial }
}
