import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import type { Ref } from 'vue'
import { adminListClientsByVetApi } from '../../api/admin-clients.api'
import type { ClientFilters } from '../../types/client.types'

export function useAdminClientsByVet(
  vetGuid: Ref<string> | string,
  filters: Ref<ClientFilters> | ClientFilters = {},
) {
  const guidValue  = computed(() => toValue(vetGuid))
  const filtersRef = computed(() => toValue(filters))

  return useQuery({
    queryKey: ['admin-vet-clients', guidValue, filtersRef],
    queryFn: ({ signal }) => adminListClientsByVetApi(guidValue.value, filtersRef.value, signal),
    enabled: computed(() => Boolean(guidValue.value)),
    staleTime: 1000 * 30,
  })
}
