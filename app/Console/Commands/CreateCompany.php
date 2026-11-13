<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\InvitesCompanyMembers;
use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 運営が企業と最初の担当者の招待を作る（docs/domain-design.md 2 章、docs/domain-api-contract.md M2.5）。
 */
class CreateCompany extends Command
{
    use InvitesCompanyMembers;

    protected $signature = 'company:create
                            {name : 企業名（100 文字まで）}
                            {email : 最初の担当者のメールアドレス}';

    protected $description = '企業を作り、最初の担当者へ招待メールを送る';

    public function handle(): int
    {
        $name = trim((string) $this->argument('name'));

        if ($name === '' || mb_strlen($name) > 100) {
            $this->error('企業名は 1〜100 文字で指定してください。');

            return self::FAILURE;
        }

        $email = $this->normalizeInvitationEmail((string) $this->argument('email'));

        if ($email === null) {
            return self::FAILURE;
        }

        // 招待メールの送信に失敗したら企業も作らない（送り直しの手順を company:invite に 1 本化するため）。
        $company = DB::transaction(function () use ($name, $email) {
            $company = Company::create(['name' => $name]);

            $this->sendInvitation($company, $email);

            return $company;
        });

        $this->info("企業を作りました: #{$company->id} {$company->name}");

        return self::SUCCESS;
    }
}
