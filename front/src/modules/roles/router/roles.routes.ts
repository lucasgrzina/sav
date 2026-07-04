import type { RouteRecordRaw } from 'vue-router'

export const rolesRoutes: RouteRecordRaw[] = [
  {
    path: '/roles',
    name: 'roles',
    component: () => import('@/modules/roles/pages/RolesPage.vue'),
    meta: { requiresAuth: true },
  },
]
