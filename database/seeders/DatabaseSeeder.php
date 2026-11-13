<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\Concerns\RunsOnlyInLocalEnvironments;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * 開発用のデータ（`./vendor/bin/sail artisan migrate:fresh --seed`）。password はすべて `password`。
 * 以降の Milestone で求人・応募・メッセージを足していく（docs/domain-design.md 11 章）。
 */
class DatabaseSeeder extends Seeder
{
    use RunsOnlyInLocalEnvironments, WithoutModelEvents;

    public function run(): void
    {
        $this->ensureLocalEnvironment();

        User::factory()->create([
            'name' => '求職 太郎',
            'email' => 'seeker@example.com',
        ]);

        $company = Company::factory()->create(['name' => 'サンプル株式会社']);

        User::factory()->companyMember($company)->create([
            'name' => '採用 花子',
            'email' => 'company@example.com',
        ]);
    }
}
