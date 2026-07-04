import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import type { Ref } from 'vue'
import { fetchVetByGuid } from '../api/vets.api'

export function useVetProfile(guid: Ref<string> | string) {
  const guidRef = computed(() => toValue(guid))

  return useQuery({
    queryKey: ['vet-profile', guidRef],
    queryFn: () => fetchVetByGuid(guidRef.value),
    enabled: computed(() => Boolean(guidRef.value)),
    staleTime: 1000 * 60 * 2,
    retry: (failureCount, error: unknown) => {
      const e = error as { status?: number }
      if (e?.status === 401 || e?.status === 403 || e?.status === 404) return false
      return failureCount < 2
    },
  })
}
