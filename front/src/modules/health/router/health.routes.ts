import type { RouteRecordRaw } from 'vue-router'

export const healthRoutes: RouteRecordRaw[] = [
  {
    path: '/health/activities',
    name: 'admin-health-activities',
    component: () => import('@/modules/health/pages/HealthActivitiesPage.vue'),
    meta: { requiresAuth: true, title: 'Actividades Sanitarias' },
  },
  {
    path: '/health/categories',
    name: 'admin-health-plan-categories',
    component: () => import('@/modules/health/pages/HealthPlanCategoriesPage.vue'),
    meta: { requiresAuth: true, title: 'Categorías de Planes Sanitarios' },
  },
  {
    path: '/health/templates',
    name: 'admin-health-plan-templates',
    component: () => import('@/modules/health/pages/HealthPlanTemplatesPage.vue'),
    meta: { requiresAuth: true, title: 'Plantillas de Planes Sanitarios' },
  },
]
