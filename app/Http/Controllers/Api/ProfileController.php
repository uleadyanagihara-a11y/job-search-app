<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProfileUpdateRequest;
use App\Http\Resources\UserResource;
use App\Models\CompanyMember;
use App\Notifications\EmailChangedNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

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
     *
     * email を変えたら古いアドレスに通知する（docs/domain-api-contract.md M2.2）。
     */
    public function update(ProfileUpdateRequest $request): UserResource
    {
        $user = $request->user();

        $user->fill($request->profileAttributes());

        $previousEmail = $user->isDirty('email') ? $user->getOriginal('email') : null;

        if ($previousEmail !== null) {
            $user->email_verified_at = null;
        }

        // validation の unique は事前チェックにすぎず、同時の登録・招待の受諾・別の user の変更では抜けるため、
        // `users.email` の unique 制約を正として 409 にする（docs/domain-api-contract.md M2.2、§5.5.5）。
        try {
            $user->save();
        } catch (UniqueConstraintViolationException) {
            abort(Response::HTTP_CONFLICT);
        }

        if ($previousEmail !== null) {
            Notification::route('mail', $previousEmail)->notify(new EmailChangedNotification);
        }

        return new UserResource($user);
    }

    /**
     * DELETE /api/profile (docs/inertia-removal-migration.md §5.2).
     * body の password で本人確認するため `api.password.confirm` は重ねない（§5.5.2）。
     *
     * 企業の最後の担当者は削除できない（409。docs/domain-design.md 8 章）。
     * 求職者の seeker_profiles、担当者の company_members は FK の cascade で消える。
     */
    public function destroy(Request $request): Response
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        DB::transaction(function () use ($user) {
            $membership = $user->companyMember;

            if ($membership !== null) {
                // 同じ企業の担当者 2 人が同時に削除しても、両方が「最後ではない」と判定しないよう行を lock する。
                $members = CompanyMember::query()
                    ->where('company_id', $membership->company_id)
                    ->lockForUpdate()
                    ->count();

                abort_if($members <= 1, Response::HTTP_CONFLICT);
            }

            // logout は remember_token を作り直して user を保存するため、delete より先に呼ぶ
            // （後だと削除済みの model の save で行が作り直される）。
            Auth::guard('web')->logout();

            $user->delete();
        });

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
