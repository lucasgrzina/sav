import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import type { Ref } from 'vue'
import { adminGetProtocolApi } from '../api/protocol.api'

export function useProtocolDetail(guid: Ref<string | null> | string | null) {
  const guidValue = computed(() => toValue(guid))

  return useQuery({
    queryKey: ['admin-protocol', guidValue],
    queryFn: () => adminGetProtocolApi(guidValue.value as string),
    enabled: computed(() => Boolean(guidValue.value)),
  })
}
