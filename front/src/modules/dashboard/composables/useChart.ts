import { useQuery } from '@tanstack/vue-query'
import { getChartApi } from '../api/dashboard.api'

export function useChart() {
    return useQuery({
        queryKey: ['dashboard', 'chart'],
        queryFn:  getChartApi,
        staleTime: 1000 * 60 * 2, // 2 minutos
    })
}
