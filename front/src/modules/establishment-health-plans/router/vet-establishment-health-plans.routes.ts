import type { RouteRecordRaw } from 'vue-router'

export const vetEstablishmentHealthPlansRoutes: RouteRecordRaw[] = [
  {
    path: 'health-plans',
    name: 'vet-health-plans-list',
    component: () => import('@/modules/establishment-health-plans/pages/tenant/VetEstablishmentHealthPlansListPage.vue'),
    meta: { requiresAuth: true, title: 'Planes Sanitarios' },
  },
  {
    path: 'health-plans/new',
    name: 'vet-health-plans-new',
    component: () => import('@/modules/establishment-health-plans/pages/tenant/VetEstablishmentHealthPlanNewPage.vue'),
    meta: { requiresAuth: true, title: 'Nuevo plan sanitario' },
  },
  {
    path: 'health-plans/:guid',
    name: 'vet-health-plans-detail',
    component: () => import('@/modules/establishment-health-plans/pages/tenant/VetEstablishmentHealthPlanDetailPage.vue'),
    meta: { requiresAuth: true, title: 'Detalle de plan sanitario' },
  },
  {
    path: 'health-plan-templates',
    name: 'vet-health-plan-templates-list',
    component: () => import('@/modules/establishment-health-plans/pages/tenant/VetHealthPlanTemplatesListPage.vue'),
    meta: { requiresAuth: true, title: 'Plantillas de plan sanitario' },
  },
]
