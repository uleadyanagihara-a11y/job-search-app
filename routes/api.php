<?php

use App\Http\Controllers\Api\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Api\Auth\NewPasswordController;
use App\Http\Controllers\Api\Auth\PasswordResetLinkController;
use App\Http\Controllers\Api\Auth\RegisteredUserController;
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
