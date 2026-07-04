import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import { useRoute } from 'vue-router'
import type { Ref } from 'vue'
import { getClientApi } from '../api/clients.api'

export function useClient(clientGuid: Ref<string> | string) {
  const route   = useRoute()
  const vetGuid = computed(() => route.params.vetGuid as string)
  const guid    = computed(() => toValue(clientGuid))

  return useQuery({
    queryKey: ['client', vetGuid, guid],
    queryFn:  () => getClientApi(vetGuid.value, guid.value),
    enabled:  computed(() => Boolean(vetGuid.value) && Boolean(guid.value)),
  })
}
