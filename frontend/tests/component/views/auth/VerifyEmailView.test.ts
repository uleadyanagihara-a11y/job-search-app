import { flushPromises } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import * as authApi from '@/api/auth';
import { ApiError } from '@/api/client';
import { useAuthStore } from '@/stores/auth';
import VerifyEmailView from '@/views/auth/VerifyEmailView.vue';
import { mountAt, user } from './helpers';

vi.mock('@/api/auth', () => ({ sendVerificationNotification: vi.fn(), fetchUser: vi.fn(), logout: vi.fn() }));

const verifiedUser = { ...user, email_verified_at: '2026-10-01T00:00:00+09:00' };

async function mountAsUnverified(location = '/verify-email') {
    useAuthStore().setUser(user);

    return mountAt(VerifyEmailView, location);
}

function resendButton(wrapper: Awaited<ReturnType<typeof mountAt>>['wrapper']) {
    return wrapper.get('button[type="submit"]');
}

describe('VerifyEmailView', () => {
    it('確認メールを再送し、API の message を同じ画面に表示する', async () => {
        vi.mocked(authApi.sendVerificationNotification).mockResolvedValue({
            code: 'verification_link_sent',
            message: '確認メールを送信しました。',
        });
        const { router, wrapper } = await mountAsUnverified();

        expect(wrapper.find('[role="status"]').exists()).toBe(false);

        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(authApi.sendVerificationNotification).toHaveBeenCalledOnce();
        expect(wrapper.get('[role="status"]').text()).toBe('確認メールを送信しました。');
        expect(router.currentRoute.value.name).toBe('verification.notice');
    });

    it('送信中は再送 button を無効にする', async () => {
        let resolve!: (value: { code: string; message: string }) => void;
        vi.mocked(authApi.sendVerificationNotification).mockReturnValue(new Promise((r) => (resolve = r)));
        const { wrapper } = await mountAsUnverified();

        await wrapper.get('form').trigger('submit');
        expect(resendButton(wrapper).attributes('disabled')).toBeDefined();

        resolve({ code: 'verification_link_sent', message: '確認メールを送信しました。' });
        await flushPromises();
        expect(resendButton(wrapper).attributes('disabled')).toBeUndefined();
    });

    it('429 などの失敗は form の上に alert で表示する', async () => {
        vi.mocked(authApi.sendVerificationNotification).mockRejectedValue(
            new ApiError(429, 'rate_limited', '試行回数が多すぎます。'),
        );
        const { wrapper } = await mountAsUnverified();

        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toBe('試行回数が多すぎます。');
    });

    it('409（別 tab で認証済み）は user を取り直し、認証済みなら dashboard へ進む', async () => {
        vi.mocked(authApi.sendVerificationNotification).mockRejectedValue(
            new ApiError(409, 'conflict', '現在の状態ではこの操作を実行できません。'),
        );
        vi.mocked(authApi.fetchUser).mockResolvedValue(verifiedUser);
        const { router, wrapper } = await mountAsUnverified();

        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(authApi.fetchUser).toHaveBeenCalledOnce();
        expect(useAuthStore().isVerified.value).toBe(true);
        expect(router.currentRoute.value.name).toBe('dashboard');
    });

    it.each([
        ['invalid-or-expired', '確認リンクが無効か、有効期限が切れています。確認メールを再送してください。'],
        ['user-mismatch', 'この確認リンクは、ログイン中のアカウントのものではありません。'],
        ['too-many-requests', '確認リンクを開く回数が多すぎます。しばらくしてから再度お試しください。'],
        ['unknown', 'メールアドレスを確認できませんでした。'],
    ])('callback の ?error=%s を表示し、再送に成功したら消す', async (code, message) => {
        vi.mocked(authApi.sendVerificationNotification).mockResolvedValue({
            code: 'verification_link_sent',
            message: '確認メールを送信しました。',
        });
        const { wrapper } = await mountAsUnverified(`/verify-email?error=${code}`);

        expect(wrapper.get('[role="alert"]').text()).toBe(message);

        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(wrapper.find('[role="alert"]').exists()).toBe(false);
    });

    it('認証済み（guard が ?error= の時だけ通す）なら error と dashboard への link だけを出す', async () => {
        useAuthStore().setUser(verifiedUser);
        const { wrapper } = await mountAt(VerifyEmailView, '/verify-email?error=user-mismatch');

        expect(wrapper.get('[role="alert"]').text()).toBe('この確認リンクは、ログイン中のアカウントのものではありません。');
        expect(wrapper.find('form').exists()).toBe(false);
        expect(wrapper.get('a[href="/dashboard"]').text()).toBe('Go to Dashboard');
    });

    it('Log Out は API で logout して login へ進む', async () => {
        vi.mocked(authApi.logout).mockResolvedValue();
        const { router, wrapper } = await mountAsUnverified();

        await wrapper.get('button[type="button"]').trigger('click');
        await flushPromises();

        expect(authApi.logout).toHaveBeenCalledOnce();
        expect(useAuthStore().user.value).toBeNull();
        expect(router.currentRoute.value.name).toBe('login');
        expect(authApi.sendVerificationNotification).not.toHaveBeenCalled();
    });

    it('logout の失敗は alert を出してこの画面に残る', async () => {
        vi.mocked(authApi.logout).mockRejectedValue(new ApiError(0, 'network_error', 'サーバーに接続できませんでした。'));
        const { router, wrapper } = await mountAsUnverified();

        await wrapper.get('button[type="button"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toBe('ログアウトできませんでした。時間をおいて再度お試しください。');
        expect(router.currentRoute.value.name).toBe('verification.notice');
    });
});
