import { computed, type MaybeRefOrGetter, toValue } from 'vue'
import { useQuery } from '@tanstack/vue-query'
import { getAdminDashboardApi } from '../api/dashboard.api'

export const ADMIN_DASHBOARD_KEY = ['dashboard', 'admin'] as const
export const ADMIN_DASHBOARD_STALE_MS = 5 * 60 * 1000

export function useAdminDashboard(enabled: MaybeRefOrGetter<boolean> = true) {
  return useQuery({
    queryKey: ADMIN_DASHBOARD_KEY,
    queryFn: getAdminDashboardApi,
    staleTime: ADMIN_DASHBOARD_STALE_MS,
    enabled: computed(() => toValue(enabled)),
  })
}
