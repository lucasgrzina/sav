import { watchEffect, watch, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useVetStore } from '@/core/stores/vet.store'
import { useAuthStore } from '@/modules/auth/stores/auth.store'
import { useVetProfile } from './useVetProfile'
import { useNotification } from '@/core/composables/useNotification'
import type { VetUserProfile, VetTenantRole } from '@/core/types/vet-context.types'

export function useVetTenant() {
  const route     = useRoute()
  const router    = useRouter()
  const vetStore  = useVetStore()
  const authStore = useAuthStore()
  const { error: notifyError } = useNotification()

  const guid = computed(() => route.params.vetGuid as string)

  const { data: vetData, isLoading, isError, error } = useVetProfile(guid)

  watchEffect(() => {
    const vet = vetData.value
    if (!vet) return

    const userVetItem = vetStore.userVets.find(v => v.guid === guid.value)
    if (!userVetItem) return

    const profile: VetUserProfile = {
      guid: userVetItem.guid,
      role: {
        name:        userVetItem.role.name as VetTenantRole,
        permissions: userVetItem.role.permissions,
      },
    }

    authStore.clearTenantPermissions()
    vetStore.setCurrentVet(vet, profile)
    authStore.setTenantPermissions(profile.role.permissions)
  })

  watch(isError, (hasError) => {
    if (!hasError) return

    const e = error.value as { status?: number } | null

    // 401: el interceptor HTTP ya limpió el auth store y redirigió al login
    if (e?.status === 401) return

    vetStore.clearCurrentVet()
    vetStore.setLastVisitedGuid(null)

    if (e?.status === 404) {
      notifyError('La veterinaria no existe.')
    } else if (e?.status === 403) {
      notifyError('Ya no tenés acceso a esta veterinaria.')
    } else {
      notifyError('Esta veterinaria no está disponible actualmente.')
    }

    router.replace('/dashboard')
  })

  return { vetData, isLoading, isError }
}
