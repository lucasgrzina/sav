import type { RouteRecordRaw } from 'vue-router'
import { vetTenantGuard } from '@/router/guards/vetTenantGuard'

export const vetsTenantRoutes: RouteRecordRaw[] = [
  {
    path: '/vets/:vetGuid',
    component: () => import('@/components/layouts/VetTenantLayout.vue'),
    beforeEnter: vetTenantGuard,
    children: [
      {
        path: '',
        redirect: to => `/vets/${to.params.vetGuid}/perfil`,
      },
      {
        path: 'perfil',
        name: 'vet-tenant-perfil',
        component: () => import('@/modules/vets/pages/tenant/VetProfilePage.vue'),
        meta: { requiresAuth: true, title: 'Perfil de la veterinaria' },
      },
      {
        path: 'perfil/editar',
        name: 'vet-tenant-perfil-editar',
        component: () => import('@/modules/vets/pages/tenant/VetTenantEditPage.vue'),
        meta: { requiresAuth: true, title: 'Editar perfil de la veterinaria' },
      },
      {
        path: 'usuarios',
        name: 'vet-tenant-usuarios',
        component: () => import('@/modules/vets/pages/tenant/VetUsersPage.vue'),
        meta: { requiresAuth: true, title: 'Usuarios de la veterinaria' },
      },
      {
        path: 'usuarios/crear',
        name: 'vet-tenant-usuarios-crear',
        component: () => import('@/modules/vets/pages/tenant/VetStaffCreatePage.vue'),
        meta: { requiresAuth: true, title: 'Incorporar personal al equipo' },
      },
      {
        path: 'usuarios/:profileGuid/editar',
        name: 'vet-tenant-usuarios-editar',
        component: () => import('@/modules/vets/pages/tenant/VetEditStaffPage.vue'),
        meta: { requiresAuth: true, title: 'Editar perfil de usuario' },
      },
      {
        path: 'mi-perfil',
        name: 'vet-tenant-mi-perfil',
        component: () => import('@/modules/vets/pages/tenant/MyVetProfilePage.vue'),
        meta: { requiresAuth: true, title: 'Mi perfil' },
      },
      {
        path: 'tutoriales',
        name: 'vet-tenant-tutoriales',
        component: () => import('@/modules/tutorials/pages/TutorialsViewerPage.vue'),
        meta: { requiresAuth: true, title: 'Tutoriales' },
      },
      {
        path: 'soporte',
        name: 'vet-tenant-soporte',
        component: () => import('@/modules/support-messages/pages/SupportMessagesPage.vue'),
        meta: { requiresAuth: true, title: 'Soporte' },
      },
    ],
  },
]
