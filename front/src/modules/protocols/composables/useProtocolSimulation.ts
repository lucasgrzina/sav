import { computed, type ComputedRef } from 'vue'
import { useQuery } from '@tanstack/vue-query'
import { adminSimulateProtocolApi } from '../api/protocol.api'

export function useProtocolSimulation(
  guid: ComputedRef<string>,
  baseDate: ComputedRef<string | undefined>,
) {
  return useQuery({
    queryKey: computed(() => ['admin-protocol-simulation', guid.value, baseDate.value]),
    queryFn: () => adminSimulateProtocolApi(guid.value, baseDate.value as string),
    enabled: computed(() => !!baseDate.value),
  })
}
