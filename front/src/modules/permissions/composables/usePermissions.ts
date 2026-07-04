import { useQuery } from '@tanstack/vue-query';
import { listPermissionsApi } from '@/modules/permissions/api/permissions.api';

export function usePermissions() {
    return useQuery({
        queryKey: ['permissions'],
        queryFn: () => listPermissionsApi(),
        staleTime: 1000 * 60 * 30, // 30 minutos — permisos cambian raramente
        gcTime: 1000 * 60 * 60,    // 1 hora en cache
    });
}
