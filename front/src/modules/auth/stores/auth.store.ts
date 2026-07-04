import { defineStore } from 'pinia'
import type { User } from '@/modules/auth/types/auth.types'
import { loginApi, registerApi, logoutApi, profileApi } from '@/modules/auth/api/auth.api'
import type { RegisterPayload } from '@/modules/auth/api/auth.api'

interface AuthStoreState {
    token: string | null
    user: User | null
    isLoggingIn: boolean
    isLoggingOut: boolean
    mustVerifyAccount: boolean
    pendingVerificationGuid: string | null
    mustChangePassword: boolean
    tenantPermissions: string[]
}

export const useAuthStore = defineStore('auth', {
    state: (): AuthStoreState => ({
        token: null,
        user: null,
        isLoggingIn: false,
        isLoggingOut: false,
        mustVerifyAccount: false,
        pendingVerificationGuid: null,
        mustChangePassword: false,
        tenantPermissions: [],
    }),

    getters: {
        isAuthenticated: (s): boolean => Boolean(s.user && s.token),
    },

    actions: {
        async login(email: string, password: string, remember = false): Promise<{ must_verify_account?: boolean; must_change_password?: boolean; user?: User }> {
            this.isLoggingIn = true
            try {
                const data = await loginApi({ email, password, remember })

                if (data.must_verify_account) {
                    this.mustVerifyAccount = true
                    if (data.user) this.pendingVerificationGuid = data.user.guid
                    return { must_verify_account: true, user: data.user as User }
                }

                this.token = data.access_token
                this.user  = data.user as User
                this.mustVerifyAccount = false
                this.pendingVerificationGuid = null
                this.mustChangePassword = data.must_change_password ?? false

                return { must_verify_account: false, user: data.user as User, must_change_password: this.mustChangePassword }
            } finally {
                this.isLoggingIn = false
            }
        },

        async register(payload: RegisterPayload): Promise<{ guid: string; email: string }> {
            return registerApi(payload)
        },

        async logout(): Promise<void> {
            this.isLoggingOut = true
            try {
                await logoutApi()
            } catch {
                // Ignorar — limpiar sesión igual
            } finally {
                this.$reset()
                this.isLoggingOut = false
            }
        },

        async fetchUser(): Promise<void> {
            const data = await profileApi()
            this.user = data as User
        },

        setTenantPermissions(permissions: string[]): void {
            this.tenantPermissions = permissions
        },

        clearTenantPermissions(): void {
            this.tenantPermissions = []
        },
    },

    persist: {
        pick: ['token', 'user', 'mustVerifyAccount', 'pendingVerificationGuid', 'mustChangePassword', 'tenantPermissions'],
    },
})
