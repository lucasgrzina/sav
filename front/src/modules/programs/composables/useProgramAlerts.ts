import { useQuery, keepPreviousData } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import type { MaybeRefOrGetter } from 'vue'
import { usePermission } from '@/core/composables/usePermissions'
import { listProgramAlertsApi } from '../api/program-alerts.api'
import type { ProgramAlertsFilters, ProgramAlertsParams } from '../types/program-alerts.types'

export const PROGRAM_ALERTS_PER_PAGE = 25

export function useProgramAlerts(
  vetGuid: MaybeRefOrGetter<string>,
  guid: MaybeRefOrGetter<string>,
  filters: MaybeRefOrGetter<ProgramAlertsFilters>,
  page: MaybeRefOrGetter<number>,
) {
  const { can } = usePermission()

  const params = computed<ProgramAlertsParams>(() => {
    const f = toValue(filters)
    return {
      status: f.status,
      ...(f.profile ? { profile: f.profile } : {}),
      ...(f.target ? { target: f.target } : {}),
      ...(f.type ? { type: f.type } : {}),
      page: toValue(page),
      per_page: PROGRAM_ALERTS_PER_PAGE,
    }
  })

  return useQuery({
    queryKey: computed(() => ['program-alerts', toValue(vetGuid), toValue(guid), toValue(filters), toValue(page)]),
    queryFn: ({ signal }) => listProgramAlertsApi(toValue(vetGuid), toValue(guid), params.value, signal),
    placeholderData: keepPreviousData,
    // Delivery state and contact advice change outside this screen (jobs, provider webhooks,
    // contact edits), so always refetch on mount/focus instead of serving the 5-minute global cache.
    staleTime: 0,
    enabled: computed(() => can('programs.alerts.read') && Boolean(toValue(vetGuid)) && Boolean(toValue(guid))),
  })
}
