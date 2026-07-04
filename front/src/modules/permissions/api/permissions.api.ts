import { http } from '@/core/api/http';
import type { PermissionGroup } from '@/modules/permissions/types/permission.types';

export async function listPermissionsApi(): Promise<PermissionGroup[]> {
    const res = await http.get('/v1/permissions');
    return res.data;
}
