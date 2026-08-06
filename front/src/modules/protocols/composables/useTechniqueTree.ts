import { useQuery } from '@tanstack/vue-query'
import { listTechniquesApi } from '@/modules/techniques/api/technique.api'

export function useTechniqueTree() {
  return useQuery({
    queryKey: ['techniques-tree'],
    queryFn: () => listTechniquesApi(),
    staleTime: 1000 * 60 * 5,
  })
}
