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
 * request ごとに停止状態を確かめる middleware は作らないため、ここで担当者を確実に締め出す:
 * - `sessions` の行を消す（SESSION_DRIVER=database 前提。driver を変えるときはここも見直す）
 * - `remember_token` を作り直す（「ログイン状態を保持する」の cookie で再認証されないようにする）
 */
class SuspendCompany extends Command
{
    protected $signature = 'company:suspend {company : 企業の id}';

    protected $description = '企業を停止し、担当者の session と remember cookie を無効にする';

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

            DB::table('sessions')->whereIn('user_id', $userIds)->delete();

            User::query()->whereKey($userIds)->each(function (User $user) {
                $user->setRememberToken(Str::random(60));
                $user->save();
            });
        });

        $this->info("企業を停止しました: #{$company->id} {$company->name}");

        return self::SUCCESS;
    }
}
