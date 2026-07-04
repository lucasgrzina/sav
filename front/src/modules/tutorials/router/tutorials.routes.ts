import type { RouteRecordRaw } from 'vue-router'

export const tutorialsRoutes: RouteRecordRaw[] = [
  {
    path: '/tutorials',
    name: 'tutorials',
    component: () => import('@/modules/tutorials/pages/AdminTutorialsPage.vue'),
    meta: { requiresAuth: true, title: 'Tutoriales' },
  },
]
