import { flushPromises } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import * as authApi from '@/api/auth';
import { ApiError } from '@/api/client';
import ConfirmPasswordView from '@/views/auth/ConfirmPasswordView.vue';
import { mountAt, validationError } from './helpers';

vi.mock('@/api/auth', () => ({ confirmPassword: vi.fn() }));

describe('ConfirmPasswordView', () => {
    it('password を送り、?redirect= の画面へ replace で戻る', async () => {
        vi.mocked(authApi.confirmPassword).mockResolvedValue();
        const { router, wrapper } = await mountAt(ConfirmPasswordView, '/confirm-password?redirect=/profile%3Ftab%3Ddelete');
        const replace = vi.spyOn(router, 'replace');

        await wrapper.get('#password').setValue('secret');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(authApi.confirmPassword).toHaveBeenCalledWith({ password: 'secret' });
        expect(replace).toHaveBeenCalledWith('/profile?tab=delete');
        expect(router.currentRoute.value.fullPath).toBe('/profile?tab=delete');
    });

    it.each([
        ['redirect 無し', '/confirm-password'],
        ['外部 URL', '/confirm-password?redirect=//evil.example'],
    ])('%s なら dashboard へ進む', async (_, location) => {
        vi.mocked(authApi.confirmPassword).mockResolvedValue();
        const { router, wrapper } = await mountAt(ConfirmPasswordView, location);

        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(router.currentRoute.value.name).toBe('dashboard');
    });

    it('422 は password の下に表示し、入力した password を消す', async () => {
        vi.mocked(authApi.confirmPassword).mockRejectedValue(
            validationError({ password: ['パスワードが正しくありません。'] }),
        );
        const { router, wrapper } = await mountAt(ConfirmPasswordView, '/confirm-password?redirect=/profile');

        await wrapper.get('#password').setValue('wrong');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(wrapper.get('#password-error').text()).toBe('パスワードが正しくありません。');
        expect(wrapper.get('#password').attributes('aria-invalid')).toBe('true');
        expect(wrapper.get('#password').attributes('aria-describedby')).toBe('password-error');
        expect((wrapper.get('#password').element as HTMLInputElement).value).toBe('');
        expect(router.currentRoute.value.name).toBe('password.confirm');
    });

    it('422 以外の失敗は form の上に alert で表示する', async () => {
        vi.mocked(authApi.confirmPassword).mockRejectedValue(
            new ApiError(429, 'rate_limited', '試行回数が多すぎます。'),
        );
        const { wrapper } = await mountAt(ConfirmPasswordView, '/confirm-password');

        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toBe('試行回数が多すぎます。');
        expect(wrapper.find('#password-error').text()).toBe('');
    });
});
