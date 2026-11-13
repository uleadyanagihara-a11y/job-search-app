import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';
import { authGuard } from '@/router/guards';
import ConfirmPasswordView from '@/views/auth/ConfirmPasswordView.vue';
import ForgotPasswordView from '@/views/auth/ForgotPasswordView.vue';
import LoginView from '@/views/auth/LoginView.vue';
import RegisterView from '@/views/auth/RegisterView.vue';
import ResetPasswordView from '@/views/auth/ResetPasswordView.vue';
import VerifyEmailView from '@/views/auth/VerifyEmailView.vue';
import DashboardView from '@/views/DashboardView.vue';
import NotFoundView from '@/views/NotFoundView.vue';
import ProfileView from '@/views/profile/ProfileView.vue';

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

// route 名は旧 Laravel の route 名に揃える。
// アプリの情報はログイン後にだけ見せる。公開してよいのは認証画面と 404 だけで、
// それ以外は access を省略して（= auth）guard に login へ送らせる。
export const routes: RouteRecordRaw[] = [
    // 公開の Welcome 画面は持たない。未ログインなら dashboard の guard で login へ送られる。
    { path: '/', name: 'home', redirect: { name: 'dashboard' } },
    { path: '/login', name: 'login', component: LoginView, meta: { title: 'ログイン', access: 'guest' } },
    { path: '/register', name: 'register', component: RegisterView, meta: { title: 'アカウント登録', access: 'guest' } },
    {
        path: '/forgot-password',
        name: 'password.request',
        component: ForgotPasswordView,
        meta: { title: 'パスワードの再設定', access: 'guest' },
    },
    {
        path: '/reset-password/:token',
        name: 'password.reset',
        component: ResetPasswordView,
        meta: { title: 'パスワードの再設定', access: 'guest' },
    },
    {
        path: '/verify-email',
        name: 'verification.notice',
        component: VerifyEmailView,
        meta: { title: 'メールアドレスの確認', access: 'auth' },
    },
    {
        path: '/confirm-password',
        name: 'password.confirm',
        component: ConfirmPasswordView,
        meta: { title: 'パスワードの確認', access: 'auth' },
    },
    {
        path: '/dashboard',
        name: 'dashboard',
        component: DashboardView,
        meta: { title: 'ダッシュボード', access: 'auth', verified: true },
    },
    { path: '/profile', name: 'profile', component: ProfileView, meta: { title: 'プロフィール', access: 'auth' } },
    {
        path: '/:pathMatch(.*)*',
        name: 'not-found',
        component: NotFoundView,
        meta: { title: 'ページが見つかりません', access: 'public' },
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
