import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import type { MaybeRefOrGetter } from 'vue'
import { listProvincesApi } from '../api/clients.api'

export function useProvinces(countryGuid: MaybeRefOrGetter<string | undefined>) {
  const guid = computed(() => toValue(countryGuid) ?? '')

  return useQuery({
    queryKey: ['provinces', guid],
    queryFn: () => listProvincesApi(guid.value),
    enabled: computed(() => Boolean(guid.value)),
    staleTime: Infinity,
  })
}
