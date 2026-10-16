import { apiRequest, expectShape } from '@/api/client';
import { isUserResponse, type User } from '@/types/api';

// `mustVerifyEmail` は返らないため、画面側で `email_verified_at === null` から導出する（phase0 §0.3）。
export async function fetchProfile(): Promise<User> {
    return expectShape(await apiRequest('GET', '/profile'), isUserResponse).data;
}

export async function updateProfile(payload: { name: string; email: string }): Promise<User> {
    return expectShape(await apiRequest('PATCH', '/profile', { body: payload }), isUserResponse).data;
}

export interface UpdatePasswordPayload {
    current_password: string;
    password: string;
    password_confirmation: string;
}

export async function updatePassword(payload: UpdatePasswordPayload): Promise<void> {
    await apiRequest('PUT', '/profile/password', { body: payload });
}

export async function deleteAccount(payload: { password: string }): Promise<void> {
    await apiRequest('DELETE', '/profile', { body: payload });
}
