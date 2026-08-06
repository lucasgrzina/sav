import type { RouteRecordRaw } from 'vue-router'

export const clientsRoutes: RouteRecordRaw[] = [
  {
    path: 'clients',
    name: 'clients-list',
    component: () => import('@/modules/clients/pages/tenant/ClientsListPage.vue'),
    meta: { requiresAuth: true, title: 'Clientes' },
  },
  {
    // IMPORTANTE: /new DEBE estar antes que /:guid para evitar que Vue Router
    // interprete "new" como un guid.
    path: 'clients/new',
    name: 'clients-create',
    component: () => import('@/modules/clients/pages/tenant/ClientCreatePage.vue'),
    meta: { requiresAuth: true, title: 'Agregar cliente' },
  },
  {
    // IMPORTANTE: las rutas con :clientGuid/staff/... deben ir ANTES que /:guid
    // para evitar que Vue Router capture clientGuid como valor del param :guid.
    path: 'clients/:clientGuid/staff/new',
    name: 'clients-staff-create',
    component: () => import('@/modules/clients/pages/tenant/ClientStaffCreatePage.vue'),
    meta: { requiresAuth: true, title: 'Agregar staff al cliente' },
  },
  {
    path: 'clients/:clientGuid/staff/:profileGuid/edit',
    name: 'clients-staff-edit',
    component: () => import('@/modules/clients/pages/tenant/ClientEditStaffPage.vue'),
    meta: { requiresAuth: true, title: 'Editar staff del cliente' },
  },
  {
    path: 'clients/:guid',
    name: 'clients-detail',
    component: () => import('@/modules/clients/pages/tenant/ClientDetailPage.vue'),
    props: true,
    meta: { requiresAuth: true, title: 'Detalle del cliente' },
  },
  {
    path: 'clients/:guid/edit',
    name: 'clients-edit',
    component: () => import('@/modules/clients/pages/tenant/ClientEditPage.vue'),
    props: true,
    meta: { requiresAuth: true, title: 'Editar cliente' },
  },
]
