import type { RouteRecordRaw } from 'vue-router'

// Children of /vets/:vetGuid (VetTenantLayout + vetTenantGuard).
export const vetHomeRoutes: RouteRecordRaw[] = [
  {
    path: 'inicio',
    name: 'vet-tenant-inicio',
    component: () => import('@/modules/home/pages/VetHomePage.vue'),
    meta: { requiresAuth: true, title: 'Inicio' },
  },
]
