import { flushPromises } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import * as authApi from '@/api/auth';
import { ApiError } from '@/api/client';
import { useAuthStore } from '@/stores/auth';
import RegisterView from '@/views/auth/RegisterView.vue';
import { mountAt, user, validationError } from './helpers';

vi.mock('@/api/auth', () => ({ register: vi.fn() }));

async function fill(wrapper: Awaited<ReturnType<typeof mountAt>>['wrapper']): Promise<void> {
    await wrapper.get('#name').setValue('Test');
    await wrapper.get('#email').setValue('test@example.com');
    await wrapper.get('#password').setValue('Passw0rd!');
    await wrapper.get('#password_confirmation').setValue('Passw0rd!');
}

describe('RegisterView', () => {
    it('入力を register API に送り、user を store に入れて redirect_to へ移動する', async () => {
        vi.mocked(authApi.register).mockResolvedValue({ data: user, meta: { redirect_to: '/dashboard' } });
        const { router, wrapper } = await mountAt(RegisterView, '/register');

        await fill(wrapper);
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(authApi.register).toHaveBeenCalledWith({
            name: 'Test',
            email: 'test@example.com',
            password: 'Passw0rd!',
            password_confirmation: 'Passw0rd!',
        });
        expect(useAuthStore().user.value).toEqual(user);
        expect(router.currentRoute.value.fullPath).toBe('/dashboard');
    });

    it('422 は field ごとに表示し、password 系だけ空に戻す', async () => {
        vi.mocked(authApi.register).mockRejectedValue(
            validationError({ email: ['このメールアドレスは既に使用されています。'], password: ['短すぎます。'] }),
        );
        const { router, wrapper } = await mountAt(RegisterView, '/register');

        await fill(wrapper);
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(wrapper.get('#email-error').text()).toBe('このメールアドレスは既に使用されています。');
        expect(wrapper.get('#password-error').text()).toBe('短すぎます。');
        expect(wrapper.get('#name-error').isVisible()).toBe(false);
        expect(wrapper.get<HTMLInputElement>('#name').element.value).toBe('Test');
        expect(wrapper.get<HTMLInputElement>('#email').element.value).toBe('test@example.com');
        expect(wrapper.get<HTMLInputElement>('#password').element.value).toBe('');
        expect(wrapper.get<HTMLInputElement>('#password_confirmation').element.value).toBe('');
        expect(router.currentRoute.value.name).toBe('register');
    });

    it('422 以外の失敗は alert で表示する', async () => {
        vi.mocked(authApi.register).mockRejectedValue(new ApiError(429, 'rate_limited', 'しばらくしてから再試行してください。'));
        const { wrapper } = await mountAt(RegisterView, '/register');

        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toBe('しばらくしてから再試行してください。');
    });

    it('login への link を持つ', async () => {
        const { wrapper } = await mountAt(RegisterView, '/register');

        const link = wrapper.findAll('a').find((a) => a.text() === 'Already registered?');

        expect(link?.attributes('href')).toBe('/login');
    });
});
