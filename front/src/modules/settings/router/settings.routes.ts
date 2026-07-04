import type { RouteRecordRaw } from 'vue-router'

export const settingsRoutes: RouteRecordRaw[] = [
    {
        path: '/settings',
        name: 'settings',
        component: () => import('@/modules/settings/pages/SettingsPage.vue'),
        meta: { requiresAuth: true, title: 'Configuración' },
    },
]
