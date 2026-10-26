import { computed, type MaybeRefOrGetter, toValue } from 'vue'
import { useQuery } from '@tanstack/vue-query'
import { usePermission } from '@/core/composables/usePermissions'
import { getVetHomeApi } from '../api/home.api'

export const VET_HOME_KEY = 'vet-home'
export const VET_HOME_STALE_MS = 60 * 1000

export function useVetHome(vetGuid: MaybeRefOrGetter<string>) {
  const { can } = usePermission()

  return useQuery({
    queryKey: computed(() => [VET_HOME_KEY, toValue(vetGuid)]),
    queryFn: ({ signal }) => getVetHomeApi(toValue(vetGuid), signal),
    staleTime: VET_HOME_STALE_MS,
    enabled: computed(() => Boolean(toValue(vetGuid)) && can('programs.read')),
  })
}
