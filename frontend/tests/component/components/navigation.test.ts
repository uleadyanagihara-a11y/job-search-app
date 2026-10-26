import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';
import { createMemoryHistory, createRouter, type Router } from 'vue-router';
import Dropdown from '@/components/Dropdown.vue';
import DropdownLink from '@/components/DropdownLink.vue';
import NavLink from '@/components/NavLink.vue';
import ResponsiveNavLink from '@/components/ResponsiveNavLink.vue';
import GuestLayout from '@/layouts/GuestLayout.vue';

let router: Router;

beforeEach(async () => {
    const Empty = { render: () => null };

    router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', name: 'home', component: Empty },
            { path: '/dashboard', name: 'dashboard', component: Empty },
            { path: '/profile', name: 'profile', component: Empty },
        ],
    });
    await router.push('/');
});

describe('NavLink', () => {
    it('to を href にし、active なら aria-current を付ける', () => {
        const active = mount(NavLink, {
            props: { to: { name: 'dashboard' }, active: true },
            slots: { default: 'Dashboard' },
            global: { plugins: [router] },
        });
        const inactive = mount(NavLink, {
            props: { to: { name: 'profile' } },
            global: { plugins: [router] },
        });

        expect(active.get('a').attributes()).toMatchObject({ href: '/dashboard', 'aria-current': 'page' });
        expect(active.get('a').classes()).toContain('border-indigo-400');
        expect(inactive.get('a').attributes('aria-current')).toBeUndefined();
        expect(inactive.get('a').classes()).toContain('border-transparent');
    });

    it('click で router 遷移する', async () => {
        const wrapper = mount(NavLink, {
            props: { to: { name: 'profile' } },
            global: { plugins: [router] },
        });

        await wrapper.get('a').trigger('click');
        await flushPromises();

        expect(router.currentRoute.value.name).toBe('profile');
    });
});

describe.each([
    ['DropdownLink', DropdownLink],
    ['ResponsiveNavLink', ResponsiveNavLink],
])('%s', (_, component) => {
    it('to があれば link、無ければ type=button の button にする', async () => {
        const link = mount(component, { props: { to: { name: 'profile' } }, global: { plugins: [router] } });
        let clicked = 0;
        const button = mount(component, {
            attrs: { onClick: () => clicked++ },
            slots: { default: 'Log Out' },
            global: { plugins: [router] },
        });

        expect(link.get('a').attributes('href')).toBe('/profile');
        expect(button.find('a').exists()).toBe(false);
        expect(button.get('button').attributes('type')).toBe('button');

        await button.get('button').trigger('click');

        expect(clicked).toBe(1);
    });
});

describe('Dropdown', () => {
    function mountDropdown() {
        return mount(Dropdown, {
            slots: {
                trigger: '<button type="button">Menu</button>',
                content: '<a href="/profile">Profile</a>',
            },
            attachTo: document.body,
        });
    }

    it('trigger の click で開閉する', async () => {
        const wrapper = mountDropdown();
        const content = wrapper.get('[data-dropdown-content]');

        expect(content.isVisible()).toBe(false);

        await wrapper.get('button').trigger('click');
        expect(content.isVisible()).toBe(true);

        await wrapper.get('button').trigger('click');
        expect(content.isVisible()).toBe(false);
    });

    it('Escape・overlay の click・項目の click で閉じる', async () => {
        const wrapper = mountDropdown();
        const content = wrapper.get('[data-dropdown-content]');

        await wrapper.get('button').trigger('click');
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await wrapper.vm.$nextTick();
        expect(content.isVisible()).toBe(false);

        await wrapper.get('button').trigger('click');
        await wrapper.get('[data-dropdown-overlay]').trigger('click');
        expect(content.isVisible()).toBe(false);

        await wrapper.get('button').trigger('click');
        await content.get('a').trigger('click');
        expect(content.isVisible()).toBe(false);
    });
});

describe('GuestLayout', () => {
    it('logo を home へのリンクにし、slot を card 内に表示する', () => {
        const wrapper = mount(GuestLayout, {
            slots: { default: '<form>login form</form>' },
            global: { plugins: [router] },
        });

        expect(wrapper.get('a').attributes()).toMatchObject({ href: '/', 'aria-label': 'Job Search App' });
        expect(wrapper.find('svg').exists()).toBe(true);
        expect(wrapper.text()).toContain('login form');
    });
});
