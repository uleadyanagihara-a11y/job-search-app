import { flushPromises } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import * as authApi from '@/api/auth';
import ForgotPasswordView from '@/views/auth/ForgotPasswordView.vue';
import { mountAt, validationError } from './helpers';

vi.mock('@/api/auth', () => ({ sendPasswordResetLink: vi.fn() }));

describe('ForgotPasswordView', () => {
    it('email を送り、API の message を同じ画面に表示する', async () => {
        vi.mocked(authApi.sendPasswordResetLink).mockResolvedValue({
            code: 'password_reset_link_sent',
            message: 'パスワード再設定用のリンクを送信しました。',
        });
        const { router, wrapper } = await mountAt(ForgotPasswordView, '/forgot-password');

        await wrapper.get('#email').setValue('test@example.com');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(authApi.sendPasswordResetLink).toHaveBeenCalledWith({ email: 'test@example.com' });
        expect(wrapper.get('[role="status"]').text()).toBe('パスワード再設定用のリンクを送信しました。');
        expect(router.currentRoute.value.name).toBe('password.request');
    });

    it('422 は email の下に表示し、前回の message は消す', async () => {
        vi.mocked(authApi.sendPasswordResetLink)
            .mockResolvedValueOnce({ code: 'password_reset_link_sent', message: '送信しました。' })
            .mockRejectedValueOnce(validationError({ email: ['メールアドレスの形式が不正です。'] }));
        const { wrapper } = await mountAt(ForgotPasswordView, '/forgot-password');

        await wrapper.get('form').trigger('submit');
        await flushPromises();
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(wrapper.get('#email-error').text()).toBe('メールアドレスの形式が不正です。');
        expect(wrapper.find('[role="status"]').exists()).toBe(false);
    });
});
