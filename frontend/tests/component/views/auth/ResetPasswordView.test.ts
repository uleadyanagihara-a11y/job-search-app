import { flushPromises } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import * as authApi from '@/api/auth';
import ResetPasswordView from '@/views/auth/ResetPasswordView.vue';
import { mountAt, validationError } from './helpers';

vi.mock('@/api/auth', () => ({ resetPassword: vi.fn() }));

const resetUrl = '/reset-password/token123?email=a%40example.com';

describe('ResetPasswordView', () => {
    it('route の email を入力欄に入れる', async () => {
        const { wrapper } = await mountAt(ResetPasswordView, resetUrl);

        expect(wrapper.get<HTMLInputElement>('#email').element.value).toBe('a@example.com');
    });

    it('route の token と入力を送り、成功したら message を持たせて login へ replace する', async () => {
        vi.mocked(authApi.resetPassword).mockResolvedValue({
            code: 'password_reset',
            message: 'パスワードをリセットしました。',
        });
        const { router, wrapper } = await mountAt(ResetPasswordView, resetUrl);
        const replace = vi.spyOn(router, 'replace');

        await wrapper.get('#password').setValue('N3w-Passw0rd!');
        await wrapper.get('#password_confirmation').setValue('N3w-Passw0rd!');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(authApi.resetPassword).toHaveBeenCalledWith({
            token: 'token123',
            email: 'a@example.com',
            password: 'N3w-Passw0rd!',
            password_confirmation: 'N3w-Passw0rd!',
        });
        // token/email を含まない /login へ、reset URL の history entry を残さない replace で移動する
        expect(replace).toHaveBeenCalledOnce();
        expect(router.currentRoute.value.fullPath).toBe('/login');
        expect(router.options.history.state.status).toBe('パスワードをリセットしました。');
    });

    it('無効な token の 422（errors.token）は form の上に表示し、password を空に戻す', async () => {
        vi.mocked(authApi.resetPassword).mockRejectedValue(
            validationError({ token: ['このパスワードリセットトークンは無効です。'] }),
        );
        const { router, wrapper } = await mountAt(ResetPasswordView, resetUrl);

        await wrapper.get('#password').setValue('N3w-Passw0rd!');
        await wrapper.get('#password_confirmation').setValue('N3w-Passw0rd!');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(wrapper.get('#token-error').text()).toBe('このパスワードリセットトークンは無効です。');
        expect(wrapper.get('#token-error').isVisible()).toBe(true);
        expect(wrapper.get<HTMLInputElement>('#password').element.value).toBe('');
        expect(router.currentRoute.value.name).toBe('password.reset');
    });

    it('password の 422 は password の下に表示する', async () => {
        vi.mocked(authApi.resetPassword).mockRejectedValue(validationError({ password: ['確認用と一致しません。'] }));
        const { wrapper } = await mountAt(ResetPasswordView, resetUrl);

        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(wrapper.get('#password-error').text()).toBe('確認用と一致しません。');
        expect(wrapper.get('#token-error').isVisible()).toBe(false);
    });
});
