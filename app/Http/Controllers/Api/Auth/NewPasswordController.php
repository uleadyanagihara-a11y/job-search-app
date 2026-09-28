<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetPasswordRequest;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class NewPasswordController extends Controller
{
    /**
     * POST /api/auth/reset-password (docs/inertia-removal-migration.md §5.3).
     *
     * @throws ValidationException
     */
    public function store(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user) use ($request) {
                $user->forceFill([
                    'password' => Hash::make($request->string('password')->toString()),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            // 無効・期限切れ・使用済み・email 不一致は区別せず errors.token に集約する（§5.3）。
            throw ValidationException::withMessages([
                'token' => [trans($status)],
            ]);
        }

        return response()->json([
            'code' => 'password_reset',
            'message' => 'パスワードを再設定しました。',
        ]);
    }
}
