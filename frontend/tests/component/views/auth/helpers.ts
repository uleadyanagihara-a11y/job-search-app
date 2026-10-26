import { mount } from '@vue/test-utils';
import type { Component } from 'vue';
import { createMemoryHistory, createRouter, type RouteLocationRaw } from 'vue-router';
import { ApiError } from '@/api/client';
import type { User } from '@/types/api';

// guest auth 画面の component test 共通の準備。view 自体は mount で直接描画し、
// 遷移先は route 名だけ解決できればよいので stub にする（guard は tests/router で検証済み）。

export const user: User = { id: 1, name: 'Test', email: 'test@example.com', email_verified_at: null };

const Stub = { render: () => null };

export function createTestRouter() {
    return createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', name: 'home', component: Stub },
            { path: '/login', name: 'login', component: Stub },
            { path: '/register', name: 'register', component: Stub },
            { path: '/forgot-password', name: 'password.request', component: Stub },
            { path: '/reset-password/:token', name: 'password.reset', component: Stub },
            { path: '/dashboard', name: 'dashboard', component: Stub },
            { path: '/profile', name: 'profile', component: Stub },
        ],
    });
}

export async function mountAt(view: Component, location: RouteLocationRaw) {
    const router = createTestRouter();
    await router.push(location);

    const wrapper = mount(view, { global: { plugins: [router] }, attachTo: document.body });

    return { router, wrapper };
}

export function validationError(errors: Record<string, string[]>): ApiError {
    return new ApiError(422, 'validation_failed', '入力内容を確認してください。', errors);
}

