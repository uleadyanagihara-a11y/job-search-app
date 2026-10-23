import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter, isNavigationFailure } from 'vue-router';
import type { User } from '@/types/api';

// auth store は module scope の状態を持つため、test ごとに module を読み直す。
vi.mock('@/api/auth', () => ({ fetchUser: vi.fn() }));

const verifiedUser: User = { id: 1, name: 'Test', email: 'test@example.com', email_verified_at: '2026-10-01T00:00:00+09:00' };
const unverifiedUser: User = { ...verifiedUser, email_verified_at: null };

async function createTestRouter(user: User | null | Error) {
    const authApi = await import('@/api/auth');

    if (user instanceof Error) {
        vi.mocked(authApi.fetchUser).mockRejectedValue(user);
    } else {
        vi.mocked(authApi.fetchUser).mockResolvedValue(user);
    }

    const { routes } = await import('@/router');
    const { authGuard } = await import('@/router/guards');
    const router = createRouter({ history: createMemoryHistory(), routes });
    router.beforeEach(authGuard);

    return router;
}

beforeEach(() => {
    vi.resetModules();
});

describe('routeAccess', () => {
    it('meta.access を省略した route は auth として扱う（default deny）', async () => {
        const { routeAccess } = await import('@/router/guards');

        expect(routeAccess({ meta: { title: 'x' } })).toBe('auth');
        expect(routeAccess({ meta: { title: 'x', access: 'public' } })).toBe('public');
    });
});

describe('authGuard', () => {
    describe('未ログイン', () => {
        it('/ は dashboard を経由して login へ送り、redirect に元の path を残す', async () => {
            const router = await createTestRouter(null);

            await router.push('/');

            expect(router.currentRoute.value.name).toBe('login');
            expect(router.currentRoute.value.query.redirect).toBe('/dashboard');
        });

        it('auth の route は query 付きの fullPath を redirect に残す', async () => {
            const router = await createTestRouter(null);

            await router.push('/profile?tab=password');

            expect(router.currentRoute.value.name).toBe('login');
            expect(router.currentRoute.value.query.redirect).toBe('/profile?tab=password');
        });

        it('guest の route はそのまま表示する', async () => {
            const router = await createTestRouter(null);

            await router.push('/reset-password/token123?email=a%40example.com');

            expect(router.currentRoute.value.name).toBe('password.reset');
            expect(router.currentRoute.value.params.token).toBe('token123');
            expect(router.currentRoute.value.query.email).toBe('a@example.com');
        });

        it('存在しない path は not-found を表示する', async () => {
            const router = await createTestRouter(null);

            await router.push('/no/such/page');

            expect(router.currentRoute.value.name).toBe('not-found');
        });
    });

    describe('ログイン済み', () => {
        it('guest の route は dashboard へ送る', async () => {
            const router = await createTestRouter(verifiedUser);

            await router.push('/login');

            expect(router.currentRoute.value.name).toBe('dashboard');
        });

        it('メール未認証なら verified の route は verification.notice へ送る', async () => {
            const router = await createTestRouter(unverifiedUser);

            await router.push('/dashboard');

            expect(router.currentRoute.value.name).toBe('verification.notice');
        });

        it('メール認証済みなら verification.notice は dashboard へ送る', async () => {
            const router = await createTestRouter(verifiedUser);

            await router.push('/verify-email');

            expect(router.currentRoute.value.name).toBe('dashboard');
        });
    });

    describe('認証状態を取得できない', () => {
        it('public の route だけ表示し、それ以外は遷移を止める', async () => {
            const router = await createTestRouter(new Error('network'));

            expect(isNavigationFailure(await router.push('/login'))).toBe(true);

            await router.push('/no/such/page');
            expect(router.currentRoute.value.name).toBe('not-found');
        });
    });
});
