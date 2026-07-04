import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import type { MaybeRef } from 'vue'
import { getClientStaffMemberApi } from '../api/client-staff.api'

export function useClientStaffMember(
  vetGuid: MaybeRef<string>,
  clientGuid: MaybeRef<string>,
  profileGuid: MaybeRef<string>,
  enabled?: MaybeRef<boolean>,
) {
  const vGuid    = computed(() => toValue(vetGuid))
  const cGuid    = computed(() => toValue(clientGuid))
  const pGuid    = computed(() => toValue(profileGuid))
  const enabled_ = computed(() => toValue(enabled) !== false && Boolean(vGuid.value) && Boolean(cGuid.value) && Boolean(pGuid.value))

  return useQuery({
    queryKey: ['client-staff-member', vGuid, cGuid, pGuid],
    queryFn:  () => getClientStaffMemberApi(vGuid.value, cGuid.value, pGuid.value),
    enabled:  enabled_,
    staleTime: 1000 * 30,
  })
}
