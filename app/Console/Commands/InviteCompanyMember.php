<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\InvitesCompanyMembers;
use App\Models\Company;
use Illuminate\Console\Command;

/**
 * 既存の企業へ招待を送り直す。同じ email の未使用の招待は無効になる。期限切れの再送もこれで行う
 * （docs/domain-api-contract.md M2.5）。
 */
class InviteCompanyMember extends Command
{
    use InvitesCompanyMembers;

    protected $signature = 'company:invite
                            {company : 企業の id}
                            {email : 担当者のメールアドレス}';

    protected $description = '既存の企業の担当者へ招待メールを送る（送り直し）';

    public function handle(): int
    {
        $company = Company::find($this->argument('company'));

        if ($company === null) {
            $this->error('企業が見つかりません: '.$this->argument('company'));

            return self::FAILURE;
        }

        // 停止中の企業への招待は受諾できない（docs/domain-design.md 2 章）ため、送らない。
        if ($company->isSuspended()) {
            $this->error("企業は停止中です。先に company:resume してください: #{$company->id} {$company->name}");

            return self::FAILURE;
        }

        $email = $this->normalizeInvitationEmail((string) $this->argument('email'));

        if ($email === null) {
            return self::FAILURE;
        }

        $this->sendInvitation($company, $email);

        $this->info("企業: #{$company->id} {$company->name}");

        return self::SUCCESS;
    }
}
