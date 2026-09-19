<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * Return the authenticated user (GET /api/user).
     */
    public function show(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}
