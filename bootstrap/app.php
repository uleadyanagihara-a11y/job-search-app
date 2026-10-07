<?php

use App\Http\Middleware\Api\EnsureApiEmailIsVerified;
use App\Http\Middleware\Api\EnsureApiGuest;
use App\Http\Middleware\Api\EnsureApiPasswordIsConfirmed;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ThrottleEmailVerificationCallback;
use App\Support\Api\ApiExceptionRenderer;
use App\Support\FrontendUrl;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Routing\Middleware\ValidateSignature;

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

        // password 変更時に他端末の session を無効化する。API（stateful）側は Sanctum の
        // `sanctum.middleware.authenticate_session` が担当するため、web group にだけ
        // `auth.session` を追加する。どちらも session の `password_hash_web` を照合する。
        $middleware->authenticateSessions();

        // メール認証 callback に未ログインで来た場合は SPA の login へ。redirect()->guest() が
        // callback URL を intended として session に保存し、login API が redirect_to で返す（§5.4）。
        // それ以外の web route は Inertia 撤去（Phase 6）まで従来の login 画面へ向ける。
        $middleware->redirectGuestsTo(fn (Request $request) => $request->routeIs('verification.verify')
            ? FrontendUrl::to('/login', ['verification_required' => 1])
            : route('login'));

        // route の middleware は Kernel の $middlewarePriority で並べ替えられ、一覧に無いものは
        // web group の後ろ（実質最後）に回る。route に書いた順は効かず、`route:list -v` の表示も
        // 実行順ではない。メール認証 callback を throttle → signed → auth の順で評価させるため、
        // 専用 throttle と signed を auth の直前に差し込む（§5.4/§5.5.1）。
        // どちらも auth と併用する route はこの callback だけなので、他の route の順序は変わらない。
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: ValidateSignature::class,
        );
        $middleware->prependToPriorityList(
            before: ValidateSignature::class,
            prepend: ThrottleEmailVerificationCallback::class,
        );

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

        // メール認証 callback は画面を返さず、失敗理由を query に付けて SPA へ redirect する（§5.4）。
        $exceptions->render(function (InvalidSignatureException $e, Request $request) {
            if ($request->routeIs('verification.verify')) {
                return redirect()->away(FrontendUrl::to('/verify-email', ['error' => 'invalid-or-expired']));
            }
        });

        // API route の例外は docs/inertia-removal-migration.md §5.1/§5.5 の
        // JSON error contract に統一する。web(Inertia) route には手を出さない。
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiExceptionRenderer::render($e);
        });
    })->create();
