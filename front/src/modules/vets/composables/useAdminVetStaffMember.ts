import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import type { MaybeRef } from 'vue'
import { adminGetVetStaffMemberApi } from '../api/vet-staff.api'

export function useAdminVetStaffMember(
  vetGuid: MaybeRef<string>,
  profileGuid: MaybeRef<string>,
  enabled?: MaybeRef<boolean>,
) {
  const vGuid    = computed(() => toValue(vetGuid))
  const pGuid    = computed(() => toValue(profileGuid))
  const enabled_ = computed(() => toValue(enabled) !== false && Boolean(vGuid.value) && Boolean(pGuid.value))

  return useQuery({
    queryKey: ['admin-vet-staff-member', vGuid, pGuid],
    queryFn:  () => adminGetVetStaffMemberApi(vGuid.value, pGuid.value),
    enabled:  enabled_,
    staleTime: 1000 * 30,
  })
}
