import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import type { Ref } from 'vue'
import { getVetApi } from '../api/vets.api'

export function useVet(guid: Ref<string> | string) {
  const guidValue = computed(() => toValue(guid))

  return useQuery({
    queryKey: ['vet', guidValue],
    queryFn: () => getVetApi(guidValue.value),
    enabled: computed(() => Boolean(guidValue.value)),
  })
}
