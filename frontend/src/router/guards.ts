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
 * 旧 Laravel の `guest` / `auth` / `verified` middleware に相当する画面遷移の判定と、`meta.roles` による振り分け。
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

    // 所属企業の停止で logout された直後は、どの画面へ行こうとしても停止の旨を出す login へ送る
    // （docs/domain-api-contract.md M2.2）。起動時の GET /api/user で分かった場合も、ここで送る。
    if (auth.takeCompanySuspended() && !(to.name === 'login' && to.query.company_suspended === '1')) {
        return { name: 'login', query: { company_suspended: '1' } };
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

    // ロールが合わない画面は dashboard へ送る（表示の振り分けだけ。データは API の Policy が守る）。
    // アプリ内の push から来ても、入れない画面を履歴に残さないよう replace にする（docs/domain-api-contract.md M2.6）。
    if (user !== null && to.meta.roles !== undefined && !to.meta.roles.includes(user.role)) {
        return { name: 'dashboard', replace: true };
    }

    // メール認証 callback の失敗（`?error=`。§5.4）は認証済みでも表示する。
    // 認証済みの user が別 user のリンクを開いた（user-mismatch）場合などに、黙って dashboard へ送らないため。
    if (to.name === 'verification.notice' && auth.isVerified.value && to.query.error === undefined) {
        return { name: 'dashboard' };
    }

    return true;
}
