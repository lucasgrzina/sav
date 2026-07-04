import type { RouteRecordRaw } from 'vue-router'

export const vetsRoutes: RouteRecordRaw[] = [
  {
    path: '/admin/vets',
    name: 'vets-list',
    component: () => import('@/modules/vets/pages/admin/AdminVetsListPage.vue'),
    meta: { requiresAuth: true, title: 'Veterinarias' },
  },
  {
    path: '/admin/vets/new',
    name: 'vets-create',
    component: () => import('@/modules/vets/pages/admin/AdminVetCreatePage.vue'),
    meta: { requiresAuth: true, title: 'Nueva veterinaria' },
  },
  {
    path: '/admin/vets/:guid/staff/:profileGuid/editar',
    name: 'vets-staff-edit',
    component: () => import('@/modules/vets/pages/admin/AdminVetEditStaffPage.vue'),
    props: true,
    meta: { requiresAuth: true, title: 'Editar miembro de staff' },
  },
  {
    path: '/admin/vets/:guid',
    name: 'vets-detail',
    component: () => import('@/modules/vets/pages/admin/AdminVetDetailPage.vue'),
    props: true,
    meta: { requiresAuth: true, title: 'Detalle de veterinaria' },
  },
  {
    path: '/admin/vets/:guid/edit',
    name: 'vets-edit',
    component: () => import('@/modules/vets/pages/admin/AdminVetEditPage.vue'),
    props: true,
    meta: { requiresAuth: true, title: 'Editar veterinaria' },
  },
  {
    path: '/admin/vets/:guid/clients/new',
    name: 'vets-client-create',
    component: () => import('@/modules/vets/pages/admin/AdminVetClientCreatePage.vue'),
    props: true,
    meta: { requiresAuth: true, title: 'Agregar cliente a veterinaria' },
  },
]
