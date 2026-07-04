import { useQuery } from '@tanstack/vue-query'
import { listTutorialsApi } from '../api/tutorials.api'

export function useTutorials() {
  return useQuery({
    queryKey: ['tutorials'],
    queryFn: ({ signal }) => listTutorialsApi(signal),
    staleTime: 1000 * 30,
  })
}
