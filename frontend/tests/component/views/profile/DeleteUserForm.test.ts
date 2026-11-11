import { flushPromises } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/api/client';
import * as profileApi from '@/api/profile';
import { useAuthStore } from '@/stores/auth';
import DeleteUserForm from '@/views/profile/DeleteUserForm.vue';
import { mountAt, user, validationError } from '../auth/helpers';

vi.mock('@/api/profile', () => ({ deleteAccount: vi.fn() }));

// jsdom は showModal/close を実装していないため、open 属性の切替だけ再現する（tests/component/components/Modal.test.ts と同じ）。
// ブラウザでの dialog の動作は e2e/profile.spec.ts で確認する。
HTMLDialogElement.prototype.showModal = function (this: HTMLDialogElement) {
    this.open = true;
};
HTMLDialogElement.prototype.close = function (this: HTMLDialogElement) {
    this.open = false;
};

const PASSWORD = '#delete_user_password';

async function openModal() {
    useAuthStore().setUser(user);
    const mounted = await mountAt(DeleteUserForm, '/profile');

    await mounted.wrapper.get('section > button').trigger('click');
    await flushPromises();

    return mounted;
}

describe('DeleteUserForm', () => {
    it('Delete Account で確認 dialog を開き、password 欄に focus する', async () => {
        const { wrapper } = await openModal();

        expect(wrapper.get('dialog').element.open).toBe(true);
        expect(wrapper.get('dialog').attributes('aria-labelledby')).toBe('delete-user-title');
        expect(document.activeElement).toBe(wrapper.get(PASSWORD).element);
    });

    it('password を送り、auth store を破棄して login へ replace する', async () => {
        vi.mocked(profileApi.deleteAccount).mockResolvedValue();
        const { router, wrapper } = await openModal();
        const replace = vi.spyOn(router, 'replace');

        await wrapper.get(PASSWORD).setValue('secret');
        await wrapper.get('dialog form').trigger('submit');
        await flushPromises();

        expect(profileApi.deleteAccount).toHaveBeenCalledWith({ password: 'secret' });
        expect(useAuthStore().user.value).toBeNull();
        expect(replace).toHaveBeenCalledWith({ name: 'login' });
        expect(router.currentRoute.value.name).toBe('login');
    });

    it('422 は password の下に表示し、password を消して focus したまま dialog に残る', async () => {
        vi.mocked(profileApi.deleteAccount).mockRejectedValue(
            validationError({ password: ['パスワードが正しくありません。'] }),
        );
        const { router, wrapper } = await openModal();

        await wrapper.get(PASSWORD).setValue('wrong');
        await wrapper.get('dialog form').trigger('submit');
        await flushPromises();

        expect(wrapper.get(`${PASSWORD}-error`).text()).toBe('パスワードが正しくありません。');
        expect(wrapper.get(PASSWORD).attributes('aria-invalid')).toBe('true');
        expect((wrapper.get(PASSWORD).element as HTMLInputElement).value).toBe('');
        expect(document.activeElement).toBe(wrapper.get(PASSWORD).element);
        expect(wrapper.get('dialog').element.open).toBe(true);
        expect(useAuthStore().user.value).toEqual(user);
        expect(router.currentRoute.value.name).toBe('profile');
    });

    it('422 以外の失敗は dialog 内に alert で表示する', async () => {
        vi.mocked(profileApi.deleteAccount).mockRejectedValue(new ApiError(429, 'rate_limited', '試行回数が多すぎます。'));
        const { wrapper } = await openModal();

        await wrapper.get('dialog form').trigger('submit');
        await flushPromises();

        expect(wrapper.get('dialog [role="alert"]').text()).toBe('試行回数が多すぎます。');
    });

    it('Cancel で閉じると error と入力を消し、API は呼ばない', async () => {
        vi.mocked(profileApi.deleteAccount).mockRejectedValue(
            validationError({ password: ['パスワードが正しくありません。'] }),
        );
        const { wrapper } = await openModal();

        await wrapper.get('dialog form').trigger('submit');
        await flushPromises();
        vi.mocked(profileApi.deleteAccount).mockClear();

        await wrapper.get(PASSWORD).setValue('typed');
        await wrapper.get('dialog button[type="button"]').trigger('click');

        expect(wrapper.get(`${PASSWORD}-error`).text()).toBe('');
        expect((wrapper.get(PASSWORD).element as HTMLInputElement).value).toBe('');
        expect(profileApi.deleteAccount).not.toHaveBeenCalled();
    });
});
