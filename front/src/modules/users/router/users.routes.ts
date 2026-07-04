import type { RouteRecordRaw } from 'vue-router'

export const usersRoutes: RouteRecordRaw[] = [
  {
    path: '/users',
    name: 'users-module',
    component: () => import('@/modules/users/pages/UsersPage.vue'),
    meta: { requiresAuth: true, title: 'Usuarios' },
  },
]
