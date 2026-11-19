<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 企業を停止する（docs/domain-design.md 2 章「企業の停止」、docs/domain-api-contract.md M2.5）。
 *
 * ログイン中の担当者は、request ごとに停止状態を確かめる `api.company.active` が締め出すため、
 * `sessions` の行は消さない（session の driver に依存しない）。
 * 「ログイン状態を保持する」の cookie も無効にするため、`remember_token` は作り直す。
 */
class SuspendCompany extends Command
{
    protected $signature = 'company:suspend {company : 企業の id}';

    protected $description = '企業を停止し、担当者の remember cookie を無効にする（ログイン中の担当者は次の request で締め出される）';

    public function handle(): int
    {
        $company = Company::find($this->argument('company'));

        if ($company === null) {
            $this->error('企業が見つかりません: '.$this->argument('company'));

            return self::FAILURE;
        }

        DB::transaction(function () use ($company) {
            $company->suspended_at ??= now();
            $company->save();

            $userIds = $company->members()->pluck('user_id');

            User::query()->whereKey($userIds)->each(function (User $user) {
                $user->setRememberToken(Str::random(60));
                $user->save();
            });
        });

        $this->info("企業を停止しました: #{$company->id} {$company->name}");

        return self::SUCCESS;
    }
}
