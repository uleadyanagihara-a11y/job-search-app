<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiErrorResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationNotificationController extends Controller
{
    /**
     * POST /api/auth/email/verification-notification (docs/inertia-removal-migration.md §5.2).
     */
    public function store(Request $request): JsonResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return ApiErrorResponse::make(
                'conflict',
                '現在の状態ではこの操作を実行できません。',
                409,
            );
        }

        $request->user()->sendEmailVerificationNotification();

        return response()->json([
            'code' => 'verification_link_sent',
            'message' => '確認メールを送信しました。',
        ]);
    }
}
