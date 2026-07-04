import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import type { Ref } from 'vue'
import { listRolesApi } from '../api/roles.api'
import type { RoleFilters } from '../types/role.types'

export function useRoles(filters: Ref<RoleFilters> | RoleFilters = {}) {
  const filtersRef = computed(() => toValue(filters))

  return useQuery({
    queryKey: ['roles', filtersRef],
    queryFn: ({ signal }) => listRolesApi(filtersRef.value, signal),
    staleTime: 1000 * 60 * 5,
  })
}
