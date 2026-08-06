import type { RouteRecordRaw } from 'vue-router'

export const vetProgramsRoutes: RouteRecordRaw[] = [
  {
    path: 'programs',
    name: 'vet-programs-list',
    component: () => import('@/modules/programs/pages/tenant/VetProgramsListPage.vue'),
    meta: { requiresAuth: true, title: 'Programas' },
  },
  {
    path: 'programs/new',
    name: 'vet-programs-new',
    component: () => import('@/modules/programs/pages/tenant/VetProgramFormPage.vue'),
    meta: { requiresAuth: true, title: 'Nuevo programa' },
  },
  {
    path: 'programs/:guid/edit',
    name: 'vet-programs-edit',
    component: () => import('@/modules/programs/pages/tenant/VetProgramFormPage.vue'),
    meta: { requiresAuth: true, title: 'Editar programa' },
  },
  {
    path: 'programs/:guid',
    name: 'vet-programs-detail',
    component: () => import('@/modules/programs/pages/tenant/VetProgramDetailPage.vue'),
    meta: { requiresAuth: true, title: 'Detalle de programa' },
  },
]
