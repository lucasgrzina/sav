import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import type { MaybeRef } from 'vue'
import { adminListEstablishmentsApi } from '../../api/clients.api'

export function useAdminClientEstablishments(clientGuid: MaybeRef<string>) {
  const cGuid = computed(() => toValue(clientGuid))

  return useQuery({
    queryKey: ['admin-client-establishments', cGuid],
    queryFn:  () => adminListEstablishmentsApi(cGuid.value),
    enabled:  computed(() => Boolean(cGuid.value)),
    staleTime: 1000 * 60,
  })
}
