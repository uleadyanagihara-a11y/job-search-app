<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\InvitationAcceptRequest;
use App\Http\Requests\Auth\InvitationLookupRequest;
use App\Http\Resources\UserResource;
use App\Models\CompanyInvitation;
use App\Models\CompanyMember;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * 企業担当者の招待の確認と受諾（docs/domain-design.md 2 章、docs/domain-api-contract.md M2.3）。
 * guest 用のため Policy を持たず、停止中・登録済みの判定は業務ルール（409）としてここに書く。
 */
class CompanyInvitationController extends Controller
{
    /**
     * POST /api/company-invitations/lookup。
     */
    public function lookup(InvitationLookupRequest $request): JsonResponse
    {
        $invitation = $this->findAcceptableInvitation($request->validated('token'));

        return response()->json([
            'data' => [
                'company_name' => $invitation->company->name,
                'email' => $invitation->email,
                'expires_at' => $invitation->expires_at->clone()->utc()->format('Y-m-d\TH:i:s\Z'),
            ],
        ]);
    }

    /**
     * POST /api/company-invitations/accept。
     *
     * 同時に 2 回受諾されても user が 2 人できないよう、招待の行を lock して使用済みかを確かめ直す。
     * 招待後に同じ email で求職者登録された場合などは、`users.email` の unique 制約を正として 409 にする（§5.5.5）。
     */
    public function accept(InvitationAcceptRequest $request): JsonResponse
    {
        $invitation = $this->findAcceptableInvitation($request->validated('token'));

        try {
            $user = DB::transaction(function () use ($invitation, $request) {
                $locked = CompanyInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();

                if ($locked->accepted_at !== null) {
                    throw $this->invalidToken();
                }

                // 招待のメールが届いたことで本人確認できているため、email_verified_at は受諾した時刻にする。
                $user = (new User)->forceFill([
                    'name' => $request->validated('name'),
                    'email' => $locked->email,
                    'password' => Hash::make($request->validated('password')),
                    'role' => Role::Company,
                    'email_verified_at' => now(),
                ]);
                $user->save();

                $member = new CompanyMember;
                $member->company()->associate($locked->company_id);
                $member->user()->associate($user);
                $member->save();

                $locked->accepted_at = now();
                $locked->save();

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            abort(Response::HTTP_CONFLICT);
        }

        Auth::guard('web')->login($user);

        // session fixation 対策（docs/domain-design.md 2 章）。
        $request->session()->regenerate();

        return (new UserResource($user))
            ->additional(['meta' => ['redirect_to' => '/dashboard']])
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * 無効・期限切れ・使用済みの token は理由を区別せず 422。停止中の企業・登録済みの email は 409。
     *
     * @throws ValidationException
     */
    private function findAcceptableInvitation(string $token): CompanyInvitation
    {
        $invitation = CompanyInvitation::query()->usable($token)->with('company')->first();

        if ($invitation === null) {
            throw $this->invalidToken();
        }

        abort_if(
            $invitation->company->isSuspended() || User::query()->where('email', $invitation->email)->exists(),
            Response::HTTP_CONFLICT,
        );

        return $invitation;
    }

    private function invalidToken(): ValidationException
    {
        return ValidationException::withMessages([
            'token' => ['招待のリンクが無効か、有効期限が切れています。'],
        ]);
    }
}
