import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import NotFoundView from '@/views/NotFoundView.vue';

describe('NotFoundView', () => {
    it('404 と home へのリンクを表示する', async () => {
        const router = createRouter({
            history: createMemoryHistory(),
            routes: [
                { path: '/', name: 'home', component: { render: () => null } },
                { path: '/:pathMatch(.*)*', name: 'not-found', component: NotFoundView },
            ],
        });
        await router.push('/missing');

        const wrapper = mount(NotFoundView, { global: { plugins: [router] } });

        expect(wrapper.get('h1').text()).toBe('404');
        expect(wrapper.get('a').attributes('href')).toBe('/');
    });
});
