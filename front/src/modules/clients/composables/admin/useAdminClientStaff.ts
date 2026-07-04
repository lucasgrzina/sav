import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import type { MaybeRef } from 'vue'
import { adminListClientStaffApi } from '../../api/client-staff.api'

export function useAdminClientStaff(clientGuid: MaybeRef<string>) {
  const cGuid = computed(() => toValue(clientGuid))

  return useQuery({
    queryKey: ['admin-client-staff', cGuid],
    queryFn: () => adminListClientStaffApi(cGuid.value),
    enabled: computed(() => Boolean(cGuid.value)),
    staleTime: 1000 * 30,
  })
}
