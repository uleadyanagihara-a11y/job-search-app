import type { RouteLocation, RouteLocationNormalized, RouteLocationRaw, RouteMeta } from 'vue-router';
import { useAuthStore } from '@/stores/auth';

/**
 * `meta.access` を省略した route はログイン必須として扱う（default deny）。
 * 公開する画面だけ明示的に `public` / `guest` を書く。
 */
export function routeAccess(route: Pick<RouteLocation, 'meta'>): NonNullable<RouteMeta['access']> {
    return route.meta.access ?? 'auth';
}

/**
 * 旧 Laravel の `guest` / `auth` / `verified` middleware に相当する画面遷移の判定。
 * API 側でも同じ判定をするため、ここは表示の振り分けだけを担う。
 */
export async function authGuard(to: RouteLocationNormalized): Promise<boolean | RouteLocationRaw> {
    const auth = useAuthStore();
    const access = routeAccess(to);

    try {
        await auth.ensureLoaded();
    } catch {
        // 認証状態が分からない間は public 画面だけ表示する。App.vue が loadError を表示する。
        return access === 'public';
    }

    const user = auth.user.value;

    if (access === 'guest' && user !== null) {
        return { name: 'dashboard' };
    }

    if (access === 'auth' && user === null) {
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
