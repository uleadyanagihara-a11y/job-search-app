<?php

namespace Tests\Feature\Console;

use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;
use App\Notifications\CompanyInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * company:create / invite / suspend / resume（docs/domain-api-contract.md M2.5・M2.7）。
 */
class CompanyCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_makes_company_and_invitation_and_sends_mail_with_frontend_url(): void
    {
        Notification::fake();

        $this->artisan('company:create', ['name' => '新規株式会社', 'email' => 'Owner@Example.com'])
            ->expectsOutputToContain('新規株式会社')
            ->assertSuccessful();

        $company = Company::query()->sole();
        $this->assertSame('新規株式会社', $company->name);

        $invitation = CompanyInvitation::query()->sole();
        $this->assertSame('owner@example.com', $invitation->email);
        $this->assertSame($company->id, $invitation->company_id);

        Notification::assertSentOnDemand(
            CompanyInvitationNotification::class,
            function (CompanyInvitationNotification $notification, array $channels, AnonymousNotifiable $notifiable) use ($invitation) {
                $url = $notification->toMail($notifiable)->actionUrl;
                $token = basename((string) parse_url($url, PHP_URL_PATH));

                return $notifiable->routes['mail'] === 'owner@example.com'
                    && str_starts_with($url, rtrim(config('app.frontend_url'), '/').'/invitations/')
                    && ! str_contains($url, 'owner@example.com')
                    && hash('sha256', $token) === $invitation->token_hash;
            },
        );
    }

    public function test_create_fails_without_creating_anything_when_email_is_registered(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'taken@example.com']);

        $this->artisan('company:create', ['name' => '新規株式会社', 'email' => 'taken@example.com'])
            ->expectsOutputToContain('既に登録されています')
            ->assertFailed();

        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('company_invitations', 0);
        Notification::assertNothingSent();
    }

    public function test_create_rejects_invalid_email_and_name(): void
    {
        Notification::fake();

        $this->artisan('company:create', ['name' => '新規株式会社', 'email' => 'not-an-email'])->assertFailed();
        $this->artisan('company:create', ['name' => str_repeat('あ', 101), 'email' => 'owner@example.com'])->assertFailed();

        $this->assertDatabaseCount('companies', 0);
        Notification::assertNothingSent();
    }

    public function test_invite_resends_and_invalidates_the_previous_invitation(): void
    {
        Notification::fake();
        $company = Company::factory()->create();
        $oldToken = CompanyInvitation::issue($company, 'member@example.com');

        $this->artisan('company:invite', ['company' => $company->id, 'email' => 'member@example.com'])
            ->assertSuccessful();

        $this->assertDatabaseCount('company_invitations', 2);
        $this->assertNull(CompanyInvitation::query()->usable($oldToken)->first());
        Notification::assertSentOnDemandTimes(CompanyInvitationNotification::class, 1);
    }

    public function test_invite_fails_for_missing_or_suspended_company(): void
    {
        Notification::fake();

        $this->artisan('company:invite', ['company' => 999, 'email' => 'member@example.com'])->assertFailed();

        $suspended = Company::factory()->suspended()->create();
        $this->artisan('company:invite', ['company' => $suspended->id, 'email' => 'member@example.com'])->assertFailed();

        $this->assertDatabaseCount('company_invitations', 0);
        Notification::assertNothingSent();
    }

    public function test_suspend_deletes_member_sessions_and_invalidates_remember_cookie(): void
    {
        // 停止は sessions テーブルの行を消して session を切る（SESSION_DRIVER=database 前提）。
        config(['session.driver' => 'database']);

        $company = Company::factory()->create();
        $member = User::factory()->companyMember($company)->create();
        $seeker = User::factory()->create();

        $login = $this->fromFrontend()->postJson('/api/auth/login', [
            'email' => $member->email,
            'password' => 'password',
            'remember' => true,
        ])->assertOk();

        $sessionCookie = [config('session.cookie') => $login->getCookie(config('session.cookie'))->getValue()];
        $rememberName = $this->app['auth']->guard('web')->getRecallerName();
        $rememberCookie = [$rememberName => $login->getCookie($rememberName)->getValue()];
        $rememberToken = $member->fresh()->getRememberToken();

        DB::table('sessions')->insert([
            'id' => 'seeker-session', 'user_id' => $seeker->id, 'payload' => '', 'last_activity' => time(),
        ]);

        // 停止前は session cookie でも remember cookie でも認証される（test 自体の前提の確認）。
        $this->getAsFrontend('/api/user', $sessionCookie)->assertOk();
        $this->getAsFrontend('/api/user', $rememberCookie)->assertOk();
        $this->assertGreaterThan(0, DB::table('sessions')->where('user_id', $member->id)->count());

        $this->artisan('company:suspend', ['company' => $company->id])->assertSuccessful();

        $this->assertNotNull($company->fresh()->suspended_at);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $member->id)->count());
        $this->assertNotSame($rememberToken, $member->fresh()->getRememberToken());
        // 他の企業・求職者の session は消さない。
        $this->assertDatabaseHas('sessions', ['id' => 'seeker-session']);

        $this->getAsFrontend('/api/user', $sessionCookie)->assertUnauthorized();
        $this->getAsFrontend('/api/user', $rememberCookie)->assertUnauthorized();
    }

    public function test_resume_clears_suspended_at_and_member_can_login_again(): void
    {
        $company = Company::factory()->suspended()->create();
        $member = User::factory()->companyMember($company)->create();

        $this->artisan('company:resume', ['company' => $company->id])->assertSuccessful();

        $this->assertNull($company->fresh()->suspended_at);
        $this->fromFrontend()->postJson('/api/auth/login', ['email' => $member->email, 'password' => 'password'])
            ->assertOk();
    }

    /**
     * 別の request として送るため、前の request で解決した user と session を捨ててから cookie だけで送る。
     *
     * @param  array<string, string>  $cookies
     */
    private function getAsFrontend(string $uri, array $cookies): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('session.store');
        $this->app['session']->forgetDrivers();

        // JSON request は withCredentials() が無いと cookie を送らない。
        $this->defaultCookies = [];

        return $this->withCredentials()->withCookies($cookies)->fromFrontend()->getJson($uri);
    }
}
