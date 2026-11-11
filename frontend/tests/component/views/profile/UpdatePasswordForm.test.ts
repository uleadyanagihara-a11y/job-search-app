import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/api/client';
import * as profileApi from '@/api/profile';
import UpdatePasswordForm from '@/views/profile/UpdatePasswordForm.vue';
import { validationError } from '../auth/helpers';

vi.mock('@/api/profile', () => ({ updatePassword: vi.fn() }));

const CURRENT = '#update_password_current_password';
const NEW = '#update_password_password';
const CONFIRMATION = '#update_password_password_confirmation';

async function fillAndSubmit() {
    const wrapper = mount(UpdatePasswordForm, { attachTo: document.body });

    await wrapper.get(CURRENT).setValue('old-secret');
    await wrapper.get(NEW).setValue('new-secret');
    await wrapper.get(CONFIRMATION).setValue('new-secret');
    await wrapper.get('form').trigger('submit');
    await flushPromises();

    return wrapper;
}

function valueOf(wrapper: Awaited<ReturnType<typeof fillAndSubmit>>, selector: string): string {
    return (wrapper.get(selector).element as HTMLInputElement).value;
}

describe('UpdatePasswordForm', () => {
    it('3 つの欄を送り、成功したら全欄を消して Saved. を表示する', async () => {
        vi.mocked(profileApi.updatePassword).mockResolvedValue();

        const wrapper = await fillAndSubmit();

        expect(profileApi.updatePassword).toHaveBeenCalledWith({
            current_password: 'old-secret',
            password: 'new-secret',
            password_confirmation: 'new-secret',
        });
        expect([valueOf(wrapper, CURRENT), valueOf(wrapper, NEW), valueOf(wrapper, CONFIRMATION)]).toEqual(['', '', '']);
        expect(wrapper.get('[role="status"]').text()).toBe('Saved.');
    });

    it('current_password の 422 はその欄だけ消して focus する', async () => {
        vi.mocked(profileApi.updatePassword).mockRejectedValue(
            validationError({ current_password: ['パスワードが正しくありません。'] }),
        );

        const wrapper = await fillAndSubmit();

        expect(wrapper.get(`${CURRENT}-error`).text()).toBe('パスワードが正しくありません。');
        expect(wrapper.get(CURRENT).attributes('aria-invalid')).toBe('true');
        expect(valueOf(wrapper, CURRENT)).toBe('');
        expect(valueOf(wrapper, NEW)).toBe('new-secret');
        expect(document.activeElement).toBe(wrapper.get(CURRENT).element);
    });

    it('password の 422 は新しい password と確認を消して focus する', async () => {
        vi.mocked(profileApi.updatePassword).mockRejectedValue(
            validationError({ password: ['パスワードは 8 文字以上にしてください。'] }),
        );

        const wrapper = await fillAndSubmit();

        expect(wrapper.get(`${NEW}-error`).text()).toBe('パスワードは 8 文字以上にしてください。');
        expect(valueOf(wrapper, CURRENT)).toBe('old-secret');
        expect([valueOf(wrapper, NEW), valueOf(wrapper, CONFIRMATION)]).toEqual(['', '']);
        expect(document.activeElement).toBe(wrapper.get(NEW).element);
    });

    it('422 以外の失敗は alert を出し、入力を残す', async () => {
        vi.mocked(profileApi.updatePassword).mockRejectedValue(new ApiError(429, 'rate_limited', '試行回数が多すぎます。'));

        const wrapper = await fillAndSubmit();

        expect(wrapper.get('[role="alert"]').text()).toBe('試行回数が多すぎます。');
        expect(valueOf(wrapper, CURRENT)).toBe('old-secret');
        expect(wrapper.find('[role="status"]').exists()).toBe(false);
    });
});
