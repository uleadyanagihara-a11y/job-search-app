<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;

class PasswordResetLinkController extends Controller
{
    /**
     * POST /api/auth/forgot-password (docs/inertia-removal-migration.md §5.2).
     *
     * account enumeration を防ぐため、user の有無や送信抑制（RESET_THROTTLED）に
     * かかわらず常に同じ 200 を返す。
     */
    public function store(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink($request->only('email'));

        return response()->json([
            'code' => 'password_reset_link_sent',
            'message' => 'パスワード再設定の案内を送信しました。メールをご確認ください。',
        ]);
    }
}
