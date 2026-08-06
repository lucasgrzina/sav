import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import { useRoute } from 'vue-router'
import type { Ref } from 'vue'
import { listProgramsApi } from '../api/program.api'
import type { ProgramListParams } from '../types/program.types'

export function useProgramList(params: Ref<ProgramListParams> | ProgramListParams) {
  const route = useRoute()
  const vetGuid = computed(() => route.params.vetGuid as string)
  const paramsRef = computed(() => toValue(params))

  return useQuery({
    queryKey: ['programs', vetGuid, paramsRef],
    queryFn: ({ signal }) => listProgramsApi(vetGuid.value, paramsRef.value, signal),
    enabled: computed(() => Boolean(vetGuid.value)),
    staleTime: 1000 * 30,
  })
}
