import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import { useRoute } from 'vue-router'
import type { MaybeRefOrGetter } from 'vue'
import { listClientManagerOptionsApi } from '../api/program.api'

// Prefix shared with the client-staff mutations that must invalidate these options.
export const PROGRAM_MANAGER_OPTIONS_KEY = 'program-manager-options'

// Client staff linked (and not blocked) to an establishment, reduced to {guid, name, role}.
// Only fetched once an establishment is chosen.
export function useClientManagerOptions(
  clientGuid: MaybeRefOrGetter<string>,
  establishmentGuid: MaybeRefOrGetter<string>,
) {
  const route = useRoute()
  const vetGuid = computed(() => route.params.vetGuid as string)
  const cGuid = computed(() => toValue(clientGuid))
  const eGuid = computed(() => toValue(establishmentGuid))

  return useQuery({
    queryKey: [PROGRAM_MANAGER_OPTIONS_KEY, vetGuid, cGuid, eGuid],
    queryFn: () => listClientManagerOptionsApi(vetGuid.value, cGuid.value, eGuid.value),
    enabled: computed(() => Boolean(vetGuid.value) && Boolean(cGuid.value) && Boolean(eGuid.value)),
  })
}
