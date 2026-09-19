<?php

use App\Http\Controllers\Api\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/user', [UserController::class, 'show'])->middleware('auth:sanctum');

Route::post('/auth/login', [AuthenticatedSessionController::class, 'store'])
    ->middleware('api.guest');

Route::post('/auth/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth:sanctum');
