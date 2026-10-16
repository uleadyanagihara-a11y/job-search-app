// API 契約は docs/inertia-removal-migration.md §5.1–5.2 と docs/phase0-api-contract.md §0.3。
// response は unknown から受け、ここの guard で形を確かめてから型を付ける（§5.7）。

export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at: string | null;
}

export type ValidationErrors = Record<string, string[]>;

export interface ApiErrorBody {
    code: string;
    message: string;
    errors?: ValidationErrors;
}

export interface MessageResponse {
    code: string;
    message: string;
}

export interface UserWithRedirectResponse {
    data: User;
    meta: { redirect_to: string };
}

export function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

export function isUser(value: unknown): value is User {
    return (
        isRecord(value) &&
        typeof value.id === 'number' &&
        typeof value.name === 'string' &&
        typeof value.email === 'string' &&
        (value.email_verified_at === null || typeof value.email_verified_at === 'string')
    );
}

function isValidationErrors(value: unknown): value is ValidationErrors {
    return (
        isRecord(value) &&
        Object.values(value).every(
            (messages) => Array.isArray(messages) && messages.every((m) => typeof m === 'string'),
        )
    );
}

export function isApiErrorBody(value: unknown): value is ApiErrorBody {
    return (
        isRecord(value) &&
        typeof value.code === 'string' &&
        typeof value.message === 'string' &&
        (value.errors === undefined || isValidationErrors(value.errors))
    );
}

export function isMessageResponse(value: unknown): value is MessageResponse {
    return isRecord(value) && typeof value.code === 'string' && typeof value.message === 'string';
}

export function isUserResponse(value: unknown): value is { data: User } {
    return isRecord(value) && isUser(value.data);
}

export function isUserWithRedirectResponse(value: unknown): value is UserWithRedirectResponse {
    if (!isRecord(value) || !isUser(value.data) || !isRecord(value.meta)) {
        return false;
    }

    return typeof value.meta.redirect_to === 'string';
}
