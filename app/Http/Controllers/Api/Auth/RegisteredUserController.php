<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
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

        // validation の unique は事前チェックにすぎず、同時の登録・招待の受諾では抜けるため、
        // `users.email` の unique 制約を正として 409 にする（docs/domain-api-contract.md M2.2、§5.5.5）。
        try {
            $user->save();
        } catch (UniqueConstraintViolationException) {
            abort(Response::HTTP_CONFLICT);
        }

        event(new Registered($user));

        Auth::guard('web')->login($user);

        $request->session()->regenerate();

        return (new UserResource($user))
            ->additional(['meta' => ['redirect_to' => '/dashboard']])
            ->response()
            ->setStatusCode(201);
    }
}
