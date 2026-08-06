import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import { useRoute } from 'vue-router'
import type { Ref } from 'vue'
import { listVetProtocolsApi } from '../api/vet-protocol.api'
import type { VetProtocolListParams } from '../types/vet-protocol.types'

export function useVetProtocolList(params: Ref<VetProtocolListParams> | VetProtocolListParams) {
  const route = useRoute()
  const vetGuid = computed(() => route.params.vetGuid as string)
  const paramsRef = computed(() => toValue(params))

  return useQuery({
    queryKey: ['vet-protocols', vetGuid, paramsRef],
    queryFn: ({ signal }) => listVetProtocolsApi(vetGuid.value, paramsRef.value, signal),
    enabled: computed(() => Boolean(vetGuid.value)),
    staleTime: 1000 * 30,
  })
}
