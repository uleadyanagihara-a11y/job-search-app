import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import type { User } from '@/types/api';

// auth store と client の handler は module scope の状態を持つため、test ごとに module を読み直す。
// API は mock せず fetch だけを差し替え、client → handler → router の流れをそのまま通す。

const companyUser: User = {
    id: 1,
    name: 'Test',
    email: 'company@example.com',
    email_verified_at: '2026-10-01T00:00:00+09:00',
    role: 'company',
    company_id: 1,
};

function jsonResponse(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

const companySuspended = () => jsonResponse(403, { code: 'company_suspended', message: '停止中' });

/**
 * path ごとに response を返す fetch を差し替える。GET /api/user は `user` の値で答える。
 */
function stubFetch(user: () => Response, others: Record<string, () => Response> = {}): void {
    vi.stubGlobal(
        'fetch',
        vi.fn<typeof fetch>(async (input) => {
            const url = String(input);

            if (url === '/api/user') {
                return user();
            }

            if (url === '/sanctum/csrf-cookie') {
                return new Response(null, { status: 204 });
            }

            const handler = others[url];

            if (handler === undefined) {
                throw new Error(`想定外の request: ${url}`);
            }

            return handler();
        }),
    );
}

async function setUp() {
    const { routes } = await import('@/router');
    const { authGuard } = await import('@/router/guards');
    const { registerApiErrorHandlers } = await import('@/router/apiErrorHandlers');
    const { apiRequest } = await import('@/api/client');
    const { useAuthStore } = await import('@/stores/auth');

    const router = createRouter({ history: createMemoryHistory(), routes });
    router.beforeEach(authGuard);
    registerApiErrorHandlers(router);

    // handler の中の遷移は待たれない（void）ため、失敗を握りつぶしてから遷移が落ち着くのを待つ。
    const failingRequest = (...args: Parameters<typeof apiRequest>) => apiRequest(...args).catch(() => undefined);

    return { router, failingRequest, auth: useAuthStore() };
}

beforeEach(() => {
    vi.resetModules();
});

describe('403 company_suspended', () => {
    it('ログイン中の画面で起きたら、auth store を破棄して ?company_suspended=1 の login へ送る', async () => {
        stubFetch(() => jsonResponse(200, { data: companyUser }), { '/api/profile': companySuspended });
        const { router, failingRequest, auth } = await setUp();

        await router.push('/profile');
        expect(router.currentRoute.value.name).toBe('profile');

        await failingRequest('GET', '/profile');

        await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('login'));
        expect(router.currentRoute.value.query).toEqual({ company_suspended: '1' });
        expect(auth.user.value).toBeNull();
    });

    it('起動時の GET /api/user で起きたら、最初に開いた画面ではなく ?company_suspended=1 の login へ送る', async () => {
        stubFetch(companySuspended);
        const { router, auth } = await setUp();

        // handler は遷移せず印だけ付け、最初の遷移の guard が ?company_suspended=1 の login へ振り替える
        // （両方が遷移すると、guard の ?redirect= 付きの login が勝って停止の旨が消える）。
        await router.push('/profile');

        await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('login'));
        expect(router.currentRoute.value.query).toEqual({ company_suspended: '1' });
        expect(auth.user.value).toBeNull();
        expect(auth.loaded.value).toBe(true);
    });

    it('起動時に login を直接開いたときも ?company_suspended=1 を付ける', async () => {
        stubFetch(companySuspended);
        const { router } = await setUp();

        await router.push('/login');

        expect(router.currentRoute.value.name).toBe('login');
        expect(router.currentRoute.value.query).toEqual({ company_suspended: '1' });
    });

    it('login へ送るのは 1 度だけで、その後は他の画面へ移れる', async () => {
        stubFetch(companySuspended);
        const { router } = await setUp();

        await router.push('/profile');
        await vi.waitFor(() => expect(router.currentRoute.value.query).toEqual({ company_suspended: '1' }));

        await router.push('/register');

        expect(router.currentRoute.value.name).toBe('register');
    });

    it('login 画面の login API で起きたら遷移しない（画面の alert が出す）', async () => {
        stubFetch(() => jsonResponse(401, { code: 'unauthenticated', message: '認証が必要です。' }), {
            '/api/auth/login': companySuspended,
        });
        const { router, failingRequest } = await setUp();

        await router.push('/login');
        await failingRequest('POST', '/auth/login', { body: { email: 'company@example.com', password: 'password' } });

        expect(router.currentRoute.value.name).toBe('login');
        expect(router.currentRoute.value.query).toEqual({});

        // 印を付けていないため、他の画面へ移っても login へ戻されない。
        await router.push('/register');
        expect(router.currentRoute.value.name).toBe('register');
    });
});

describe('401', () => {
    it('ログインが必要な画面で起きたら、今の path を redirect に残して login へ送る', async () => {
        stubFetch(() => jsonResponse(200, { data: companyUser }), {
            '/api/profile': () => jsonResponse(401, { code: 'unauthenticated', message: '認証が必要です。' }),
        });
        const { router, failingRequest, auth } = await setUp();

        await router.push('/profile');
        await failingRequest('GET', '/profile');

        await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('login'));
        expect(router.currentRoute.value.query).toEqual({ redirect: '/profile' });
        expect(auth.user.value).toBeNull();
    });
});

describe('423 password_confirmation_required', () => {
    it('confirm-password へ送り、今の path を redirect に残す', async () => {
        stubFetch(() => jsonResponse(200, { data: companyUser }), {
            '/api/profile': () => jsonResponse(423, { code: 'password_confirmation_required', message: '確認が必要です。' }),
        });
        const { router, failingRequest } = await setUp();

        await router.push('/profile');
        await failingRequest('DELETE', '/profile');

        await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('password.confirm'));
        expect(router.currentRoute.value.query).toEqual({ redirect: '/profile' });
    });
});
