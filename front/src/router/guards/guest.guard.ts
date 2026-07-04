import type { NavigationGuard } from 'vue-router'
import { useAuthStore } from '@/modules/auth/stores/auth.store'
import { useVetStore } from '@/core/stores/vet.store'
import { ROUTES } from '@/core/constants/app'

export const guestGuard: NavigationGuard = () => {
    const auth = useAuthStore()

    if (auth.isAuthenticated) {
        const vetStore = useVetStore()
        if (vetStore.lastVisitedGuid) {
            return { path: `/vets/${vetStore.lastVisitedGuid}`, replace: true }
        }
        return { path: ROUTES.dashboard, replace: true }
    }

    return true
}
