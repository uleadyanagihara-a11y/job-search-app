import { flushPromises } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/api/client';
import * as profileApi from '@/api/profile';
import { useAuthStore } from '@/stores/auth';
import ProfileView from '@/views/profile/ProfileView.vue';
import { mountAt, user } from '../auth/helpers';

vi.mock('@/api/profile', () => ({
    fetchProfile: vi.fn(),
    updateProfile: vi.fn(),
    updatePassword: vi.fn(),
    deleteAccount: vi.fn(),
}));
vi.mock('@/api/auth', () => ({ sendVerificationNotification: vi.fn(), fetchUser: vi.fn(), logout: vi.fn() }));

const verifiedUser = { ...user, email_verified_at: '2026-10-01T00:00:00+09:00' };

describe('ProfileView', () => {
    it('GET /api/profile の user で auth store と form を初期化し、3 つの section を表示する', async () => {
        useAuthStore().setUser({ ...verifiedUser, name: 'Stale' });
        vi.mocked(profileApi.fetchProfile).mockResolvedValue(verifiedUser);

        const { wrapper } = await mountAt(ProfileView, '/profile');

        expect(wrapper.text()).toContain('読み込み中…');

        await flushPromises();

        expect(useAuthStore().user.value).toEqual(verifiedUser);
        expect(wrapper.get('h1').text()).toBe('Profile');
        expect(wrapper.findAll('h2').map((h) => h.text())).toEqual(
            expect.arrayContaining(['Profile Information', 'Update Password', 'Delete Account']),
        );
        expect((wrapper.get('#name').element as HTMLInputElement).value).toBe('Test');
        expect((wrapper.get('#email').element as HTMLInputElement).value).toBe('test@example.com');
        expect(wrapper.text()).not.toContain('Your email address is unverified.');
    });

    it('email を変えて保存すると、auth store と未認証の表示を更新する', async () => {
        useAuthStore().setUser(verifiedUser);
        vi.mocked(profileApi.fetchProfile).mockResolvedValue(verifiedUser);
        const updated = { ...verifiedUser, email: 'new@example.com', email_verified_at: null };
        vi.mocked(profileApi.updateProfile).mockResolvedValue(updated);

        const { wrapper } = await mountAt(ProfileView, '/profile');
        await flushPromises();

        await wrapper.get('#email').setValue('new@example.com');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(useAuthStore().user.value).toEqual(updated);
        expect(wrapper.text()).toContain('Your email address is unverified.');
    });

    it('読み込みに失敗したら alert を出し、再読み込みで form を表示する', async () => {
        useAuthStore().setUser(verifiedUser);
        vi.mocked(profileApi.fetchProfile)
            .mockRejectedValueOnce(new ApiError(0, 'network_error', 'サーバーに接続できませんでした。'))
            .mockResolvedValueOnce(verifiedUser);

        const { wrapper } = await mountAt(ProfileView, '/profile');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toBe('プロフィールを読み込めませんでした。サーバーに接続できませんでした。');
        expect(wrapper.find('form').exists()).toBe(false);

        await wrapper.get('main button').trigger('click');
        await flushPromises();

        expect(wrapper.find('[role="alert"]').exists()).toBe(false);
        expect(wrapper.find('#name').exists()).toBe(true);
    });
});
