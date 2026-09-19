<?php

namespace App\Http\Middleware\Api;

use App\Support\Api\ApiErrorResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `api.guest` middleware alias (docs/inertia-removal-migration.md §5.5.1).
 * Laravel標準の`guest`はauthenticated userへ302 redirectするためAPI routeでは使わない。
 */
class EnsureApiGuest
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()) {
            return ApiErrorResponse::make(
                'already_authenticated',
                '既にログインしています。',
                Response::HTTP_CONFLICT,
            );
        }

        return $next($request);
    }
}
