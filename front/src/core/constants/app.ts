export const API_BASE_URL = import.meta.env.VITE_API_BASE_URL as string;
export const APP_NAME = (import.meta.env.VITE_APP_NAME as string) ?? 'Mi Proyecto';
export const AUTH_MODE = 'token' as const;

export const ROUTES = {
    login: '/login',
    register: '/register',
    dashboard: '/dashboard',
    verifyAccount: '/verify-account',
    forgotPassword: '/forgot-password',
} as const;
