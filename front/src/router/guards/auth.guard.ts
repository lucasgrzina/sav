import type { NavigationGuard } from 'vue-router'
import { useAuthStore } from '@/modules/auth/stores/auth.store'
import { ROUTES } from '@/core/constants/app'

export const authGuard: NavigationGuard = (to) => {
    const auth = useAuthStore()

    if (!auth.isAuthenticated) {
        return { path: ROUTES.login, replace: true }
    }

    return true
}
