<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 招待の lookup・accept（docs/domain-api-contract.md M2.3・M2.7）。
 */
class CompanyInvitationTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'invited@example.com';

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create(['name' => '招待株式会社']);
    }

    /**
     * @return array<string, string>
     */
    private function acceptInput(string $token): array
    {
        return [
            'token' => $token,
            'name' => '招待 太郎',
            'password' => 'password',
            'password_confirmation' => 'password',
        ];
    }

    public function test_lookup_returns_company_name_email_and_expiry(): void
    {
        $this->freezeSecond();
        $token = CompanyInvitation::issue($this->company, self::EMAIL);

        $this->postJson('/api/company-invitations/lookup', ['token' => $token])
            ->assertOk()
            ->assertExactJson(['data' => [
                'company_name' => '招待株式会社',
                'email' => self::EMAIL,
                'expires_at' => now()->addDays(7)->utc()->format('Y-m-d\TH:i:s\Z'),
            ]]);
    }

    public function test_accept_creates_a_verified_company_member_and_logs_in_with_a_new_session(): void
    {
        $token = CompanyInvitation::issue($this->company, self::EMAIL);

        Session::start();
        $sessionId = Session::getId();

        $response = $this->fromFrontend()->postJson('/api/company-invitations/accept', [
            ...$this->acceptInput($token),
            // email は受け取らず、招待に保存したものを使う。
            'email' => 'attacker@example.com',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.email', self::EMAIL)
            ->assertJsonPath('data.name', '招待 太郎')
            ->assertJsonPath('data.role', 'company')
            ->assertJsonPath('data.company_id', $this->company->id)
            ->assertJsonPath('data.email_verified_at', fn ($value) => is_string($value))
            ->assertJsonPath('meta.redirect_to', '/dashboard');

        $user = User::query()->where('email', self::EMAIL)->sole();
        $this->assertSame(Role::Company, $user->role);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame($this->company->id, $user->companyMember->company_id);
        $this->assertDatabaseMissing('users', ['email' => 'attacker@example.com']);
        $this->assertNotNull(CompanyInvitation::query()->sole()->accepted_at);

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotSame($sessionId, Session::getId());
    }

    public function test_the_same_token_cannot_be_accepted_twice(): void
    {
        $token = CompanyInvitation::issue($this->company, self::EMAIL);

        $this->fromFrontend()->postJson('/api/company-invitations/accept', $this->acceptInput($token))->assertCreated();

        $this->app['auth']->forgetGuards();
        $this->app['session']->flush();

        $this->fromFrontend()->postJson('/api/company-invitations/accept', $this->acceptInput($token))
            ->assertStatus(422)
            ->assertOnlyJsonValidationErrors(['token']);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('company_members', 1);
    }

    /**
     * 無効・期限切れ・使用済み・送り直しで無効になった token は、理由を区別せず同じ 422。
     *
     * @return array<string, array{string}>
     */
    public static function unusableTokens(): array
    {
        return ['unknown' => ['unknown'], 'expired' => ['expired'], 'accepted' => ['accepted'], 'reissued' => ['reissued']];
    }

    private function makeUnusableToken(string $case): string
    {
        if ($case === 'unknown') {
            return str_repeat('a', CompanyInvitation::TOKEN_LENGTH);
        }

        $token = CompanyInvitation::issue($this->company, self::EMAIL);

        match ($case) {
            'expired' => $this->travel(7)->days(),
            'accepted' => CompanyInvitation::query()->update(['accepted_at' => now()]),
            'reissued' => CompanyInvitation::issue($this->company, self::EMAIL),
        };

        return $token;
    }

    #[DataProvider('unusableTokens')]
    public function test_unusable_token_gets_the_same_422(string $case): void
    {
        $token = $this->makeUnusableToken($case);
        $usersBefore = User::query()->count();

        $this->postJson('/api/company-invitations/lookup', ['token' => $token])
            ->assertStatus(422)
            ->assertJson(['code' => 'validation_failed'])
            ->assertJsonPath('errors.token.0', '招待のリンクが無効か、有効期限が切れています。')
            ->assertOnlyJsonValidationErrors(['token']);

        $this->fromFrontend()->postJson('/api/company-invitations/accept', $this->acceptInput($token))
            ->assertStatus(422)
            ->assertJsonPath('errors.token.0', '招待のリンクが無効か、有効期限が切れています。')
            ->assertOnlyJsonValidationErrors(['token']);

        $this->assertSame($usersBefore, User::query()->count());
        $this->assertGuest('web');
    }

    public function test_invitation_is_valid_until_just_before_seven_days(): void
    {
        $token = CompanyInvitation::issue($this->company, self::EMAIL);
        $this->travel(7 * 24 * 60 - 1)->minutes();

        $this->postJson('/api/company-invitations/lookup', ['token' => $token])->assertOk();
    }

    public function test_reissued_token_is_usable(): void
    {
        CompanyInvitation::issue($this->company, self::EMAIL);
        $token = CompanyInvitation::issue($this->company, self::EMAIL);

        $this->postJson('/api/company-invitations/lookup', ['token' => $token])->assertOk();
    }

    public function test_invitation_to_suspended_company_gets_409(): void
    {
        $token = CompanyInvitation::issue($this->company, self::EMAIL);
        $this->company->forceFill(['suspended_at' => now()])->save();

        $this->postJson('/api/company-invitations/lookup', ['token' => $token])
            ->assertStatus(409)->assertJson(['code' => 'conflict']);
        $this->fromFrontend()->postJson('/api/company-invitations/accept', $this->acceptInput($token))
            ->assertStatus(409)->assertJson(['code' => 'conflict']);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_invitation_to_registered_email_gets_409(): void
    {
        // 招待の後に同じ email で求職者登録された場合など。
        $token = CompanyInvitation::issue($this->company, self::EMAIL);
        $seeker = User::factory()->create(['email' => self::EMAIL]);

        $this->postJson('/api/company-invitations/lookup', ['token' => $token])
            ->assertStatus(409)->assertJson(['code' => 'conflict']);
        $this->fromFrontend()->postJson('/api/company-invitations/accept', $this->acceptInput($token))
            ->assertStatus(409)->assertJson(['code' => 'conflict']);

        $this->assertSame(Role::Seeker, $seeker->fresh()->role);
        $this->assertDatabaseCount('company_members', 0);
        $this->assertNull(CompanyInvitation::query()->sole()->accepted_at);
    }

    public function test_accept_validates_name_and_password_like_register(): void
    {
        $token = CompanyInvitation::issue($this->company, self::EMAIL);

        $this->fromFrontend()->postJson('/api/company-invitations/accept', [
            'token' => $token,
            'name' => str_repeat('a', 256),
            'password' => 'password',
            'password_confirmation' => 'different',
        ])
            ->assertStatus(422)
            ->assertOnlyJsonValidationErrors(['name', 'password']);

        $this->assertDatabaseCount('users', 0);
        $this->assertNull(CompanyInvitation::query()->sole()->accepted_at);
    }

    public function test_missing_token_uses_the_invitation_attribute_name(): void
    {
        $this->postJson('/api/company-invitations/lookup', [])
            ->assertStatus(422)
            ->assertJsonPath('errors.token.0', fn (string $message) => str_contains($message, '招待のリンク'));
    }

    public function test_accept_requires_a_browser_session(): void
    {
        $token = CompanyInvitation::issue($this->company, self::EMAIL);

        $this->postJson('/api/company-invitations/accept', $this->acceptInput($token))
            ->assertBadRequest()
            ->assertJson(['code' => 'session_required']);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_lookup_and_accept_share_the_rate_limit(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/company-invitations/lookup', [])->assertStatus(422);
            $this->fromFrontend()->postJson('/api/company-invitations/accept', [])->assertStatus(422);
        }

        $this->postJson('/api/company-invitations/lookup', [])
            ->assertTooManyRequests()
            ->assertJson(['code' => 'rate_limited']);
    }

    public function test_token_is_stored_only_as_a_sha256_hash(): void
    {
        $token = CompanyInvitation::issue($this->company, self::EMAIL);

        $this->assertSame(64, strlen($token));
        $this->assertSame(hash('sha256', $token), CompanyInvitation::query()->sole()->token_hash);
        $this->assertDatabaseMissing('company_invitations', ['token_hash' => $token]);
    }
}
