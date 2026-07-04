import type { RouteRecordRaw } from 'vue-router'

export const techniquesRoutes: RouteRecordRaw[] = [
  {
    // /create DEBE ir ANTES que /:guid para evitar que Vue Router interprete "create" como guid
    path: '/techniques/create',
    name: 'admin-techniques-create',
    component: () => import('@/modules/techniques/pages/TechniqueCreatePage.vue'),
    meta: { requiresAuth: true, title: 'Nueva técnica' },
  },
  {
    path: '/techniques/:guid/edit',
    name: 'admin-techniques-edit',
    component: () => import('@/modules/techniques/pages/TechniqueEditPage.vue'),
    props: true,
    meta: { requiresAuth: true, title: 'Editar técnica' },
  },
  {
    path: '/techniques/:guid',
    name: 'admin-techniques-detail',
    component: () => import('@/modules/techniques/pages/TechniqueDetailPage.vue'),
    props: true,
    meta: { requiresAuth: true, title: 'Detalle de técnica' },
  },
  {
    path: '/techniques',
    name: 'admin-techniques-list',
    component: () => import('@/modules/techniques/pages/TechniqueListPage.vue'),
    meta: { requiresAuth: true, title: 'Técnicas de Reproducción' },
  },
]
