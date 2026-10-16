<?php

namespace App\Http\Middleware\Api;

use App\Support\Api\ApiErrorResponse;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `api.session` middleware alias (docs/inertia-removal-migration.md §5.5.1).
 *
 * Sanctum は Referer/Origin が `sanctum.stateful` に一致する request にだけ session を開始する。
 * session を書き換える endpoint に session 無しで来ると、DB 更新や認証の後で
 * `$request->session()` が例外になり 500 になるため、FormRequest/controller より前で 400 を返す。
 * 付けた endpoint は session（Cookie）認証専用であり、token 認証では使えない。
 */
class EnsureApiSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            return static::sessionRequired();
        }

        return $next($request);
    }

    /** session を読む他の API middleware も同じ応答に揃える。 */
    public static function sessionRequired(): JsonResponse
    {
        return ApiErrorResponse::make(
            'session_required',
            'この操作にはブラウザのセッションが必要です。ページを再読み込みしてください。',
            Response::HTTP_BAD_REQUEST,
        );
    }
}
