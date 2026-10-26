import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';
import { authGuard } from '@/router/guards';
import ForgotPasswordView from '@/views/auth/ForgotPasswordView.vue';
import LoginView from '@/views/auth/LoginView.vue';
import RegisterView from '@/views/auth/RegisterView.vue';
import ResetPasswordView from '@/views/auth/ResetPasswordView.vue';
import NotFoundView from '@/views/NotFoundView.vue';
import PlaceholderView from '@/views/PlaceholderView.vue';

declare module 'vue-router' {
    interface RouteMeta {
        title: string;
        /**
         * public: 誰でも / guest: 未ログインのみ / auth: ログイン必須。
         * 省略時は auth（default deny）。判定は routeAccess() を通す。
         */
        access?: 'public' | 'guest' | 'auth';
        /** メール認証済みのみ（旧 `verified` middleware） */
        verified?: boolean;
    }
}

const APP_NAME = 'Job Search App';

// 未移植の画面は Phase 4 で移植するまで PlaceholderView で受ける。
// route 名は旧 Laravel の route 名に揃える。
// アプリの情報はログイン後にだけ見せる。公開してよいのは認証画面と 404 だけで、
// それ以外は access を省略して（= auth）guard に login へ送らせる。
export const routes: RouteRecordRaw[] = [
    // 公開の Welcome 画面は持たない。未ログインなら dashboard の guard で login へ送られる。
    { path: '/', name: 'home', redirect: { name: 'dashboard' } },
    { path: '/login', name: 'login', component: LoginView, meta: { title: 'Log in', access: 'guest' } },
    { path: '/register', name: 'register', component: RegisterView, meta: { title: 'Register', access: 'guest' } },
    {
        path: '/forgot-password',
        name: 'password.request',
        component: ForgotPasswordView,
        meta: { title: 'Forgot Password', access: 'guest' },
    },
    {
        path: '/reset-password/:token',
        name: 'password.reset',
        component: ResetPasswordView,
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
