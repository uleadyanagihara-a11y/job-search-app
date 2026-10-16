import { isApiErrorBody, type ValidationErrors } from '@/types/api';

// Sanctum の SPA 認証（docs/inertia-removal-migration.md §4.2.3）。
// origin は埋め込まず、Nginx の同一 origin 上の相対 URL だけを使う。
const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || '/api';
const CSRF_COOKIE_URL = '/sanctum/csrf-cookie';

type Method = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';

/**
 * API の失敗を表す。`code` は §5.1 の error code。
 * ただし `network_error`（fetch 自体の失敗）と `unexpected_response`（JSON でない、または契約外の形）は
 * Laravel が返さない client 側だけの code。
 */
export class ApiError extends Error {
    readonly status: number;
    readonly code: string;
    readonly errors: ValidationErrors;

    constructor(status: number, code: string, message: string, errors: ValidationErrors = {}) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.code = code;
        this.errors = errors;
    }
}

export function isApiError(error: unknown): error is ApiError {
    return error instanceof ApiError;
}

type UnauthenticatedHandler = () => void;

let unauthenticatedHandler: UnauthenticatedHandler | null = null;

/**
 * session 切れ等で API が 401 を返したときの処理（auth store の破棄と login への遷移）を登録する。
 */
export function onUnauthenticated(handler: UnauthenticatedHandler): void {
    unauthenticatedHandler = handler;
}

export interface RequestOptions {
    body?: unknown;
    /** 401 を想定内として扱い、onUnauthenticated の handler を呼ばない（起動時の GET /api/user 用）。 */
    allowUnauthenticated?: boolean;
}

/**
 * JSON API を呼び、成功時は body を unknown のまま返す（204 は null）。
 * 形の検証は呼び出し側（src/api/*.ts）の guard で行う。
 */
export async function apiRequest(method: Method, path: string, options: RequestOptions = {}): Promise<unknown> {
    const isMutation = method !== 'GET';

    if (isMutation && readXsrfToken() === null) {
        await fetchCsrfCookie();
    }

    let response = await send(method, path, options.body);

    // session 期限切れ等で token が古い場合は、Cookie を取り直して 1 度だけ再送する。
    if (isMutation && response.status === 419) {
        await fetchCsrfCookie();
        response = await send(method, path, options.body);
    }

    if (response.status === 204) {
        return null;
    }

    const payload = await readJson(response);

    if (!response.ok) {
        const error = toApiError(response.status, payload);

        if (error.status === 401 && !options.allowUnauthenticated) {
            unauthenticatedHandler?.();
        }

        throw error;
    }

    return payload;
}

/**
 * guard を満たさない成功 response を契約違反として ApiError にする。
 */
export function expectShape<T>(payload: unknown, guard: (value: unknown) => value is T): T {
    if (!guard(payload)) {
        throw new ApiError(0, 'unexpected_response', 'サーバーから予期しない応答がありました。');
    }

    return payload;
}

async function send(method: Method, path: string, body: unknown): Promise<Response> {
    const headers: Record<string, string> = { Accept: 'application/json' };

    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
    }

    const xsrfToken = readXsrfToken();

    if (method !== 'GET' && xsrfToken !== null) {
        headers['X-XSRF-TOKEN'] = xsrfToken;
    }

    try {
        return await fetch(`${API_BASE_URL}${path}`, {
            method,
            headers,
            body: body === undefined ? undefined : JSON.stringify(body),
            credentials: 'same-origin',
        });
    } catch {
        throw networkError();
    }
}

async function fetchCsrfCookie(): Promise<void> {
    let response: Response;

    try {
        response = await fetch(CSRF_COOKIE_URL, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
    } catch {
        throw networkError();
    }

    if (!response.ok) {
        throw toApiError(response.status, await readJson(response));
    }
}

function readXsrfToken(): string | null {
    for (const cookie of document.cookie.split('; ')) {
        const [name, ...rest] = cookie.split('=');

        if (name === 'XSRF-TOKEN') {
            return decodeURIComponent(rest.join('='));
        }
    }

    return null;
}

async function readJson(response: Response): Promise<unknown> {
    if (!response.headers.get('Content-Type')?.includes('application/json')) {
        return null;
    }

    try {
        return await response.json();
    } catch {
        return null;
    }
}

function toApiError(status: number, payload: unknown): ApiError {
    if (isApiErrorBody(payload)) {
        return new ApiError(status, payload.code, payload.message, payload.errors);
    }

    // Nginx の 502 HTML 等、Laravel の JSON error 以外。
    return new ApiError(status, 'unexpected_response', 'サーバーから予期しない応答がありました。');
}

function networkError(): ApiError {
    return new ApiError(0, 'network_error', 'サーバーに接続できませんでした。');
}
