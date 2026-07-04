import type { RouteRecordRaw } from 'vue-router'

export const systemSettingsRoutes: RouteRecordRaw[] = [
    {
        path: '/admin/system-settings',
        name: 'system-settings',
        component: () => import('@/modules/system-settings/pages/SystemSettingsPage.vue'),
        meta: { requiresAuth: true, title: 'Configuración del Sistema' },
    },
]
