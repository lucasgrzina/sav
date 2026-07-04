import { computed, onMounted } from 'vue'
import { useRolesCacheStore } from '@/modules/roles/stores/roles-cache.store'
import type { VetStaffRoleItem, VetStaffRoleName } from '../types/vet.types'
import { VET_STAFF_ROLES } from '../types/vet.types'

export function useVetRoles() {
  const store = useRolesCacheStore()

  onMounted(() => {
    store.fetchTenantRoles()
  })

  const vetRoles = computed<VetStaffRoleItem[]>(() =>
    store.roles
      .filter((r): r is typeof r & { name: VetStaffRoleName } =>
        (VET_STAFF_ROLES as readonly string[]).includes(r.name),
      )
      .map((r) => ({ guid: r.guid, name: r.name as VetStaffRoleName })),
  )

  const isLoading = computed(() => store.loading)

  return { vetRoles, isLoading }
}
