import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import type { Ref } from 'vue'
import { listVetsApi } from '../api/vets.api'
import type { VetFilters, VetListParams } from '../types/vet.types'

function toListParams(filters: VetFilters): VetListParams {
  return {
    ...filters,
    validated: filters.validated === '' ? undefined : filters.validated,
    suspended: filters.suspended === '' ? undefined : filters.suspended,
  }
}

export function useVets(filters: Ref<VetFilters> | VetFilters = {}) {
  const filtersRef = computed(() => toValue(filters))

  return useQuery({
    queryKey: ['vets', filtersRef],
    queryFn: ({ signal }) => listVetsApi(toListParams(filtersRef.value), signal),
    staleTime: 1000 * 30,
  })
}
