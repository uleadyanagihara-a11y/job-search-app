import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import * as authApi from '@/api/auth';
import { ApiError } from '@/api/client';
import * as profileApi from '@/api/profile';
import { useAuthStore } from '@/stores/auth';
import type { User } from '@/types/api';
import UpdateProfileInformationForm from '@/views/profile/UpdateProfileInformationForm.vue';
import { user, validationError } from '../auth/helpers';

vi.mock('@/api/profile', () => ({ updateProfile: vi.fn() }));
vi.mock('@/api/auth', () => ({ sendVerificationNotification: vi.fn(), fetchUser: vi.fn() }));

const verifiedUser: User = { ...user, email_verified_at: '2026-10-01T00:00:00+09:00' };

function mountForm(current: User = verifiedUser) {
    useAuthStore().setUser(current);

    return mount(UpdateProfileInformationForm, { props: { user: current }, attachTo: document.body });
}

describe('UpdateProfileInformationForm', () => {
    it('name・email を送り、更新済みの user で auth store を更新して「保存しました。」を表示する', async () => {
        const updated = { ...verifiedUser, name: 'Renamed' };
        vi.mocked(profileApi.updateProfile).mockResolvedValue(updated);
        const wrapper = mountForm();

        await wrapper.get('#name').setValue('Renamed');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(profileApi.updateProfile).toHaveBeenCalledWith({ name: 'Renamed', email: 'test@example.com' });
        expect(useAuthStore().user.value).toEqual(updated);
        expect(wrapper.get('[role="status"]').text()).toBe('保存しました。');
    });

    it('422 は各 field の下に表示し、入力は残す', async () => {
        vi.mocked(profileApi.updateProfile).mockRejectedValue(
            validationError({ name: ['名前は必須です。'], email: ['このメールアドレスは既に使われています。'] }),
        );
        const wrapper = mountForm();

        await wrapper.get('#email').setValue('taken@example.com');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(wrapper.get('#name-error').text()).toBe('名前は必須です。');
        expect(wrapper.get('#email-error').text()).toBe('このメールアドレスは既に使われています。');
        expect(wrapper.get('#email').attributes('aria-invalid')).toBe('true');
        expect(wrapper.get('#email').attributes('aria-describedby')).toBe('email-error');
        expect((wrapper.get('#email').element as HTMLInputElement).value).toBe('taken@example.com');
        expect(useAuthStore().user.value).toEqual(verifiedUser);
    });

    it('422 以外の失敗は form の上に alert で表示する', async () => {
        vi.mocked(profileApi.updateProfile).mockRejectedValue(
            new ApiError(0, 'network_error', 'サーバーに接続できませんでした。'),
        );
        const wrapper = mountForm();

        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toBe('サーバーに接続できませんでした。');
        expect(wrapper.find('[role="status"]').exists()).toBe(false);
    });

    it('認証済みなら確認メールの再送は出さない', () => {
        const wrapper = mountForm();

        expect(wrapper.text()).not.toContain('メールアドレスが確認されていません。');
    });

    it('未認証なら確認メールを再送し、API の message を表示する', async () => {
        vi.mocked(authApi.sendVerificationNotification).mockResolvedValue({
            code: 'verification_link_sent',
            message: '確認メールを送信しました。',
        });
        const wrapper = mountForm(user);

        await wrapper.get('button[type="button"]').trigger('click');
        await flushPromises();

        expect(authApi.sendVerificationNotification).toHaveBeenCalledOnce();
        expect(profileApi.updateProfile).not.toHaveBeenCalled();
        expect(wrapper.get('[role="status"]').text()).toBe('確認メールを送信しました。');
    });

    it('再送の 409（別 tab で認証済み）は user を取り直す', async () => {
        vi.mocked(authApi.sendVerificationNotification).mockRejectedValue(
            new ApiError(409, 'conflict', '現在の状態ではこの操作を実行できません。'),
        );
        vi.mocked(authApi.fetchUser).mockResolvedValue(verifiedUser);
        const wrapper = mountForm(user);

        await wrapper.get('button[type="button"]').trigger('click');
        await flushPromises();

        expect(authApi.fetchUser).toHaveBeenCalledOnce();
        expect(useAuthStore().isVerified.value).toBe(true);
        expect(wrapper.get('[role="alert"]').text()).toBe('現在の状態ではこの操作を実行できません。');
    });
});
