import { describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import { navigationItems } from '@/layouts/navigation';
import { routes } from '@/router';

// nav の項目の roles と、同じ名前の route の meta.roles は別々に書いている（.claude/rules/frontend.md）。
// 片方だけ直すと「nav に出るのに押すと dashboard へ戻される」「開ける画面なのに nav に出ない」になるため、本物の routes と照らす。
vi.mock('@/api/auth', () => ({ fetchUser: vi.fn() }));

describe('navigationItems', () => {
    const router = createRouter({ history: createMemoryHistory(), routes });

    it.each(navigationItems.map((item) => [item.name, item] as const))('%s は route があり、roles が meta.roles と一致する', (_, item) => {
        expect(router.hasRoute(item.name)).toBe(true);

        const route = router.resolve({ name: item.name });

        expect(route.meta.roles).toEqual(item.roles);
    });
});
