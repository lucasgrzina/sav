import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import type { Ref } from 'vue'
import { listHealthPlanTemplatesApi, getHealthPlanTemplateApi } from '../api/health-plan-templates.api'
import type { HealthPlanTemplateListParams } from '../types/health.types'

export function useHealthPlanTemplates(
  params: Ref<HealthPlanTemplateListParams> | HealthPlanTemplateListParams = {}
) {
  const paramsRef = computed(() => toValue(params))
  return useQuery({
    queryKey: ['admin-health-plan-templates', paramsRef],
    queryFn: ({ signal }) => listHealthPlanTemplatesApi(paramsRef.value, signal),
    staleTime: 1000 * 30,
  })
}

export function useHealthPlanTemplate(guid: Ref<string | null>) {
  const guidValue = computed(() => toValue(guid))
  return useQuery({
    queryKey: ['admin-health-plan-template', guidValue],
    queryFn: () => getHealthPlanTemplateApi(guidValue.value!),
    enabled: computed(() => Boolean(guidValue.value)),
    staleTime: 1000 * 30,
  })
}
