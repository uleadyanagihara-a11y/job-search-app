import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter, type Router } from 'vue-router';
import * as authApi from '@/api/auth';
import { ApiError } from '@/api/client';
import AuthenticatedLayout from '@/layouts/AuthenticatedLayout.vue';
import { useAuthStore } from '@/stores/auth';
import type { User } from '@/types/api';

vi.mock('@/api/auth', () => ({ logout: vi.fn() }));

const user: User = { id: 1, name: 'Test User', email: 'test@example.com', email_verified_at: '2026-10-01T00:00:00+09:00' };

let router: Router;

beforeEach(async () => {
    const Empty = { render: () => null };

    router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/login', name: 'login', component: Empty },
            { path: '/dashboard', name: 'dashboard', component: Empty },
            { path: '/profile', name: 'profile', component: Empty },
        ],
    });
    await router.push('/dashboard');

    useAuthStore().setUser(user);
});

function mountLayout() {
    return mount(AuthenticatedLayout, {
        slots: { header: '<h1>Heading</h1>', default: '<p>Content</p>' },
        global: { plugins: [router] },
        attachTo: document.body,
    });
}

function logoutButtons(wrapper: ReturnType<typeof mountLayout>) {
    return wrapper.findAll('button').filter((button) => button.text() === 'Log Out');
}

describe('AuthenticatedLayout', () => {
    it('header と content の slot、store の user の name/email を表示する', () => {
        const wrapper = mountLayout();

        expect(wrapper.get('header h1').text()).toBe('Heading');
        expect(wrapper.get('main').text()).toBe('Content');
        expect(wrapper.get('[data-user-menu]').text()).toBe('Test User');
        expect(wrapper.get('#responsive-navigation').text()).toContain('test@example.com');
    });

    it('header slot が無ければ header を描画しない', () => {
        const wrapper = mount(AuthenticatedLayout, { global: { plugins: [router] } });

        expect(wrapper.find('header').exists()).toBe(false);
    });

    it('現在の route の Dashboard link に aria-current を付け、Profile への link を持つ', () => {
        const wrapper = mountLayout();
        const dashboardLinks = wrapper.findAll('a').filter((a) => a.text() === 'Dashboard');
        const profileLinks = wrapper.findAll('a').filter((a) => a.text() === 'Profile');

        expect(dashboardLinks).toHaveLength(2);
        expect(dashboardLinks.every((a) => a.attributes('aria-current') === 'page')).toBe(true);
        expect(profileLinks.map((a) => a.attributes('href'))).toEqual(['/profile', '/profile']);
    });

    it.each(['/dashboard', '/profile'])('%s で desktop と responsive menu に同じ link が同じ順・active 状態で並ぶ', async (path) => {
        await router.push(path);
        const wrapper = mountLayout();
        const anchors = wrapper.findAll('a').filter((a) => a.attributes('aria-label') !== 'Job Search App');
        const describeLinks = (links: typeof anchors) =>
            links.map((a) => ({ text: a.text(), href: a.attributes('href'), current: a.attributes('aria-current') }));

        const responsive = anchors.filter((a) => a.element.closest('#responsive-navigation') !== null);
        const desktop = anchors.filter((a) => a.element.closest('#responsive-navigation') === null);

        expect(desktop.length).toBeGreaterThan(0);
        expect(describeLinks(responsive)).toEqual(describeLinks(desktop));
    });

    it('hamburger で responsive menu を開閉し、画面遷移で閉じる', async () => {
        const wrapper = mountLayout();
        const toggle = wrapper.get('button[aria-controls="responsive-navigation"]');

        expect(toggle.attributes('aria-expanded')).toBe('false');
        expect(wrapper.get('#responsive-navigation').classes()).toContain('hidden');

        await toggle.trigger('click');

        expect(toggle.attributes('aria-expanded')).toBe('true');
        expect(wrapper.get('#responsive-navigation').classes()).toContain('block');

        await router.push('/profile');
        await flushPromises();

        expect(toggle.attributes('aria-expanded')).toBe('false');
    });

    it.each([0, 1])('Log Out（%i 番目: dropdown / responsive）で logout API を呼び、store を破棄して login へ移動する', async (index) => {
        vi.mocked(authApi.logout).mockResolvedValue();
        const wrapper = mountLayout();

        await logoutButtons(wrapper)[index]!.trigger('click');
        await flushPromises();

        expect(authApi.logout).toHaveBeenCalledTimes(1);
        expect(useAuthStore().user.value).toBeNull();
        expect(router.currentRoute.value.fullPath).toBe('/login');
    });

    it('logout 中は Log Out を無効にし、二重に送らない', async () => {
        vi.mocked(authApi.logout).mockReturnValue(new Promise(() => {}));
        const wrapper = mountLayout();

        await logoutButtons(wrapper)[0]!.trigger('click');
        await logoutButtons(wrapper)[1]!.trigger('click');

        expect(authApi.logout).toHaveBeenCalledTimes(1);
        expect(logoutButtons(wrapper).every((button) => button.attributes('disabled') !== undefined)).toBe(true);
    });

    it('logout に失敗したら alert を出し、ログイン状態と画面を保つ', async () => {
        vi.mocked(authApi.logout).mockRejectedValue(new ApiError(0, 'network_error', 'サーバーに接続できませんでした。'));
        const wrapper = mountLayout();

        await logoutButtons(wrapper)[0]!.trigger('click');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toContain('ログアウトできませんでした');
        expect(useAuthStore().user.value).toEqual(user);
        expect(router.currentRoute.value.name).toBe('dashboard');
        expect(logoutButtons(wrapper)[0]!.attributes('disabled')).toBeUndefined();
    });
});
