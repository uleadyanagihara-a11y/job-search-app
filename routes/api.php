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

Route::post('/auth/register', [RegisteredUserController::class, 'store'])
    ->middleware(['api.guest', 'throttle:6,1']);

Route::post('/auth/login', [AuthenticatedSessionController::class, 'store'])
    ->middleware('api.guest');

Route::post('/auth/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth:sanctum');

Route::post('/auth/forgot-password', [PasswordResetLinkController::class, 'store'])
    ->middleware(['api.guest', 'throttle:6,1']);

Route::post('/auth/reset-password', [NewPasswordController::class, 'store'])
    ->middleware(['api.guest', 'throttle:6,1']);

Route::post('/auth/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
    ->middleware(['auth:sanctum', 'throttle:6,1']);

Route::post('/auth/confirm-password', [ConfirmablePasswordController::class, 'store'])
    ->middleware(['auth:sanctum', 'throttle:6,1']);

Route::get('/profile', [ProfileController::class, 'show'])->middleware('auth:sanctum');

Route::patch('/profile', [ProfileController::class, 'update'])->middleware('auth:sanctum');

// password 更新と account 削除は body の現在 password で本人確認するため
// api.password.confirm を重ねない（docs/inertia-removal-migration.md §5.5.2）。
Route::delete('/profile', [ProfileController::class, 'destroy'])->middleware('auth:sanctum');

Route::put('/profile/password', [PasswordController::class, 'update'])->middleware('auth:sanctum');
