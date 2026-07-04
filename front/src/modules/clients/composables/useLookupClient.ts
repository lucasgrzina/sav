import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import { useRoute } from 'vue-router'
import { lookupClientApi } from '../api/clients.api'

export function useLookupClient() {
  const route   = useRoute()
  const vetGuid = computed(() => route.params.vetGuid as string)
  const taxId   = ref<string>('')
  const enabled = ref(false)
  const queryClient = useQueryClient()

  const query = useQuery({
    queryKey: ['client-lookup', vetGuid, taxId],
    queryFn:  () => lookupClientApi(vetGuid.value, taxId.value),
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
    queryClient.removeQueries({ queryKey: ['client-lookup', vetGuid.value] })
  }

  return { ...query, taxId, search, reset }
}
