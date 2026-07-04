import type { RouteRecordRaw } from 'vue-router'

export const authRoutes: RouteRecordRaw[] = [
    {
        path: '/login',
        name: 'login',
        component: () => import('@/modules/auth/pages/LoginPage.vue'),
        meta: { requiresGuest: true },
    },
    {
        path: '/register',
        name: 'register',
        component: () => import('@/modules/auth/pages/RegisterPage.vue'),
        meta: { requiresGuest: true },
    },
    {
        path: '/verify-account/:guid',
        name: 'verify-account',
        component: () => import('@/modules/auth/pages/VerifyAccountPage.vue'),
        props: true,
        meta: { requiresGuest: true },
    },
    {
        path: '/forgot-password',
        name: 'forgot-password',
        component: () => import('@/modules/auth/pages/ForgotPasswordPage.vue'),
        meta: { requiresGuest: true },
    },
    {
        path: '/reset-password/:guid',
        name: 'reset-password',
        component: () => import('@/modules/auth/pages/ResetPasswordPage.vue'),
        meta: { requiresGuest: true },
    },
    {
        path: '/invitacion',
        name: 'invitation',
        component: () => import('@/modules/auth/pages/InvitationPage.vue'),
        meta: { requiresGuest: true },
    },
]
