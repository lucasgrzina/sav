import { useAuthStore } from '@/modules/auth/stores/auth.store';
import { storeToRefs } from 'pinia';

export function useAuth() {
    const store = useAuthStore();
    const { user, token, isAuthenticated, mustVerifyAccount, pendingVerificationGuid } = storeToRefs(store);

    return {
        user,
        token,
        isAuthenticated,
        mustVerifyAccount,
        pendingVerificationGuid,
        login: store.login,
        register: store.register,
        logout: store.logout,
        fetchUser: store.fetchUser,
    };
}
