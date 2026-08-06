import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import { useRoute } from 'vue-router'
import type { Ref } from 'vue'
import { getProgramApi } from '../api/program.api'

export function useProgramDetail(guid: Ref<string | null> | string | null) {
  const route = useRoute()
  const vetGuid = computed(() => route.params.vetGuid as string)
  const guidRef = computed(() => toValue(guid))

  return useQuery({
    queryKey: ['program', vetGuid, guidRef],
    queryFn: () => getProgramApi(vetGuid.value, guidRef.value as string),
    enabled: computed(() => Boolean(vetGuid.value) && Boolean(guidRef.value)),
  })
}
