<?php

use App\Http\Middleware\Api\EnsureApiEmailIsVerified;
use App\Http\Middleware\Api\EnsureApiGuest;
use App\Http\Middleware\Api\EnsureApiPasswordIsConfirmed;
use App\Http\Middleware\HandleInertiaRequests;
use App\Support\Api\ApiExceptionRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Nginx がリクエストを中継するため、X-Forwarded-* を Laravel に解釈させる。
        // sail network 内は Nginx だけが唯一のブラウザ向け入口（CLAUDE.md）。
        $middleware->trustProxies(at: '*');

        $middleware->statefulApi();

        $middleware->alias([
            'api.guest' => EnsureApiGuest::class,
            'api.verified' => EnsureApiEmailIsVerified::class,
            'api.password.confirm' => EnsureApiPasswordIsConfirmed::class,
        ]);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // API route の例外は docs/inertia-removal-migration.md §5.1/§5.5 の
        // JSON error contract に統一する。web(Inertia) route には手を出さない。
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiExceptionRenderer::render($e);
        });
    })->create();
