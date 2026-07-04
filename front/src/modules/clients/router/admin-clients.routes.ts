import type { RouteRecordRaw } from 'vue-router'

export const adminClientsRoutes: RouteRecordRaw[] = [
  {
    path: '/admin/clients',
    name: 'admin-clients-list',
    component: () => import('@/modules/clients/pages/admin/AdminClientListPage.vue'),
    meta: { requiresAuth: true, title: 'Clientes (Admin)' },
  },
  {
    // /new DEBE ir antes que /:guid para evitar que Vue Router interprete "new" como un guid
    path: '/admin/clients/new',
    name: 'admin-clients-create',
    component: () => import('@/modules/clients/pages/admin/AdminClientCreatePage.vue'),
    meta: { requiresAuth: true, title: 'Nuevo cliente' },
  },
  {
    // La ruta de edición de staff debe ir antes que /:guid para evitar colisión con :guid/staff/:profileGuid
    path: '/admin/clients/:guid/staff/:profileGuid/editar',
    name: 'admin-clients-staff-edit',
    component: () => import('@/modules/clients/pages/admin/AdminClientEditStaffPage.vue'),
    props: true,
    meta: { requiresAuth: true, title: 'Editar staff del cliente' },
  },
  {
    path: '/admin/clients/:guid',
    name: 'admin-clients-detail',
    component: () => import('@/modules/clients/pages/admin/AdminClientDetailPage.vue'),
    props: true,
    meta: { requiresAuth: true, title: 'Detalle del cliente' },
  },
  {
    path: '/admin/clients/:guid/edit',
    name: 'admin-clients-edit',
    component: () => import('@/modules/clients/pages/admin/AdminClientEditPage.vue'),
    props: true,
    meta: { requiresAuth: true, title: 'Editar cliente' },
  },
]
