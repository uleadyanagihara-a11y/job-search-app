<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProfileUpdateRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class ProfileController extends Controller
{
    /**
     * GET /api/profile (docs/inertia-removal-migration.md §5.2).
     * 旧 `mustVerifyEmail` prop は返さず、SPA が `email_verified_at` から導出する
     * （docs/phase0-api-contract.md §0.3）。
     */
    public function show(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    /**
     * PATCH /api/profile (docs/inertia-removal-migration.md §5.2).
     * SPA が auth store を同じ user で更新できるよう、更新済み resource を返す。
     */
    public function update(ProfileUpdateRequest $request): UserResource
    {
        $user = $request->user();

        $user->fill($request->validated());

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        return new UserResource($user);
    }

    /**
     * DELETE /api/profile (docs/inertia-removal-migration.md §5.2).
     * body の password で本人確認するため `api.password.confirm` は重ねない（§5.5.2）。
     */
    public function destroy(Request $request): Response
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::guard('web')->logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
