<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;
use App\Notifications\CompanyInvitationNotification;
use Database\Seeders\Concerns\RunsOnlyInLocalEnvironments;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/**
 * E2E 用のデータ。compose.yaml の laravel.e2e が起動のたびに `migrate:fresh --seeder=E2eSeeder` で入れる。
 *
 * 各 test が register API で user を作ると rate limit の待ちが積み上がるため、E2E で使う user はここでまとめて作る
 * （docs/domain-design.md 2 章）。email と password は frontend/e2e/helpers.ts の E2E_USERS と揃える。
 *
 * artisan は Playwright から呼べないため、招待はここで作ってメールを送る。E2E は Mailpit からメールの URL を取る。
 */
class E2eSeeder extends Seeder
{
    use RunsOnlyInLocalEnvironments;

    public const PASSWORD = 'E2e-Passw0rd!';

    public function run(): void
    {
        $this->ensureLocalEnvironment();

        $password = Hash::make(self::PASSWORD);

        User::factory()->create([
            'name' => 'E2E 求職者',
            'email' => 'e2e-seeker@example.com',
            'password' => $password,
        ]);

        $company = Company::factory()->create(['name' => 'E2E 株式会社']);

        User::factory()->companyMember($company)->create([
            'name' => 'E2E 担当者',
            'email' => 'e2e-company@example.com',
            'password' => $password,
        ]);

        User::factory()->companyMember(Company::factory()->suspended()->create(['name' => 'E2E 停止中株式会社']))->create([
            'name' => 'E2E 停止中の担当者',
            'email' => 'e2e-suspended@example.com',
            'password' => $password,
        ]);

        $invitedEmail = 'e2e-invited@example.com';

        Notification::route('mail', $invitedEmail)->notify(
            new CompanyInvitationNotification($company, CompanyInvitation::issue($company, $invitedEmail)),
        );
    }
}
