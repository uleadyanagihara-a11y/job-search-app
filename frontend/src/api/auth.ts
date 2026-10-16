import { apiRequest, expectShape, isApiError } from '@/api/client';
import {
    isMessageResponse,
    isUserResponse,
    isUserWithRedirectResponse,
    type MessageResponse,
    type User,
    type UserWithRedirectResponse,
} from '@/types/api';

// endpoint は docs/phase0-api-contract.md §0.2 で確定済み。

/**
 * GET /api/user。未ログイン（401）は null。
 */
export async function fetchUser(): Promise<User | null> {
    try {
        return expectShape(await apiRequest('GET', '/user', { allowUnauthenticated: true }), isUserResponse).data;
    } catch (error) {
        if (isApiError(error) && error.status === 401) {
            return null;
        }

        throw error;
    }
}

export interface LoginPayload {
    email: string;
    password: string;
    remember: boolean;
}

export async function login(payload: LoginPayload): Promise<UserWithRedirectResponse> {
    return expectShape(await apiRequest('POST', '/auth/login', { body: payload }), isUserWithRedirectResponse);
}

export interface RegisterPayload {
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
}

export async function register(payload: RegisterPayload): Promise<UserWithRedirectResponse> {
    return expectShape(await apiRequest('POST', '/auth/register', { body: payload }), isUserWithRedirectResponse);
}

export async function logout(): Promise<void> {
    await apiRequest('POST', '/auth/logout');
}

export async function sendPasswordResetLink(payload: { email: string }): Promise<MessageResponse> {
    return expectShape(await apiRequest('POST', '/auth/forgot-password', { body: payload }), isMessageResponse);
}

export interface ResetPasswordPayload {
    token: string;
    email: string;
    password: string;
    password_confirmation: string;
}

export async function resetPassword(payload: ResetPasswordPayload): Promise<MessageResponse> {
    return expectShape(await apiRequest('POST', '/auth/reset-password', { body: payload }), isMessageResponse);
}

export async function sendVerificationNotification(): Promise<MessageResponse> {
    return expectShape(await apiRequest('POST', '/auth/email/verification-notification'), isMessageResponse);
}

export async function confirmPassword(payload: { password: string }): Promise<void> {
    await apiRequest('POST', '/auth/confirm-password', { body: payload });
}
