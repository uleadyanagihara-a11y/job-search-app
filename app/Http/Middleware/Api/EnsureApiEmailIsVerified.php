<?php

namespace App\Http\Middleware\Api;

use App\Support\Api\ApiErrorResponse;
use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `api.verified` middleware alias (docs/inertia-removal-migration.md §5.5.1).
 * `auth:sanctum` の後段で使う前提のため、未認証時の401判定はここでは行わない。
 */
class EnsureApiEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

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
