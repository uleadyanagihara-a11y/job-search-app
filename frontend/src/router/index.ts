import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';
import { authGuard } from '@/router/guards';
import NotFoundView from '@/views/NotFoundView.vue';
import PlaceholderView from '@/views/PlaceholderView.vue';

declare module 'vue-router' {
    interface RouteMeta {
        title: string;
        /** public: 誰でも / guest: 未ログインのみ / auth: ログイン必須 */
        access: 'public' | 'guest' | 'auth';
        /** メール認証済みのみ（旧 `verified` middleware） */
        verified?: boolean;
    }
}

const APP_NAME = 'Job Search App';

// 画面は Phase 4 で移植するまで PlaceholderView で受ける。
// route 名は旧 Laravel の route 名に揃える。
export const routes: RouteRecordRaw[] = [
    { path: '/', name: 'home', component: PlaceholderView, meta: { title: 'Welcome', access: 'public' } },
    { path: '/login', name: 'login', component: PlaceholderView, meta: { title: 'Log in', access: 'guest' } },
    { path: '/register', name: 'register', component: PlaceholderView, meta: { title: 'Register', access: 'guest' } },
    {
        path: '/forgot-password',
        name: 'password.request',
        component: PlaceholderView,
        meta: { title: 'Forgot Password', access: 'guest' },
    },
    {
        path: '/reset-password/:token',
        name: 'password.reset',
        component: PlaceholderView,
        meta: { title: 'Reset Password', access: 'guest' },
    },
    {
        path: '/verify-email',
        name: 'verification.notice',
        component: PlaceholderView,
        meta: { title: 'Email Verification', access: 'auth' },
    },
    {
        path: '/confirm-password',
        name: 'password.confirm',
        component: PlaceholderView,
        meta: { title: 'Confirm Password', access: 'auth' },
    },
    {
        path: '/dashboard',
        name: 'dashboard',
        component: PlaceholderView,
        meta: { title: 'Dashboard', access: 'auth', verified: true },
    },
    { path: '/profile', name: 'profile', component: PlaceholderView, meta: { title: 'Profile', access: 'auth' } },
    {
        path: '/:pathMatch(.*)*',
        name: 'not-found',
        component: NotFoundView,
        meta: { title: 'Not Found', access: 'public' },
    },
];

export const router = createRouter({
    history: createWebHistory(import.meta.env.BASE_URL),
    routes,
});

router.beforeEach(authGuard);

router.afterEach((to) => {
    document.title = `${to.meta.title} - ${APP_NAME}`;
});
