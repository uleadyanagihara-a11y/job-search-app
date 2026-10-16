import type { LocationQueryValue, Router } from 'vue-router';

// login 後の intended URL（docs/inertia-removal-migration.md §5.4, §8）。
// SPA 内の intended は `?redirect=` に保持し、Laravel の callback へ戻る分は login API の
// `meta.redirect_to` が返す（Laravel 側で `/auth/email/verify/{id}/{hash}` に限定済み）。

const VERIFICATION_CALLBACK_PREFIX = '/auth/email/verify/';

/**
 * 同一 origin の SPA path だけを受け付ける。`//evil.example` や `/\evil.example` のような
 * protocol-relative URL と、Laravel が処理する path は弾く。
 */
export function safeRedirectPath(value: LocationQueryValue | LocationQueryValue[] | undefined): string | null {
    if (typeof value !== 'string' || !value.startsWith('/') || value.startsWith('//') || value.startsWith('/\\')) {
        return null;
    }

    if (value.startsWith(VERIFICATION_CALLBACK_PREFIX) || value.startsWith('/api/')) {
        return null;
    }

    return value;
}

/**
 * login/register 成功後の遷移。メール認証 callback は Laravel が処理するため、SPA の router ではなく
 * `window.location.assign()` で再実行する。
 */
export async function navigateAfterLogin(
    router: Router,
    redirectTo: string,
    intended: LocationQueryValue | LocationQueryValue[] | undefined,
): Promise<void> {
    if (redirectTo.startsWith(VERIFICATION_CALLBACK_PREFIX)) {
        window.location.assign(redirectTo);
        return;
    }

    await router.replace(safeRedirectPath(intended) ?? redirectTo);
}
