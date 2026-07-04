import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import type { Ref } from 'vue'
import { adminGetClientApi } from '../../api/admin-clients.api'

export function useAdminClient(guid: Ref<string> | string) {
  const guidValue = computed(() => toValue(guid))

  return useQuery({
    queryKey: ['admin-client', guidValue],
    queryFn: () => adminGetClientApi(guidValue.value),
    enabled: computed(() => Boolean(guidValue.value)),
  })
}
