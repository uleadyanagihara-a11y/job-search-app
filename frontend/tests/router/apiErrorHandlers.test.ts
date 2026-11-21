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

describe('server の認証状態が auth store より新しい（docs/domain-api-contract.md M2.2）', () => {
    const unauthenticated = () => jsonResponse(401, { code: 'unauthenticated', message: '認証が必要です。' });

    it('guest の画面で 409 already_authenticated（別の tab でログイン済み）なら、user を取り直して dashboard へ送る', async () => {
        // 起動時は未ログイン。別の tab でログインした後の GET /api/user は user を返す
        let loggedIn = false;
        stubFetch(() => (loggedIn ? jsonResponse(200, { data: companyUser }) : unauthenticated()), {
            '/api/auth/login': () => jsonResponse(409, { code: 'already_authenticated', message: '既にログインしています。' }),
        });
        const { router, failingRequest, auth } = await setUp();

        await router.push('/login');
        expect(router.currentRoute.value.name).toBe('login');

        loggedIn = true;
        await failingRequest('POST', '/auth/login', { body: { email: 'company@example.com', password: 'password' } });

        await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('dashboard'));
        expect(auth.user.value).toEqual(companyUser);
    });

    it('メール確認が必要な画面で 403 email_unverified（別の tab でメールアドレスを変えた）なら、user を取り直して verification.notice へ送る', async () => {
        let verified = true;
        stubFetch(
            () => jsonResponse(200, { data: { ...companyUser, email_verified_at: verified ? companyUser.email_verified_at : null } }),
            { '/api/seeker-profile': () => jsonResponse(403, { code: 'email_unverified', message: 'メールアドレスの確認が必要です。' }) },
        );
        const { router, failingRequest, auth } = await setUp();

        await router.push('/dashboard');
        expect(router.currentRoute.value.name).toBe('dashboard');

        verified = false;
        await failingRequest('GET', '/seeker-profile');

        await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('verification.notice'));
        expect(auth.isVerified.value).toBe(false);
    });

    /** handler の force の replace まで終わるのを待つ（route 名が変わらない場合も、遷移の完了で待てるように）。 */
    function countNavigations(router: Awaited<ReturnType<typeof setUp>>['router']) {
        let count = 0;
        router.afterEach(() => {
            count++;
        });

        return () => count;
    }

    it('user を取り直しても状態が変わらなければ、今の画面のまま（path・query・hash も残す）', async () => {
        stubFetch(() => jsonResponse(200, { data: companyUser }), {
            '/api/seeker-profile': () => jsonResponse(403, { code: 'email_unverified', message: 'メールアドレスの確認が必要です。' }),
        });
        const { router, failingRequest } = await setUp();

        await router.push('/profile?tab=password#delete');
        const navigations = countNavigations(router);
        await failingRequest('GET', '/seeker-profile');

        await vi.waitFor(() => expect(navigations()).toBe(1));
        expect(router.currentRoute.value.fullPath).toBe('/profile?tab=password#delete');
    });

    it('取り直しの GET /api/user が 401 なら、今の path を redirect に残して login へ送る', async () => {
        let loggedIn = true;
        stubFetch(() => (loggedIn ? jsonResponse(200, { data: companyUser }) : unauthenticated()), {
            '/api/seeker-profile': () => jsonResponse(403, { code: 'email_unverified', message: 'メールアドレスの確認が必要です。' }),
        });
        const { router, failingRequest, auth } = await setUp();

        await router.push('/dashboard');
        loggedIn = false;
        await failingRequest('GET', '/seeker-profile');

        await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('login'));
        expect(router.currentRoute.value.query).toEqual({ redirect: '/dashboard' });
        expect(auth.user.value).toBeNull();
    });

    it('取り直しに失敗したら遷移しない（画面の error 表示に任せる）', async () => {
        let failing = false;
        stubFetch(() => (failing ? jsonResponse(500, { code: 'server_error', message: 'Server Error' }) : unauthenticated()), {
            '/api/auth/register': () => jsonResponse(409, { code: 'already_authenticated', message: '既にログインしています。' }),
        });
        const { router, failingRequest } = await setUp();

        await router.push('/register');
        const navigations = countNavigations(router);
        failing = true;
        await failingRequest('POST', '/auth/register', { body: {} });
        await vi.waitFor(() =>
            expect(vi.mocked(fetch).mock.calls.filter(([url]) => String(url) === '/api/user')).toHaveLength(2),
        );
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(navigations()).toBe(0);
        expect(router.currentRoute.value.name).toBe('register');
    });

    it('取り直しの GET /api/user が 403 company_suspended なら、遷移は company_suspended の処理に任せる', async () => {
        let suspended = false;
        stubFetch(() => (suspended ? companySuspended() : unauthenticated()), {
            '/api/auth/register': () => jsonResponse(409, { code: 'already_authenticated', message: '既にログインしています。' }),
        });
        const { router, failingRequest, auth } = await setUp();

        await router.push('/register');
        suspended = true;
        await failingRequest('POST', '/auth/register', { body: {} });

        await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('login'));
        expect(router.currentRoute.value.query).toEqual({ company_suspended: '1' });
        expect(auth.user.value).toBeNull();
    });
});
