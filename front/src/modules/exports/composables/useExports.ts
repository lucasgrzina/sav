import { useQuery } from '@tanstack/vue-query'
import { listExportsApi } from '../api/exports.api'

export function useExports(params?: { per_page?: number }) {
  return useQuery({
    queryKey: ['exports', params],
    queryFn:  () => listExportsApi(params),
    refetchInterval: (query) => {
      // Si hay alguna exportación en proceso, hacer polling cada 10 segundos
      const data = query.state.data
      const hasProcessing = data?.data?.some(
        e => e.status === 'pending' || e.status === 'processing'
      )
      return hasProcessing ? 10_000 : false
    },
  })
}
