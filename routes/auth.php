<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Middleware\ThrottleEmailVerificationCallback;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('register', [RegisteredUserController::class, 'create'])
        ->name('register');

    Route::post('register', [RegisteredUserController::class, 'store']);

    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->name('password.store');
});

Route::middleware('auth')->group(function () {
    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});

// メール認証 Web callback（docs/inertia-removal-migration.md §5.4）。
// 実行順は throttle → signed → auth。throttle を先頭に置くのは、signed/auth で弾かれる
// リクエスト（署名総当たり・未ログインアクセス）もレート制限の対象にするため。
// 実行順はここに書いた順ではなく bootstrap/app.php の priority 設定で決まる。
// - throttle: 標準の ThrottleRequests は $middlewarePriority で auth の後ろへ並べ替えられる
//   ため、専用 middleware を使う（失敗時は SPA へ redirect）。
// - signed: 失敗時は bootstrap/app.php で SPA へ redirect する。
// - auth: 未ログイン時は bootstrap/app.php の redirectGuestsTo で SPA の login へ。
// path は SPA の /verify-email 画面と prefix が重ならないよう /auth/email/verify/ 配下に置く
// （Nginx が prefix で Laravel へ proxy する）。
Route::get('auth/email/verify/{id}/{hash}', VerifyEmailController::class)
    ->middleware([ThrottleEmailVerificationCallback::class, 'signed', 'auth'])
    ->name('verification.verify');
