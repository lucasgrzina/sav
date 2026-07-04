import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import type { Ref } from 'vue'
import { adminListTechniquesApi } from '../api/technique.api'
import type { TechniqueListParams } from '../types/technique.types'

export function useTechniqueList(
  params: Ref<TechniqueListParams> | TechniqueListParams = {},
) {
  const paramsRef = computed(() => toValue(params))

  return useQuery({
    queryKey: ['admin-techniques', paramsRef],
    queryFn: ({ signal }) => adminListTechniquesApi(paramsRef.value, signal),
    staleTime: 1000 * 30,
  })
}
