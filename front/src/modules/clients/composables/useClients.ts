import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import { useRoute } from 'vue-router'
import type { Ref } from 'vue'
import { listClientsApi } from '../api/clients.api'
import type { ClientFilters } from '../types/client.types'

export function useClients(filters: Ref<ClientFilters> | ClientFilters = {}) {
  const route      = useRoute()
  const vetGuid    = computed(() => route.params.vetGuid as string)
  const filtersRef = computed(() => toValue(filters))

  return useQuery({
    queryKey: ['clients', vetGuid, filtersRef],
    queryFn:  ({ signal }) => listClientsApi(vetGuid.value, filtersRef.value, signal),
    enabled:  computed(() => Boolean(vetGuid.value)),
    staleTime: 1000 * 30,
  })
}
