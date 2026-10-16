import type { RouteLocationNormalized, RouteLocationRaw } from 'vue-router';
import { useAuthStore } from '@/stores/auth';

/**
 * 旧 Laravel の `guest` / `auth` / `verified` middleware に相当する画面遷移の判定。
 * API 側でも同じ判定をするため、ここは表示の振り分けだけを担う。
 */
export async function authGuard(to: RouteLocationNormalized): Promise<boolean | RouteLocationRaw> {
    const auth = useAuthStore();

    try {
        await auth.ensureLoaded();
    } catch {
        // 認証状態が分からない間は public 画面だけ表示する。App.vue が loadError を表示する。
        return to.meta.access === 'public';
    }

    const user = auth.user.value;

    if (to.meta.access === 'guest' && user !== null) {
        return { name: 'dashboard' };
    }

    if (to.meta.access === 'auth' && user === null) {
        return { name: 'login', query: { redirect: to.fullPath } };
    }

    if (to.meta.verified && !auth.isVerified.value) {
        return { name: 'verification.notice' };
    }

    if (to.name === 'verification.notice' && auth.isVerified.value) {
        return { name: 'dashboard' };
    }

    return true;
}
