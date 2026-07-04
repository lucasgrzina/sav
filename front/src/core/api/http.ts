import axios from 'axios'
import type { AxiosInstance, AxiosResponse, AxiosError, InternalAxiosRequestConfig } from 'axios'
import { API_BASE_URL } from '@/core/constants/app'
import { useAuthStore } from '@/modules/auth/stores/auth.store'

export const http: AxiosInstance = axios.create({
    baseURL: API_BASE_URL,
    timeout: 30000,
    headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
    },
})

// useAuthStore() se llama DENTRO de las funciones del interceptor, nunca en el
// top-level del módulo. En ESM/Vite los imports circulares se resuelven antes
// de que cualquier request ocurra, por lo que el store ya está inicializado.
function getAuthToken(): string | null {
    try {
        return useAuthStore().token
    } catch {
        return null
    }
}

function resetAuthStore(): void {
    try {
        useAuthStore().$reset()
    } catch {
        // Pinia aún no inicializado (improbable en runtime normal)
    }
}

// REQUEST INTERCEPTOR — inyectar Bearer token si existe
http.interceptors.request.use(
    (config: InternalAxiosRequestConfig) => {
        const token = getAuthToken()
        if (token && config.headers) {
            config.headers['Authorization'] = `Bearer ${token}`
        }
        return config
    },
    (error: AxiosError) => Promise.reject(error),
)

// RESPONSE INTERCEPTOR — desenvuelve el wrapper { success, data, message }
http.interceptors.response.use(
    (response: AxiosResponse) => {
        const payload = response.data as Record<string, unknown> | null

        if (payload && payload['success'] === true) {
            response.data = payload['data']
            return response
        }

        if (payload && payload['success'] === false) {
            return Promise.reject({
                success: false,
                message: payload['message'] ?? 'Error en la solicitud.',
                errors: payload['errors'] ?? null,
                error_code: payload['error_code'] ?? null,
            })
        }

        return response
    },
    (error: AxiosError) => {
        const status = error.response?.status
        const payload = error.response?.data as Record<string, unknown> | undefined

        if (status === 401) {
            resetAuthStore()

            // Redirigir al login de forma lazy (import dinámico) para evitar circular
            import('@/router').then(({ router }) => {
                if (router.currentRoute.value.path !== '/login') {
                    router.push('/login')
                }
            })

            return Promise.reject({
                success: false,
                status: 401,
                message: 'Sesión expirada. Por favor, iniciá sesión nuevamente.',
                errors: null,
            })
        }

        if (status === 422) {
            return Promise.reject({
                success: false,
                status: 422,
                message: payload?.['message'] ?? 'Error de validación.',
                errors: payload?.['errors'] ?? null,
            })
        }

        if (status === 500) {
            return Promise.reject({
                success: false,
                status: 500,
                message: payload?.['message'] ?? 'Error interno del servidor.',
                errors: null,
            })
        }

        if (!error.response) {
            return Promise.reject({
                success: false,
                status: undefined,
                message: 'No se pudo conectar con el servidor.',
                errors: null,
            })
        }

        return Promise.reject({
            success: false,
            status,
            message: payload?.['message'] ?? 'Error en la solicitud.',
            errors: payload?.['errors'] ?? null,
        })
    },
)
