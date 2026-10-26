import { flushPromises } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import * as authApi from '@/api/auth';
import { ApiError } from '@/api/client';
import { useAuthStore } from '@/stores/auth';
import LoginView from '@/views/auth/LoginView.vue';
import { mountAt, user, validationError } from './helpers';

vi.mock('@/api/auth', () => ({ login: vi.fn() }));

describe('LoginView', () => {
    it('入力した email/password/remember で login API を呼び、user を store に入れて redirect_to へ移動する', async () => {
        vi.mocked(authApi.login).mockResolvedValue({ data: user, meta: { redirect_to: '/dashboard' } });
        const { router, wrapper } = await mountAt(LoginView, '/login');

        await wrapper.get('#email').setValue('test@example.com');
        await wrapper.get('#password').setValue('password');
        await wrapper.get('input[type="checkbox"]').setValue(true);
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(authApi.login).toHaveBeenCalledWith({ email: 'test@example.com', password: 'password', remember: true });
        expect(useAuthStore().user.value).toEqual(user);
        expect(router.currentRoute.value.fullPath).toBe('/dashboard');
    });

    it('?redirect= があれば SPA の intended path へ移動する', async () => {
        vi.mocked(authApi.login).mockResolvedValue({ data: user, meta: { redirect_to: '/dashboard' } });
        const { router, wrapper } = await mountAt(LoginView, '/login?redirect=/profile');

        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(router.currentRoute.value.fullPath).toBe('/profile');
    });

    it('422 は field ごとに表示し、password を空に戻す', async () => {
        vi.mocked(authApi.login).mockRejectedValue(validationError({ email: ['認証に失敗しました。'] }));
        const { router, wrapper } = await mountAt(LoginView, '/login');

        await wrapper.get('#email').setValue('test@example.com');
        await wrapper.get('#password').setValue('wrong');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(wrapper.get('#email-error').text()).toBe('認証に失敗しました。');
        expect(wrapper.get('#email').attributes('aria-describedby')).toBe('email-error');
        expect(wrapper.get<HTMLInputElement>('#email').element.value).toBe('test@example.com');
        expect(wrapper.get<HTMLInputElement>('#password').element.value).toBe('');
        expect(router.currentRoute.value.name).toBe('login');
    });

    it('422 以外の失敗は field error と分けて alert で表示する', async () => {
        vi.mocked(authApi.login).mockRejectedValue(new ApiError(0, 'network_error', 'サーバーに接続できませんでした。'));
        const { wrapper } = await mountAt(LoginView, '/login');

        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toBe('サーバーに接続できませんでした。');
        expect(wrapper.get('#email-error').isVisible()).toBe(false);
    });

    it('送信中は button を無効にする', async () => {
        vi.mocked(authApi.login).mockReturnValue(new Promise(() => {}));
        const { wrapper } = await mountAt(LoginView, '/login');

        await wrapper.get('form').trigger('submit');

        expect(wrapper.get('button[type="submit"]').element).toHaveProperty('disabled', true);
    });

    it('reset-password から history state で渡された message を表示する', async () => {
        const { wrapper } = await mountAt(LoginView, { name: 'login', state: { status: 'パスワードをリセットしました。' } });

        expect(wrapper.get('[role="status"]').text()).toBe('パスワードをリセットしました。');
    });

    it('?verification_required=1 ならメール確認のためのログインを促す', async () => {
        const { wrapper } = await mountAt(LoginView, '/login?verification_required=1');

        expect(wrapper.get('[role="status"]').text()).toContain('ログインしてください');
    });

    it('password 再設定と register への link を持つ', async () => {
        const { wrapper } = await mountAt(LoginView, '/login?redirect=/profile');
        const hrefs = wrapper.findAll('a').map((a) => a.attributes('href'));

        expect(hrefs).toContain('/forgot-password');
        expect(hrefs).toContain('/register?redirect=/profile');
    });
});
