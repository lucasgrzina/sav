export interface AuthUserRole {
    guid: string
    name: string
}

export interface User {
    id: number;
    guid: string;
    first_name: string;
    last_name: string;
    email: string;
    last_login_at: string | null;
    roles?: AuthUserRole[];
    permissions?: string[];
}

export interface AuthState {
    token: string | null;
    user: User | null;
    isAuthenticated: boolean;
    mustVerifyAccount: boolean;
    mustChangePassword: boolean;
}

export interface LoginPayload {
    email: string;
    password: string;
    remember?: boolean;
}

export interface RegisterPayload {
    first_name: string;
    last_name: string;
    email: string;
    password: string;
    password_confirmation: string;
}

export interface TokenResponse {
    token: string;
    token_type: string;
}
