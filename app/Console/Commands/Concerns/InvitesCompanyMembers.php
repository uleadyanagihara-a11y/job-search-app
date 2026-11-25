<?php

namespace App\Console\Commands\Concerns;

use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;
use App\Notifications\CompanyInvitationNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * company:create と company:invite の共通処理（docs/domain-api-contract.md M2.5）。
 *
 * @mixin Command
 */
trait InvitesCompanyMembers
{
    /**
     * 招待できない email ならエラーを出力して null を返す。email は小文字にそろえる（register の `lowercase` と同じ）。
     */
    protected function normalizeInvitationEmail(string $email): ?string
    {
        $email = Str::lower(trim($email));

        if (Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']])->fails()) {
            $this->error("メールアドレスの形式が正しくありません: {$email}");

            return null;
        }

        // 1 アカウント 1 ロールのため、既存の user を企業担当者に変えない（docs/domain-api-contract.md M2.0）。
        if (User::query()->where('email', $email)->exists()) {
            $this->error("このメールアドレスは既に登録されています: {$email}");

            return null;
        }

        return $email;
    }

    protected function sendInvitation(Company $company, string $email): void
    {
        $token = CompanyInvitation::issue($company, $email);

        Notification::route('mail', $email)->notify(new CompanyInvitationNotification($company, $token));

        $this->info("招待メールを送りました: {$email}");
    }
}
