import type { NavigationGuard } from 'vue-router'
import { useVetStore } from '@/core/stores/vet.store'
import { useNotification } from '@/core/composables/useNotification'
import { fetchUserVets } from '@/modules/vets/api/user-vets.api'
import { queryClient } from '@/core/plugins/vue-query'

export const vetTenantGuard: NavigationGuard = async (to) => {
  const vetStore = useVetStore()
  const { error: notifyError } = useNotification()

  const guid = to.params.vetGuid as string

  if (!guid) {
    return { path: '/dashboard', replace: true }
  }

  if (vetStore.userVets.length === 0) {
    try {
      const vets = await queryClient.fetchQuery({
        queryKey: ['user-vets'],
        queryFn: fetchUserVets,
        staleTime: 1000 * 60 * 5,
      })
      vetStore.setUserVets(vets)
    } catch {
      notifyError('No se pudo verificar el acceso a la veterinaria.')
      return { path: '/dashboard', replace: true }
    }
  }

  const matchingVet = vetStore.userVets.find(v => v.guid === guid)

  if (!matchingVet) {
    notifyError('No tenés acceso a esta veterinaria o no existe.')
    return { path: '/dashboard', replace: true }
  }

  return true
}
