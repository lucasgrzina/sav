import { useQuery } from '@tanstack/vue-query'
import { useVetStore } from '@/core/stores/vet.store'
import { fetchUserVets } from '../api/user-vets.api'

export function useUserVets() {
  const vetStore = useVetStore()

  return useQuery({
    queryKey: ['user-vets'],
    queryFn: async () => {
      const vets = await fetchUserVets()
      vetStore.setUserVets(vets)
      return vets
    },
    staleTime: 1000 * 60 * 5,
  })
}
