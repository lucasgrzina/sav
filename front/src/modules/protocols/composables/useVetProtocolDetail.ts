import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import { useRoute } from 'vue-router'
import type { Ref } from 'vue'
import { getVetProtocolApi } from '../api/vet-protocol.api'

export function useVetProtocolDetail(guid: Ref<string | null> | string | null) {
  const route = useRoute()
  const vetGuid = computed(() => route.params.vetGuid as string)
  const guidRef = computed(() => toValue(guid))

  return useQuery({
    queryKey: ['vet-protocol', vetGuid, guidRef],
    queryFn: () => getVetProtocolApi(vetGuid.value, guidRef.value as string),
    enabled: computed(() => Boolean(vetGuid.value) && Boolean(guidRef.value)),
  })
}
