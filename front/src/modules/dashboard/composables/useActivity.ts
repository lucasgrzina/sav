import { useQuery } from '@tanstack/vue-query'
import { getActivityApi } from '../api/dashboard.api'

export function useActivity() {
    return useQuery({
        queryKey: ['dashboard', 'activity'],
        queryFn:  getActivityApi,
        staleTime: 1000 * 60 * 2, // 2 minutos
    })
}
