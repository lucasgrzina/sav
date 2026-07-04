import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { listRolesApi } from '../api/roles.api'
import { getRoleLabel } from '@/core/utils/roles'
import { VET_STAFF_ROLES } from '@/modules/vets/types/vet.types'
import { CLIENT_STAFF_ROLES } from '@/modules/clients/types/client.types'
import type { RoleItem } from '../types/role.types'

export interface TenantRoleOption {
  value: string
  label: string
  name: string
}

export const useRolesCacheStore = defineStore('roles-cache', () => {
  const roles = ref<RoleItem[]>([])
  const loaded = ref(false)
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function fetchTenantRoles(): Promise<void> {
    if (loaded.value || loading.value) return

    loading.value = true
    error.value = null

    try {
      const response = await listRolesApi({ type: 'tenant', per_page: 50 })
      roles.value = response.data
      loaded.value = true
    } catch (err) {
      error.value = 'No se pudieron cargar los roles'
    } finally {
      loading.value = false
    }
  }

  const tenantRoles = computed<TenantRoleOption[]>(() =>
    roles.value.map((r) => ({
      value: r.guid,
      label: getRoleLabel(r.name),
      name: r.name,
    })),
  )

  const vetRoles = computed<TenantRoleOption[]>(() =>
    roles.value
      .filter((r) => (VET_STAFF_ROLES as readonly string[]).includes(r.name))
      .map((r) => ({
        value: r.guid,
        label: getRoleLabel(r.name),
        name: r.name,
      })),
  )

  const clientRoles = computed<TenantRoleOption[]>(() =>
    roles.value
      .filter((r) => (CLIENT_STAFF_ROLES as readonly string[]).includes(r.name))
      .map((r) => ({
        value: r.guid,
        label: getRoleLabel(r.name),
        name: r.name,
      })),
  )

  return {
    roles,
    loaded,
    loading,
    error,
    fetchTenantRoles,
    tenantRoles,
    vetRoles,
    clientRoles,
  }
})
