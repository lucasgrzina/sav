import { computed, onMounted } from 'vue'
import { useRolesCacheStore } from '@/modules/roles/stores/roles-cache.store'
import type { ClientStaffRoleItem, ClientStaffRoleName } from '../types/client.types'
import { CLIENT_STAFF_ROLES } from '../types/client.types'

export function useClientRoles() {
  const store = useRolesCacheStore()

  onMounted(() => {
    store.fetchTenantRoles()
  })

  const clientRoles = computed<ClientStaffRoleItem[]>(() =>
    store.roles
      .filter((r): r is typeof r & { name: ClientStaffRoleName } =>
        (CLIENT_STAFF_ROLES as readonly string[]).includes(r.name),
      )
      .map((r) => ({ guid: r.guid, name: r.name as ClientStaffRoleName })),
  )

  const isLoading = computed(() => store.loading)

  return { clientRoles, isLoading }
}
