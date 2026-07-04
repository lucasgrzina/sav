import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import type { Ref } from 'vue'
import { listHealthPlanCategoriesApi } from '../api/health-plan-categories.api'
import type { HealthPlanCategoryListParams } from '../types/health.types'

export function useHealthPlanCategories(
  params: Ref<HealthPlanCategoryListParams> | HealthPlanCategoryListParams = {}
) {
  const paramsRef = computed(() => toValue(params))
  return useQuery({
    queryKey: ['admin-health-plan-categories', paramsRef],
    queryFn: ({ signal }) => listHealthPlanCategoriesApi(paramsRef.value, signal),
    staleTime: 1000 * 30,
  })
}
