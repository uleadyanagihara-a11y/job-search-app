<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;

/**
 * 停止した企業を再開する（docs/domain-api-contract.md M2.5）。担当者は login し直す。
 */
class ResumeCompany extends Command
{
    protected $signature = 'company:resume {company : 企業の id}';

    protected $description = '停止した企業を再開する';

    public function handle(): int
    {
        $company = Company::find($this->argument('company'));

        if ($company === null) {
            $this->error('企業が見つかりません: '.$this->argument('company'));

            return self::FAILURE;
        }

        $company->suspended_at = null;
        $company->save();

        $this->info("企業を再開しました: #{$company->id} {$company->name}");

        return self::SUCCESS;
    }
}
