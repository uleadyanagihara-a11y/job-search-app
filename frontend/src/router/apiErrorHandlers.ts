import { START_LOCATION, type Router } from 'vue-router';
import { onCompanySuspended, onPasswordConfirmationRequired, onUnauthenticated } from '@/api/client';
import { routeAccess } from '@/router/guards';
import { useAuthStore } from '@/stores/auth';

/**
 * API の失敗のうち、どの画面で起きても同じ遷移をするもの（401・403 company_suspended・423）の処理を登録する。
 * main.ts から呼ぶ。test で router を差し替えられるよう、router を引数で受ける。
 */
export function registerApiErrorHandlers(router: Router): void {
    // session 切れ等で API が 401 を返したら、auth store を破棄して login へ送る。
    onUnauthenticated(() => {
        useAuthStore().clear();

        const current = router.currentRoute.value;

        if (routeAccess(current) === 'auth') {
            void router.replace({ name: 'login', query: { redirect: current.fullPath } });
        }
    });

    // ログイン中に所属企業が停止され、API が 403 company_suspended を返したら（server は logout 済み）、
    // auth store を破棄して login へ送り、停止された旨を出す（docs/domain-api-contract.md M2.2）。
    // 遷移先は router guard が決める（store の印を読んで `?company_suspended=1` の login へ送る）。
    // - login 画面の login API の失敗は、画面の alert が出すので印を付けず遷移もしない。
    // - 起動時（最初の遷移の途中）の GET /api/user で起きたら、ここでは遷移しない。最初の遷移の guard が印を読む。
    //   ここでも遷移すると 2 つの遷移が競い、guard の `?redirect=` 付きの login が勝って停止の旨が消える。
    onCompanySuspended(() => {
        const current = router.currentRoute.value;

        if (current.name === 'login') {
            useAuthStore().clear();

            return;
        }

        useAuthStore().clear({ companySuspended: true });

        if (current !== START_LOCATION) {
            void router.replace({ name: 'login', query: { company_suspended: '1' } });
        }
    });

    // password 再確認が必要な API が 423 を返したら、confirm-password へ送り、確認後に今の画面へ戻す。
    // form の入力は残らないため、戻った画面で操作をやり直してもらう（旧 RequirePassword の redirect と同じ）。
    onPasswordConfirmationRequired(() => {
        const current = router.currentRoute.value;

        if (current.name !== 'password.confirm') {
            void router.push({ name: 'password.confirm', query: { redirect: current.fullPath } });
        }
    });
}
