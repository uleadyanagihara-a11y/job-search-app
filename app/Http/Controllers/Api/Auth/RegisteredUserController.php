<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class RegisteredUserController extends Controller
{
    /**
     * POST /api/auth/register (docs/inertia-removal-migration.md §5.2).
     */
    public function store(RegisterRequest $request): JsonResponse
    {
        // 登録 API で作るのは求職者だけ。request の role は validation に無いため読まない
        // （企業担当者は招待でしか作れない。docs/domain-design.md 1 章）。
        $user = (new User)->forceFill([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => Hash::make($request->validated('password')),
            'role' => Role::Seeker,
        ]);
        $user->save();

        event(new Registered($user));

        Auth::guard('web')->login($user);

        $request->session()->regenerate();

        return (new UserResource($user))
            ->additional(['meta' => ['redirect_to' => '/dashboard']])
            ->response()
            ->setStatusCode(201);
    }
}
