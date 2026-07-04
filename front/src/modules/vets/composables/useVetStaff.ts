import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import type { MaybeRef } from 'vue'
import { listVetStaffApi } from '../api/vet-staff.api'

export function useVetStaff(vetGuid: MaybeRef<string>) {
  const guid = computed(() => toValue(vetGuid))

  return useQuery({
    queryKey: ['vet-staff', guid],
    queryFn: () => listVetStaffApi(guid.value),
    enabled: computed(() => Boolean(guid.value)),
    staleTime: 1000 * 30,
  })
}
