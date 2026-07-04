import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import type { MaybeRef } from 'vue'
import { listClientStaffApi } from '../api/client-staff.api'

export function useClientStaff(vetGuid: MaybeRef<string>, clientGuid: MaybeRef<string>) {
  const vGuid = computed(() => toValue(vetGuid))
  const cGuid = computed(() => toValue(clientGuid))

  return useQuery({
    queryKey: ['client-staff', vGuid, cGuid],
    queryFn: () => listClientStaffApi(vGuid.value, cGuid.value),
    enabled: computed(() => Boolean(vGuid.value) && Boolean(cGuid.value)),
    staleTime: 1000 * 30,
  })
}
