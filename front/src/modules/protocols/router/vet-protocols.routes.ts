import type { RouteRecordRaw } from 'vue-router'

export const vetProtocolsRoutes: RouteRecordRaw[] = [
  {
    path: 'protocols',
    name: 'vet-protocols-list',
    component: () => import('@/modules/protocols/pages/tenant/VetProtocolsListPage.vue'),
    meta: { requiresAuth: true, title: 'Protocolos' },
  },
  {
    path: 'protocols/:guid',
    name: 'vet-protocols-detail',
    component: () => import('@/modules/protocols/pages/tenant/VetProtocolDetailPage.vue'),
    meta: { requiresAuth: true, title: 'Detalle de protocolo' },
  },
]
