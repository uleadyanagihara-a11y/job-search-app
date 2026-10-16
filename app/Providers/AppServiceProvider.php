<?php

namespace App\Providers;

use App\Support\FrontendUrl;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        $this->forceApplicationUrl();

        // 認証は Sanctum の SPA（session）認証だけを使い、API token は発行しない。
        // personal_access_tokens テーブルも無いため、既定のままだと session で認証できない
        // request に Authorization: Bearer が付くと token を照合する SQL が失敗して 500 になる。
        // token を読まないようにして、bearer は無視し未ログインとして 401 を返す。
        Sanctum::getAccessTokenFromRequestUsing(fn () => null);

        // reset mail は Laravel の画面 route ではなく SPA の URL へ向ける（§5.3）。
        ResetPassword::createUrlUsing(fn ($user, string $token) => FrontendUrl::to(
            '/reset-password/'.$token,
            ['email' => $user->getEmailForPasswordReset()],
        ));

        $this->configureRateLimiting();
    }

    /**
     * 生成する URL の host/scheme を APP_URL に固定する（host header poisoning 対策）。
     *
     * Nginx は client の Host を X-Forwarded-Host に転送し、trustProxies('*') がそれを信用する。
     * 固定しないと、メール認証リンク等が request の Host / X-Forwarded-Host / X-Forwarded-Proto
     * から作られ、攻撃者の指定した host を指す正規のメールを送れてしまう。
     * 受信側（signed の検証）は request の URL を使うため、この設定の影響を受けない。
     */
    private function forceApplicationUrl(): void
    {
        $appUrl = (string) config('app.url');

        URL::useOrigin($appUrl);
        // useOrigin だけでは scheme が request（X-Forwarded-Proto）のものに置き換わるため、scheme も固定する。
        URL::forceScheme(parse_url($appUrl, PHP_URL_SCHEME) ?: 'http');
    }

    /**
     * endpoint ごとの名前付き limiter（docs/inertia-removal-migration.md §5.5.2）。
     *
     * `throttle:6,1` は全 route で同じ key（user id または domain|IP）を使うため、
     * 別 endpoint の request も同じ回数に合算される。名前付き limiter は key に limiter 名が
     * 付くので endpoint ごとに独立して数えられる。`by()` を省くと全 user 共通の key になるため必ず指定する。
     * メール認証 callback は ThrottleEmailVerificationCallback が別途制限する。
     */
    private function configureRateLimiting(): void
    {
        // login は LoginRequest が email+IP の組ごとに失敗 5 回で止める。それだけだと 1 IP から
        // email を変えて試す攻撃を防げないため、IP 単位の上限も重ねる。成功も数えるので、
        // IP を共有する環境（社内・学校の NAT 等）を考慮して他の endpoint より緩くする。
        RateLimiter::for('auth.login', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));

        // guest 専用 endpoint は未ログインなので IP 単位。
        foreach (['auth.register', 'auth.forgot-password', 'auth.reset-password'] as $name) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute(6)->by($request->ip()));
        }

        // 認証済み endpoint は user 単位。web/API の同じ操作の route は同じ limiter を共有する。
        // auth の後に動く前提だが、auth 無しの route に付けても 500 にならないよう IP に倒す。
        foreach (['auth.verification-notification', 'auth.confirm-password'] as $name) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute(6)->by(
                $request->user()?->getAuthIdentifier() ?? $request->ip(),
            ));
        }
    }
}
