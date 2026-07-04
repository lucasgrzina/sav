import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import { useRoute } from 'vue-router'
import type { Ref } from 'vue'
import { listEstablishmentsApi } from '../api/clients.api'

export function useClientEstablishments(clientGuid: Ref<string> | string) {
  const route   = useRoute()
  const vetGuid = computed(() => route.params.vetGuid as string)
  const guid    = computed(() => toValue(clientGuid))

  return useQuery({
    queryKey: ['client-establishments', vetGuid, guid],
    queryFn:  () => listEstablishmentsApi(vetGuid.value, guid.value),
    enabled:  computed(() => Boolean(vetGuid.value) && Boolean(guid.value)),
    staleTime: 1000 * 60,
  })
}
