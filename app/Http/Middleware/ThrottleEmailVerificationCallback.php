<?php

namespace App\Http\Middleware;

use App\Support\FrontendUrl;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * メール認証 callback 専用の throttle（docs/inertia-removal-migration.md §5.4）。
 *
 * 署名不正・未ログインで弾かれるリクエストも数えるため signed/auth より前で評価したいが、
 * 標準の ThrottleRequests は Kernel の $middlewarePriority で auth の後ろへ並べ替えられる。
 * 並べ替えは親クラス/interface も見て判定するため、ThrottleRequests は継承しない。
 * key は未ログインでも数えられるよう IP にする。
 */
class ThrottleEmailVerificationCallback
{
    private const MAX_ATTEMPTS = 6;

    private const DECAY_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $key = 'email-verification-callback:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return redirect()->away(FrontendUrl::to('/verify-email', ['error' => 'too-many-requests']));
        }

        RateLimiter::hit($key, self::DECAY_SECONDS);

        return $next($request);
    }
}
