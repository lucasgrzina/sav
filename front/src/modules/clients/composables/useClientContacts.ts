import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import { useRoute } from 'vue-router'
import type { Ref } from 'vue'
import { listContactsApi } from '../api/clients.api'

export function useClientContacts(clientGuid: Ref<string> | string) {
  const route   = useRoute()
  const vetGuid = computed(() => route.params.vetGuid as string)
  const guid    = computed(() => toValue(clientGuid))

  return useQuery({
    queryKey: ['client-contacts', vetGuid, guid],
    queryFn:  () => listContactsApi(vetGuid.value, guid.value),
    enabled:  computed(() => Boolean(vetGuid.value) && Boolean(guid.value)),
    staleTime: 1000 * 60,
  })
}
