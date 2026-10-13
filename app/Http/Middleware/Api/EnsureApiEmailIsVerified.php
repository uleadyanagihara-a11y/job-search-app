<?php

namespace App\Http\Middleware\Api;

use App\Support\Api\ApiErrorResponse;
use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `api.verified` middleware alias (docs/inertia-removal-migration.md §5.5.1).
 *
 * `auth:sanctum` と併用する前提だが、route に `auth:sanctum` を付け忘れても未ログインの
 * request を素通ししないよう、user が無い場合は 401 を返す（標準 `verified` も user 無しは拒否）。
 * そのため未ログインでも閲覧できる route には付けない。verification の有無で表示を変える
 * だけの route は controller / Policy 側で `hasVerifiedEmail()` を判定する。
 */
class EnsureApiEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return ApiErrorResponse::make(
                'unauthenticated',
                '認証が必要です。',
                Response::HTTP_UNAUTHORIZED,
            );
        }

        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            return ApiErrorResponse::make(
                'email_unverified',
                'メールアドレスの確認が必要です。',
                Response::HTTP_FORBIDDEN,
            );
        }

        return $next($request);
    }
}
