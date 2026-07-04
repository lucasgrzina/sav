import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import type { Ref } from 'vue'
import { listDocumentTypesApi } from '../api/vets.api'

export function useDocumentTypes(countryGuid: Ref<string> | string) {
  const guidValue = computed(() => toValue(countryGuid))

  return useQuery({
    queryKey: ['document-types', guidValue],
    queryFn: () => listDocumentTypesApi(guidValue.value),
    enabled: computed(() => Boolean(guidValue.value)),
    staleTime: Infinity,
  })
}
