import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import type { Ref } from 'vue'
import { adminListVetStaffApi } from '../api/vet-staff.api'

export function useAdminVetStaff(vetGuid: Ref<string> | string) {
  const guidValue = computed(() => toValue(vetGuid))

  return useQuery({
    queryKey: ['admin-vet-staff', guidValue],
    queryFn: () => adminListVetStaffApi(guidValue.value),
    enabled: computed(() => Boolean(guidValue.value)),
    staleTime: 1000 * 30,
  })
}
