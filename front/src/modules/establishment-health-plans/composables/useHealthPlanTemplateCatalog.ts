import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import { useRoute } from 'vue-router'
import type { Ref } from 'vue'
import { listHealthPlanTemplatesCatalogApi, getHealthPlanTemplateCatalogApi } from '../api/health-plan-templates-catalog.api'
import type { HealthPlanTemplateListParams } from '@/modules/health/types/health.types'

export function useHealthPlanTemplateCatalog(
  params: Ref<HealthPlanTemplateListParams> | HealthPlanTemplateListParams = {},
) {
  const route = useRoute()
  const vetGuid = computed(() => route.params.vetGuid as string)
  const paramsRef = computed(() => toValue(params))

  return useQuery({
    queryKey: ['health-plan-templates-catalog', vetGuid, paramsRef],
    queryFn: ({ signal }) => listHealthPlanTemplatesCatalogApi(vetGuid.value, paramsRef.value, signal),
    enabled: computed(() => Boolean(vetGuid.value)),
    staleTime: 1000 * 60,
  })
}

export function useHealthPlanTemplateCatalogDetail(guid: Ref<string | null> | string | null) {
  const route = useRoute()
  const vetGuid = computed(() => route.params.vetGuid as string)
  const guidRef = computed(() => toValue(guid))

  return useQuery({
    queryKey: ['health-plan-template-catalog', vetGuid, guidRef],
    queryFn: () => getHealthPlanTemplateCatalogApi(vetGuid.value, guidRef.value as string),
    enabled: computed(() => Boolean(vetGuid.value) && Boolean(guidRef.value)),
    staleTime: 1000 * 60,
  })
}
