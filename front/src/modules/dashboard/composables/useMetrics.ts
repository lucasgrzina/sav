import { useQuery } from '@tanstack/vue-query'
import { getMetricsApi } from '../api/dashboard.api'

export function useMetrics() {
    return useQuery({
        queryKey: ['dashboard', 'metrics'],
        queryFn:  getMetricsApi,
        staleTime: 1000 * 60 * 2, // 2 minutos
    })
}
