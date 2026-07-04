import { computed } from 'vue';
import { useAuthStore } from '@/modules/auth/stores/auth.store';

export function usePermission() {
    const auth = useAuthStore();

    const userPermissions   = computed(() => auth.user?.permissions ?? []);
    const userRoles         = computed(() => auth.user?.roles ?? []);
    const tenantPermissions = computed(() => auth.tenantPermissions);

    function can(permission: string): boolean {
        return userPermissions.value.includes(permission) ||
               tenantPermissions.value.includes(permission);
    }

    function hasRole(role: string): boolean {
        return userRoles.value.some(r => r.name === role);
    }

    function hasAnyRole(...roles: string[]): boolean {
        return roles.some(r => userRoles.value.some(ur => ur.name === r));
    }

    const hasTenantContext = computed(() => tenantPermissions.value.length > 0);

    return { can, hasRole, hasAnyRole, hasTenantContext };
}
