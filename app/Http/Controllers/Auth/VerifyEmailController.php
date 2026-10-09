<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\FrontendUrl;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class VerifyEmailController extends Controller
{
    /**
     * 署名付きメール認証 Web callback（docs/inertia-removal-migration.md §5.4）。
     *
     * EmailVerificationRequest は id 不一致と hash 不一致をまとめて 403 にするため使わず、
     * user-mismatch と invalid-or-expired を区別できるよう個別に判定する。
     * 署名不正/期限切れ・未ログイン・throttle は route middleware 側で redirect 済み。
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();

        // 「このリンクはログイン中の user のものか」を verified 判定より先に確認する。
        // 別 user のリンクで「認証済み」と見せたり、どちらかを更新したりしないため。
        if (! hash_equals((string) $user->getKey(), (string) $request->route('id'))) {
            return redirect()->away(FrontendUrl::to('/verify-email', ['error' => 'user-mismatch']));
        }

        // 署名は正しいが、送信後に email が変わった等で hash が一致しないリンク。
        if (! hash_equals(sha1($user->getEmailForVerification()), (string) $request->route('hash'))) {
            return redirect()->away(FrontendUrl::to('/verify-email', ['error' => 'invalid-or-expired']));
        }

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return redirect()->away(FrontendUrl::to('/dashboard', ['verified' => 1]));
    }
}
