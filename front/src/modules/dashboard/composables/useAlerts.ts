import { useQuery } from '@tanstack/vue-query'
import { getAlertsApi } from '../api/dashboard.api'

export function useAlerts() {
    return useQuery({
        queryKey: ['dashboard', 'alerts'],
        queryFn:  getAlertsApi,
        staleTime: 1000 * 60 * 2, // 2 minutos
    })
}
