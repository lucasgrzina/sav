import { useQuery } from '@tanstack/vue-query'
import { listCountriesApi } from '../api/vets.api'

export function useCountries() {
  return useQuery({
    queryKey: ['countries'],
    queryFn: listCountriesApi,
    staleTime: Infinity,
  })
}
