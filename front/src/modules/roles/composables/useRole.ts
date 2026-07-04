import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import type { Ref } from 'vue'
import { getRoleApi } from '../api/roles.api'

export function useRole(guid: Ref<string> | string) {
  const guidValue = computed(() => toValue(guid))

  return useQuery({
    queryKey: ['role', guidValue],
    queryFn: () => getRoleApi(guidValue.value),
    enabled: computed(() => Boolean(guidValue.value)),
  })
}
