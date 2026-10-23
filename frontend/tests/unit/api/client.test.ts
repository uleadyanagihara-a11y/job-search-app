import { describe, expect, it, vi } from 'vitest';
import { apiRequest, isApiError, onUnauthenticated } from '@/api/client';

function jsonResponse(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

function requestedUrls(fetchMock: ReturnType<typeof vi.fn<typeof fetch>>): string[] {
    return fetchMock.mock.calls.map(([input]) => String(input));
}

async function catchApiError(promise: Promise<unknown>) {
    const caught = await promise.then(
        () => null,
        (error: unknown) => error,
    );

    if (!isApiError(caught)) {
        throw new Error(`ApiError を期待したが ${String(caught)} だった`);
    }

    return caught;
}

describe('apiRequest', () => {
    it('XSRF-TOKEN が無い mutation は先に CSRF Cookie を取得し、X-XSRF-TOKEN を付けて送る', async () => {
        const fetchMock = vi.fn<typeof fetch>(async (input) => {
            if (input === '/sanctum/csrf-cookie') {
                document.cookie = 'XSRF-TOKEN=token%3D; path=/';
                return new Response(null, { status: 204 });
            }

            return new Response(null, { status: 204 });
        });
        vi.stubGlobal('fetch', fetchMock);

        await expect(apiRequest('POST', '/auth/logout')).resolves.toBeNull();

        expect(requestedUrls(fetchMock)).toEqual(['/sanctum/csrf-cookie', '/api/auth/logout']);
        expect(fetchMock.mock.calls[1]?.[1]?.headers).toMatchObject({ 'X-XSRF-TOKEN': 'token=' });
    });

    it('419 は CSRF Cookie を取り直して 1 度だけ再送する', async () => {
        document.cookie = 'XSRF-TOKEN=stale; path=/';
        const fetchMock = vi
            .fn<typeof fetch>()
            .mockResolvedValueOnce(jsonResponse(419, { code: 'csrf_token_mismatch', message: 'expired' }))
            .mockResolvedValueOnce(new Response(null, { status: 204 }))
            .mockResolvedValueOnce(jsonResponse(200, { ok: true }));
        vi.stubGlobal('fetch', fetchMock);

        await expect(apiRequest('POST', '/profile')).resolves.toEqual({ ok: true });

        expect(requestedUrls(fetchMock)).toEqual(['/api/profile', '/sanctum/csrf-cookie', '/api/profile']);
    });

    it('422 は field ごとの errors を持つ ApiError にする', async () => {
        document.cookie = 'XSRF-TOKEN=token; path=/';
        vi.stubGlobal(
            'fetch',
            vi.fn<typeof fetch>().mockResolvedValue(
                jsonResponse(422, {
                    code: 'validation_failed',
                    message: '入力内容を確認してください。',
                    errors: { email: ['メールアドレスは必須です。'] },
                }),
            ),
        );

        const error = await catchApiError(apiRequest('POST', '/auth/login', { body: {} }));

        expect(error.status).toBe(422);
        expect(error.code).toBe('validation_failed');
        expect(error.errors).toEqual({ email: ['メールアドレスは必須です。'] });
    });

    it('401 は onUnauthenticated の handler を呼ぶ。allowUnauthenticated なら呼ばない', async () => {
        const handler = vi.fn();
        onUnauthenticated(handler);
        vi.stubGlobal(
            'fetch',
            vi.fn<typeof fetch>(async () => jsonResponse(401, { code: 'unauthenticated', message: '認証が必要です。' })),
        );

        await catchApiError(apiRequest('GET', '/user', { allowUnauthenticated: true }));
        expect(handler).not.toHaveBeenCalled();

        const error = await catchApiError(apiRequest('GET', '/profile'));
        expect(error.code).toBe('unauthenticated');
        expect(handler).toHaveBeenCalledOnce();
    });

    it('JSON でない失敗（Nginx の 502 等）は unexpected_response にする', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn<typeof fetch>().mockResolvedValue(
                new Response('<html>502 Bad Gateway</html>', {
                    status: 502,
                    headers: { 'Content-Type': 'text/html' },
                }),
            ),
        );

        const error = await catchApiError(apiRequest('GET', '/user'));

        expect(error.status).toBe(502);
        expect(error.code).toBe('unexpected_response');
    });

    it('fetch 自体の失敗は network_error にする', async () => {
        vi.stubGlobal('fetch', vi.fn<typeof fetch>().mockRejectedValue(new TypeError('Failed to fetch')));

        const error = await catchApiError(apiRequest('GET', '/user'));

        expect(error.status).toBe(0);
        expect(error.code).toBe('network_error');
    });
});
