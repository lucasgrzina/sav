import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { VetItem } from '@/modules/vets/types/vet.types'
import type { UserVetItem } from '@/modules/vets/types/user-vet.types'
import type { VetUserProfile } from '@/core/types/vet-context.types'
import { useAuthStore } from '@/modules/auth/stores/auth.store'

export const useVetStore = defineStore('vet', () => {
  const currentVet      = ref<VetItem | null>(null)
  const vetGuid         = ref<string | null>(null)
  const currentProfile  = ref<VetUserProfile | null>(null)
  const userVets        = ref<UserVetItem[]>([])
  const lastVisitedGuid = ref<string | null>(null)

  function setCurrentVet(vet: VetItem, profile: VetUserProfile): void {
    currentVet.value      = vet
    vetGuid.value         = vet.guid
    currentProfile.value  = profile
    lastVisitedGuid.value = vet.guid
  }

  function setUserVets(vets: UserVetItem[]): void {
    userVets.value = vets
  }

  function clearCurrentVet(): void {
    currentVet.value     = null
    vetGuid.value        = null
    currentProfile.value = null
    useAuthStore().clearTenantPermissions()
  }

  function clearAll(): void {
    currentVet.value      = null
    vetGuid.value         = null
    currentProfile.value  = null
    userVets.value        = []
    lastVisitedGuid.value = null
    useAuthStore().clearTenantPermissions()
  }

  function setLastVisitedGuid(guid: string | null): void {
    lastVisitedGuid.value = guid
  }

  return {
    currentVet,
    vetGuid,
    currentProfile,
    userVets,
    lastVisitedGuid,
    setCurrentVet,
    setUserVets,
    clearCurrentVet,
    clearAll,
    setLastVisitedGuid,
  }
}, {
  persist: {
    pick: ['lastVisitedGuid', 'currentVet', 'vetGuid', 'currentProfile'],
  },
})
