import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import type { Ref } from 'vue'
import { listSupportMessagesApi } from '../api/support-messages.api'
import type { SupportMessageListParams } from '../types/support-message.types'

export function useSupportMessages(params: Ref<SupportMessageListParams> | SupportMessageListParams = {}) {
  const paramsRef = computed(() => toValue(params))

  return useQuery({
    queryKey: ['support-messages', paramsRef],
    queryFn: ({ signal }) => listSupportMessagesApi(paramsRef.value, signal),
    staleTime: 1000 * 30,
  })
}
