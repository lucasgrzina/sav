import { computed, toValue } from 'vue'
import type { MaybeRef } from 'vue'
import { useQuery } from '@tanstack/vue-query'
import { getMyVetProfileApi } from '../api/my-vet-profile.api'

export function useMyVetProfile(vetGuid: MaybeRef<string>) {
  const vGuid = computed(() => toValue(vetGuid))

  return useQuery({
    queryKey: ['my-vet-profile', vGuid],
    queryFn:  () => getMyVetProfileApi(vGuid.value),
    enabled:  computed(() => Boolean(vGuid.value)),
    staleTime: 1000 * 30,
  })
}
