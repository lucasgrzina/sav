import { onMounted, computed } from 'vue'
import { useRolesCacheStore } from '../stores/roles-cache.store'

export function useTenantRoles() {
  const store = useRolesCacheStore()

  onMounted(() => {
    store.fetchTenantRoles()
  })

  return {
    tenantRoles: computed(() => store.tenantRoles),
    vetRoles: computed(() => store.vetRoles),
    clientRoles: computed(() => store.clientRoles),
    loading: computed(() => store.loading),
    loaded: computed(() => store.loaded),
    error: computed(() => store.error),
  }
}
