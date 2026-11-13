import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import DashboardView from '@/views/DashboardView.vue';

async function mountAt(path: string) {
    const Empty = { render: () => null };
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/dashboard', name: 'dashboard', component: Empty },
            { path: '/profile', name: 'profile', component: Empty },
            { path: '/login', name: 'login', component: Empty },
        ],
    });
    await router.push(path);
    useAuthStore().setUser({ id: 1, name: 'Test', email: 'test@example.com', email_verified_at: '2026-10-01T00:00:00+09:00' });

    return mount(DashboardView, { global: { plugins: [router] } });
}

describe('DashboardView', () => {
    it('AuthenticatedLayout の中に見出しと本文を表示する', async () => {
        const wrapper = await mountAt('/dashboard');

        expect(wrapper.get('header h1').text()).toBe('ダッシュボード');
        expect(wrapper.get('main').text()).toContain('ログインしています。');
        expect(wrapper.find('[role="status"]').exists()).toBe(false);
    });

    it('メール認証 callback からの ?verified=1 なら確認完了を表示する', async () => {
        const wrapper = await mountAt('/dashboard?verified=1');

        expect(wrapper.get('[role="status"]').text()).toBe('メールアドレスの確認が完了しました。');
    });
});
