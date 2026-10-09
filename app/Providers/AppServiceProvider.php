<?php

namespace App\Providers;

use App\Support\FrontendUrl;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

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

        // reset mail は Laravel の画面 route ではなく SPA の URL へ向ける（§5.3）。
        ResetPassword::createUrlUsing(fn ($user, string $token) => FrontendUrl::to(
            '/reset-password/'.$token,
            ['email' => $user->getEmailForPasswordReset()],
        ));
    }
}
