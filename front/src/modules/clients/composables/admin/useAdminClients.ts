import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import type { Ref } from 'vue'
import { adminListClientsApi } from '../../api/admin-clients.api'
import type { ClientFilters } from '../../types/client.types'

export function useAdminClients(filters: Ref<ClientFilters> | ClientFilters = {}) {
  const filtersRef = computed(() => toValue(filters))

  return useQuery({
    queryKey: ['admin-clients', filtersRef],
    queryFn: ({ signal }) => adminListClientsApi(filtersRef.value, signal),
    staleTime: 1000 * 30,
  })
}
