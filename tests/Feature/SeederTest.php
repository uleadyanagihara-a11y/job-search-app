<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CompanyInvitation;
use App\Models\User;
use App\Notifications\CompanyInvitationNotification;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\E2eSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * 固定の password の user を作る seeder（docs/domain-design.md 2 章）。
 */
class SeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{class-string}>
     */
    public static function seeders(): array
    {
        return ['DatabaseSeeder' => [DatabaseSeeder::class], 'E2eSeeder' => [E2eSeeder::class]];
    }

    /**
     * @param  class-string  $seeder
     */
    #[DataProvider('seeders')]
    public function test_seeder_refuses_to_run_outside_local_and_testing(string $seeder): void
    {
        $this->app['env'] = 'production';

        // db:seed は production では確認を求めて何もせず終わるため、seeder を直接実行する。
        try {
            $this->app->make($seeder)->run();
            $this->fail('例外にならなかった');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('local・testing', $e->getMessage());
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertDatabaseCount('users', 0);
    }

    public function test_e2e_seeder_creates_users_for_each_role_and_sends_an_invitation(): void
    {
        Notification::fake();

        $this->seed(E2eSeeder::class);

        $this->assertSame(Role::Seeker, User::query()->where('email', 'e2e-seeker@example.com')->sole()->role);

        $company = User::query()->where('email', 'e2e-company@example.com')->sole();
        $this->assertSame(Role::Company, $company->role);
        $this->assertFalse($company->belongsToSuspendedCompany());

        $this->assertTrue(User::query()->where('email', 'e2e-suspended@example.com')->sole()->belongsToSuspendedCompany());

        $this->assertSame('e2e-invited@example.com', CompanyInvitation::query()->sole()->email);
        Notification::assertSentOnDemandTimes(CompanyInvitationNotification::class, 1);
    }
}
