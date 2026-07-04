import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import type { Ref } from 'vue'
import { listUsersApi } from '@/modules/users/api/users.api'
import type { UserFilters, UserListParams } from '../types/user.types'

function toListParams(filters: UserFilters): UserListParams {
  return {
    ...filters,
    // null no es válido para el endpoint — convertir a undefined
    status: filters.status ?? undefined,
  }
}

export function useUsers(filters: Ref<UserFilters> | UserFilters) {
  const filtersRef = computed(() => toValue(filters))

  return useQuery({
    queryKey: ['users', filtersRef],
    queryFn: ({ signal }) => listUsersApi(toListParams(filtersRef.value), signal),
    staleTime: 1000 * 30,
  })
}
