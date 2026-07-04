import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import type { MaybeRef } from 'vue'
import { adminGetClientStaffMemberApi } from '../../api/client-staff.api'

export function useAdminClientStaffMember(
  clientGuid: MaybeRef<string>,
  profileGuid: MaybeRef<string>,
  enabled?: MaybeRef<boolean>,
) {
  const cGuid    = computed(() => toValue(clientGuid))
  const pGuid    = computed(() => toValue(profileGuid))
  const enabled_ = computed(() => toValue(enabled) !== false && Boolean(cGuid.value) && Boolean(pGuid.value))

  return useQuery({
    queryKey: ['admin-client-staff-member', cGuid, pGuid],
    queryFn:  () => adminGetClientStaffMemberApi(cGuid.value, pGuid.value),
    enabled:  enabled_,
    staleTime: 1000 * 30,
  })
}
