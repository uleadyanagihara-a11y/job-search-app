import { describe, expect, it, vi } from 'vitest';
import * as authApi from '@/api/auth';
import { isApiError, onUnauthenticated } from '@/api/client';
import type { User } from '@/types/api';

// 認証 API（docs/phase0-api-contract.md §0.2）の endpoint・body と、response の形の検証。
// CSRF・419・error の変換は tests/unit/api/client.test.ts で確認済みなので、ここは各関数の責務に絞る。

const user: User = { id: 1, name: 'Test', email: 'test@example.com', email_verified_at: null };

function jsonResponse(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

const unauthenticated = () => jsonResponse(401, { code: 'unauthenticated', message: 'Unauthenticated.' });

/** XSRF-TOKEN を置いた上で fetch を stub し、CSRF Cookie の取得を挟まずに 1 request だけ観測する。 */
function stubFetch(respond: () => Response | Promise<Response>) {
    document.cookie = 'XSRF-TOKEN=token; path=/';
    const fetchMock = vi.fn<typeof fetch>(async () => respond());
    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

function sentRequests(fetchMock: ReturnType<typeof stubFetch>) {
    return fetchMock.mock.calls.map(([input, init]) => ({
        url: String(input),
        method: init?.method,
        body: typeof init?.body === 'string' ? (JSON.parse(init.body) as unknown) : init?.body,
    }));
}

async function catchError(promise: Promise<unknown>): Promise<unknown> {
    return promise.then(
        () => null,
        (error: unknown) => error,
    );
}

describe('fetchUser', () => {
    it('GET /api/user の data を返す', async () => {
        const fetchMock = stubFetch(() => jsonResponse(200, { data: user }));

        await expect(authApi.fetchUser()).resolves.toEqual(user);

        expect(sentRequests(fetchMock)).toEqual([{ url: '/api/user', method: 'GET', body: undefined }]);
    });

    it('未ログイン（401）は null を返し、onUnauthenticated を呼ばない', async () => {
        stubFetch(unauthenticated);
        const handler = vi.fn();
        onUnauthenticated(handler);

        await expect(authApi.fetchUser()).resolves.toBeNull();

        expect(handler).not.toHaveBeenCalled();
    });

    it('401 以外の失敗はそのまま投げる', async () => {
        stubFetch(() => jsonResponse(500, { code: 'server_error', message: 'Server Error' }));

        const caught = await catchError(authApi.fetchUser());

        expect(isApiError(caught) && caught.code).toBe('server_error');
    });

    // email_verified_at は verified の判定（isVerified・router guard）に使うため、欠落や型違いも弾く。
    it.each([
        ['id が文字列', { ...user, id: '1' }],
        ['email_verified_at が無い', { id: 1, name: 'Test', email: 'test@example.com' }],
        ['email_verified_at が真偽値', { ...user, email_verified_at: true }],
    ])('契約外の形の user（%s）は unexpected_response にする', async (_, data) => {
        stubFetch(() => jsonResponse(200, { data }));

        const caught = await catchError(authApi.fetchUser());

        expect(isApiError(caught) && caught.code).toBe('unexpected_response');
    });
});

describe.each([
    {
        name: 'login',
        call: () => authApi.login({ email: 'test@example.com', password: 'password', remember: true }),
        url: '/api/auth/login',
        body: { email: 'test@example.com', password: 'password', remember: true },
    },
    {
        name: 'register',
        call: () =>
            authApi.register({ name: 'Test', email: 'test@example.com', password: 'password', password_confirmation: 'password' }),
        url: '/api/auth/register',
        body: { name: 'Test', email: 'test@example.com', password: 'password', password_confirmation: 'password' },
    },
])('$name', ({ call, url, body }) => {
    it('入力を POST し、user と redirect_to を返す', async () => {
        const response = { data: user, meta: { redirect_to: '/dashboard' } };
        const fetchMock = stubFetch(() => jsonResponse(200, response));

        await expect(call()).resolves.toEqual(response);

        expect(sentRequests(fetchMock)).toEqual([{ url, method: 'POST', body }]);
    });

    it('meta.redirect_to が無い response は unexpected_response にする', async () => {
        stubFetch(() => jsonResponse(200, { data: user, meta: {} }));

        const caught = await catchError(call());

        expect(isApiError(caught) && caught.code).toBe('unexpected_response');
    });

    it('422 は field ごとの errors を持つ ApiError として投げる', async () => {
        stubFetch(() =>
            jsonResponse(422, { code: 'validation_failed', message: '入力内容を確認してください。', errors: { email: ['不正です。'] } }),
        );

        const caught = await catchError(call());

        expect(isApiError(caught) && caught.errors).toEqual({ email: ['不正です。'] });
    });
});

describe.each([
    {
        name: 'sendPasswordResetLink',
        call: () => authApi.sendPasswordResetLink({ email: 'test@example.com' }),
        url: '/api/auth/forgot-password',
        body: { email: 'test@example.com' },
    },
    {
        name: 'resetPassword',
        call: () =>
            authApi.resetPassword({
                token: 'reset-token',
                email: 'test@example.com',
                password: 'password',
                password_confirmation: 'password',
            }),
        url: '/api/auth/reset-password',
        body: { token: 'reset-token', email: 'test@example.com', password: 'password', password_confirmation: 'password' },
    },
    {
        name: 'sendVerificationNotification',
        call: () => authApi.sendVerificationNotification(),
        url: '/api/auth/email/verification-notification',
        body: undefined,
    },
])('$name', ({ call, url, body }) => {
    it('POST し、code と message を返す', async () => {
        const response = { code: 'ok', message: '送信しました。' };
        const fetchMock = stubFetch(() => jsonResponse(200, response));

        await expect(call()).resolves.toEqual(response);

        expect(sentRequests(fetchMock)).toEqual([{ url, method: 'POST', body }]);
    });

    it('message の無い response は unexpected_response にする', async () => {
        stubFetch(() => jsonResponse(200, { code: 'ok' }));

        const caught = await catchError(call());

        expect(isApiError(caught) && caught.code).toBe('unexpected_response');
    });
});

describe('confirmPassword', () => {
    it('password を POST し、204 で完了する', async () => {
        const fetchMock = stubFetch(() => new Response(null, { status: 204 }));

        await expect(authApi.confirmPassword({ password: 'password' })).resolves.toBeUndefined();

        expect(sentRequests(fetchMock)).toEqual([
            { url: '/api/auth/confirm-password', method: 'POST', body: { password: 'password' } },
        ]);
    });

    it('誤った password の 422 は投げる', async () => {
        stubFetch(() =>
            jsonResponse(422, { code: 'validation_failed', message: '入力内容を確認してください。', errors: { password: ['違います。'] } }),
        );

        const caught = await catchError(authApi.confirmPassword({ password: 'wrong' }));

        expect(isApiError(caught) && caught.errors).toEqual({ password: ['違います。'] });
    });
});

describe('logout', () => {
    it('POST /api/auth/logout が 204 なら完了する', async () => {
        const fetchMock = stubFetch(() => new Response(null, { status: 204 }));

        await expect(authApi.logout()).resolves.toBeUndefined();

        expect(sentRequests(fetchMock)).toEqual([{ url: '/api/auth/logout', method: 'POST', body: undefined }]);
    });

    it('session が既に切れていた 401 は logout 済みとして扱い、onUnauthenticated を呼ばない', async () => {
        const fetchMock = stubFetch(unauthenticated);
        const handler = vi.fn();
        onUnauthenticated(handler);

        await expect(authApi.logout()).resolves.toBeUndefined();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(handler).not.toHaveBeenCalled();
    });

    it.each([
        ['network error', () => Promise.reject(new TypeError('Failed to fetch')), 'network_error'],
        ['500', () => Promise.resolve(jsonResponse(500, { code: 'server_error', message: 'Server Error' })), 'server_error'],
    ])('401 以外の失敗（%s）はそのまま投げる', async (_, respond, code) => {
        stubFetch(respond);

        const caught = await catchError(authApi.logout());

        expect(isApiError(caught) && caught.code).toBe(code);
    });
});
