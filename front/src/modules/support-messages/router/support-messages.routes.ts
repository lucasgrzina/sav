import type { RouteRecordRaw } from 'vue-router'

export const supportMessagesRoutes: RouteRecordRaw[] = [
  {
    path: '/support-messages',
    name: 'support-messages',
    component: () => import('@/modules/support-messages/pages/SupportMessagesPage.vue'),
    meta: { requiresAuth: true, title: 'Soporte' },
  },
]
