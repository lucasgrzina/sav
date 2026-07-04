import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import type { Ref } from 'vue'
import { adminGetTechniqueApi } from '../api/technique.api'

export function useTechniqueDetail(guid: Ref<string> | string) {
  const guidValue = computed(() => toValue(guid))

  return useQuery({
    queryKey: ['admin-technique', guidValue],
    queryFn: () => adminGetTechniqueApi(guidValue.value),
    enabled: computed(() => Boolean(guidValue.value)),
  })
}
