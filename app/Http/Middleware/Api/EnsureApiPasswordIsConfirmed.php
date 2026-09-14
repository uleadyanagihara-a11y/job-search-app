<?php

namespace App\Http\Middleware\Api;

use App\Support\Api\ApiErrorResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `api.password.confirm` middleware alias (docs/inertia-removal-migration.md §5.5.1).
 * 既存の`ConfirmablePasswordController`と同じsession値`auth.password_confirmed_at`を検証する。
 */
class EnsureApiPasswordIsConfirmed
{
    public function handle(Request $request, Closure $next): Response
    {
        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);

        if (time() - $confirmedAt > config('auth.password_timeout', 10800)) {
            return ApiErrorResponse::make(
                'password_confirmation_required',
                'パスワードの再確認が必要です。',
                423,
            );
        }

        return $next($request);
    }
}
