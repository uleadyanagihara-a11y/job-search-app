// API 契約は docs/inertia-removal-migration.md §5.1–5.2 と docs/phase0-api-contract.md §0.3。
// response は unknown から受け、ここの guard で形を確かめてから型を付ける（§5.7）。

/** 1 アカウント 1 ロール（docs/domain-design.md 1 章）。 */
export type Role = 'seeker' | 'company';

export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at: string | null;
    role: Role;
    /** 企業担当者の所属企業。求職者は null（docs/domain-api-contract.md M2.2）。 */
    company_id: number | null;
}

/** POST /api/company-invitations/lookup の data。 */
export interface InvitationSummary {
    company_name: string;
    email: string;
    expires_at: string;
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
        isNullableString(value.email_verified_at) &&
        (value.role === 'seeker' || value.role === 'company') &&
        (value.company_id === null || typeof value.company_id === 'number')
    );
}

function isNullableString(value: unknown): value is string | null {
    return value === null || typeof value === 'string';
}

export function isInvitationSummaryResponse(value: unknown): value is { data: InvitationSummary } {
    return (
        isRecord(value) &&
        isRecord(value.data) &&
        typeof value.data.company_name === 'string' &&
        typeof value.data.email === 'string' &&
        typeof value.data.expires_at === 'string'
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
