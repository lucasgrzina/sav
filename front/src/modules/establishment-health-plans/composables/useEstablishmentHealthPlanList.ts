import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import { useRoute } from 'vue-router'
import type { Ref } from 'vue'
import { listEstablishmentHealthPlansApi } from '../api/establishment-health-plans.api'
import type { EstablishmentHealthPlanListParams } from '../types/establishment-health-plan.types'

export function useEstablishmentHealthPlanList(
  params: Ref<EstablishmentHealthPlanListParams> | EstablishmentHealthPlanListParams,
) {
  const route = useRoute()
  const vetGuid = computed(() => route.params.vetGuid as string)
  const paramsRef = computed(() => toValue(params))

  return useQuery({
    queryKey: ['establishment-health-plans', vetGuid, paramsRef],
    queryFn: ({ signal }) => listEstablishmentHealthPlansApi(vetGuid.value, paramsRef.value, signal),
    enabled: computed(() => Boolean(vetGuid.value)),
    staleTime: 1000 * 30,
  })
}
