import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import { adminLookupClientApi } from '../../api/admin-clients.api'

export function useAdminLookupClient(vetGuid: string) {
  const taxId   = ref<string>('')
  const enabled = ref(false)
  const queryClient = useQueryClient()

  const query = useQuery({
    queryKey: ['admin-client-lookup', vetGuid, taxId],
    queryFn:  () => adminLookupClientApi(taxId.value, vetGuid),
    enabled:  computed(() => enabled.value && Boolean(taxId.value)),
    staleTime: 0,
    retry: false,
  })

  function search(newTaxId: string): void {
    taxId.value   = newTaxId
    enabled.value = true
  }

  function reset(): void {
    taxId.value   = ''
    enabled.value = false
    queryClient.removeQueries({ queryKey: ['admin-client-lookup', vetGuid] })
  }

  return { ...query, taxId, search, reset }
}
