import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import type { Ref } from 'vue'
import { getUserApi } from '@/modules/users/api/users.api'

export function useUser(guid: Ref<string> | string) {
  const guidValue = computed(() => toValue(guid))

  return useQuery({
    queryKey: ['user', guidValue],
    queryFn: () => getUserApi(guidValue.value),
    enabled: computed(() => Boolean(guidValue.value)),
  })
}
