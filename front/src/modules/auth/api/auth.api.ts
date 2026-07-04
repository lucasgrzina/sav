import { http } from '@/core/api/http';

// ── Tipos ──────────────────────────────────────────────────────────────────

export interface UserApiResponse {
    id: number;
    guid: string;
    first_name: string;
    last_name: string;
    email: string;
    last_login_at: string | null;
}

export interface LoginPayload {
    email: string;
    password: string;
    remember?: boolean;
}

export interface LoginResponse {
    access_token: string;
    user: UserApiResponse;
    must_verify_account: boolean;
    must_change_password: boolean;
}

export interface RegisterPayload {
    first_name: string;
    last_name: string;
    email: string;
    password: string;
    password_confirmation: string;
}

export interface RegisterResponse {
    guid: string;
    email: string;
}

export interface VerifyCodePayload {
    guid: string;
    code: string;
}

export interface ResendCodePayload {
    guid: string;
}

export interface ForgotPasswordVerifyEmailPayload {
    email: string;
}

export interface ForgotPasswordVerifyEmailResponse {
    guid: string;
    email: string;
    token: string;
}

export interface ForgotPasswordVerifyCodePayload {
    token: string;
    code: string;
}

export interface ForgotPasswordResendCodePayload {
    guid: string;
}

export interface ForgotPasswordResetPasswordPayload {
    guid: string;
    password: string;
    password_confirmation: string;
}

// ── Funciones ──────────────────────────────────────────────────────────────

export async function loginApi(payload: LoginPayload): Promise<LoginResponse> {
    const response = await http.post<LoginResponse>('/v1/auth/login', payload);
    return response.data;
}

export async function registerApi(payload: RegisterPayload): Promise<RegisterResponse> {
    const response = await http.post<RegisterResponse>('/v1/auth/register', payload);
    return response.data;
}

export async function logoutApi(): Promise<void> {
    await http.post('/v1/auth/logout');
}

export async function profileApi(): Promise<UserApiResponse> {
    const response = await http.get<UserApiResponse>('/v1/auth/profile');
    return response.data;
}

export async function verifyCodeApi(payload: VerifyCodePayload): Promise<void> {
    await http.post('/v1/auth/verify-account/verify-code', payload);
}

export async function resendVerificationCodeApi(payload: ResendCodePayload): Promise<{ guid: string; email: string }> {
    const response = await http.post<{ guid: string; email: string }>('/v1/auth/verify-account/resend-code', payload);
    return response.data;
}

export async function forgotPasswordVerifyEmailApi(
    payload: ForgotPasswordVerifyEmailPayload,
): Promise<ForgotPasswordVerifyEmailResponse> {
    const response = await http.post<ForgotPasswordVerifyEmailResponse>(
        '/v1/auth/forgot-password/verify-email',
        payload,
    );
    return response.data;
}

export async function forgotPasswordVerifyCodeApi(payload: ForgotPasswordVerifyCodePayload): Promise<void> {
    await http.post('/v1/auth/forgot-password/verify-code', payload);
}

export async function forgotPasswordResendCodeApi(
    payload: ForgotPasswordResendCodePayload,
): Promise<{ guid: string; email: string; token: string }> {
    const response = await http.post<{ guid: string; email: string; token: string }>(
        '/v1/auth/forgot-password/resend-code',
        payload,
    );
    return response.data;
}

export async function forgotPasswordResetPasswordApi(payload: ForgotPasswordResetPasswordPayload): Promise<void> {
    await http.post('/v1/auth/forgot-password/reset-password', payload);
}

export interface AcceptInvitationPayload {
    token: string;
    email: string;
    password: string;
    password_confirmation: string;
}

export interface AcceptInvitationUserResponse {
    id: number;
    guid: string;
    first_name: string;
    last_name: string;
    email: string;
    last_login_at: string | null;
    roles?: { guid: string; name: string }[];
    permissions?: string[];
}

export interface AcceptInvitationResponse {
    access_token: string;
    user: AcceptInvitationUserResponse;
    must_verify_account: boolean;
    must_change_password: boolean;
}

export async function acceptInvitationApi(payload: AcceptInvitationPayload): Promise<AcceptInvitationResponse> {
    const response = await http.post<AcceptInvitationResponse>('/v1/auth/invitation/accept', payload);
    return response.data;
}
