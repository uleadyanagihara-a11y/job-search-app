<?php

use App\Http\Controllers\Api\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Api\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Api\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Api\Auth\NewPasswordController;
use App\Http\Controllers\Api\Auth\PasswordResetLinkController;
use App\Http\Controllers\Api\Auth\RegisteredUserController;
use App\Http\Controllers\Api\PasswordController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/user', [UserController::class, 'show'])->middleware('auth:sanctum');

// api.session を付けた route は session（Cookie）認証専用で、token 認証では 400 になる。
// api.guest / api.session は $middlewarePriority に無いため、この 2 つの間の実行順は記述順で決まる
// （throttle と auth:sanctum は priority で前に寄る）。順序は EnsureApiSessionTest で検査する。
Route::post('/auth/register', [RegisteredUserController::class, 'store'])
    ->middleware(['throttle:auth.register', 'api.guest', 'api.session']);

Route::post('/auth/login', [AuthenticatedSessionController::class, 'store'])
    ->middleware(['throttle:auth.login', 'api.guest', 'api.session']);

Route::post('/auth/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware(['auth:sanctum', 'api.session']);

Route::post('/auth/forgot-password', [PasswordResetLinkController::class, 'store'])
    ->middleware(['api.guest', 'throttle:auth.forgot-password']);

Route::post('/auth/reset-password', [NewPasswordController::class, 'store'])
    ->middleware(['api.guest', 'throttle:auth.reset-password']);

Route::post('/auth/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
    ->middleware(['auth:sanctum', 'throttle:auth.verification-notification']);

Route::post('/auth/confirm-password', [ConfirmablePasswordController::class, 'store'])
    ->middleware(['auth:sanctum', 'throttle:auth.confirm-password', 'api.session']);

Route::get('/profile', [ProfileController::class, 'show'])->middleware('auth:sanctum');

Route::patch('/profile', [ProfileController::class, 'update'])->middleware('auth:sanctum');

// password 更新と account 削除は body の現在 password で本人確認するため
// api.password.confirm を重ねない（docs/inertia-removal-migration.md §5.5.2）。
// 現在 password の総当たりを防ぐため、同じく password を照合する confirm-password と
// limiter を共有し、3 endpoint の合計で数える。
Route::delete('/profile', [ProfileController::class, 'destroy'])
    ->middleware(['auth:sanctum', 'throttle:auth.confirm-password', 'api.session']);

Route::put('/profile/password', [PasswordController::class, 'update'])
    ->middleware(['auth:sanctum', 'throttle:auth.confirm-password']);
