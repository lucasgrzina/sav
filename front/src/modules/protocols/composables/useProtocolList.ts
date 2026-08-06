import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import type { Ref } from 'vue'
import { adminListProtocolsApi } from '../api/protocol.api'
import type { ProtocolListParams } from '../types/protocol.types'

export function useProtocolList(params: Ref<ProtocolListParams> | ProtocolListParams) {
  const paramsRef = computed(() => toValue(params))

  return useQuery({
    queryKey: ['admin-protocols', paramsRef],
    queryFn: ({ signal }) => adminListProtocolsApi(paramsRef.value, signal),
    enabled: computed(() => Boolean(paramsRef.value.root_guid)),
    staleTime: 1000 * 30,
  })
}
