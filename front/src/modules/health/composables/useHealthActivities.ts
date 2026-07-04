import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import type { Ref } from 'vue'
import { listHealthActivitiesApi } from '../api/health-activities.api'
import type { HealthActivityListParams } from '../types/health.types'

export function useHealthActivities(params: Ref<HealthActivityListParams> | HealthActivityListParams = {}) {
  const paramsRef = computed(() => toValue(params))
  return useQuery({
    queryKey: ['admin-health-activities', paramsRef],
    queryFn: ({ signal }) => listHealthActivitiesApi(paramsRef.value, signal),
    staleTime: 1000 * 30,
  })
}
