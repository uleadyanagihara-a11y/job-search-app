<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_endpoints_are_counted_separately(): void
    {
        // `throttle:6,1` だと同じ IP の request が endpoint をまたいで合算されていた。
        for ($i = 0; $i < 6; $i++) {
            $this->fromFrontend()->postJson('/api/auth/register', []);
        }

        $this->fromFrontend()->postJson('/api/auth/register', [])->assertStatus(429);

        $this->fromFrontend()->postJson('/api/auth/forgot-password', [])->assertStatus(422);
        $this->fromFrontend()->postJson('/api/auth/reset-password', [])->assertStatus(422);
    }

    #[DataProvider('apiGuestEndpoints')]
    public function test_guest_endpoints_are_throttled_per_ip(string $path, int $limit): void
    {
        for ($i = 0; $i < $limit; $i++) {
            $this->fromFrontend()->postJson($path, [])->assertStatus(422);
        }

        $this->fromFrontend()->postJson($path, [])
            ->assertStatus(429)
            ->assertJson(['code' => 'rate_limited'])
            ->assertHeader('Retry-After');

        $this->fromFrontend()->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->postJson($path, [])
            ->assertStatus(422);
    }

    #[DataProvider('apiGuestEndpoints')]
    public function test_guest_endpoint_throttle_expires_after_one_minute(string $path, int $limit): void
    {
        // 秒の境目をまたぐと 59 秒後の確認が揺れるため、時刻を固定してから数える。
        $this->freezeTime();

        for ($i = 0; $i < $limit; $i++) {
            $this->fromFrontend()->postJson($path, [])->assertStatus(422);
        }

        $this->travel(59)->seconds();

        $this->fromFrontend()->postJson($path, [])
            ->assertStatus(429)
            ->assertJson(['code' => 'rate_limited']);

        $this->travel(2)->seconds();

        $this->fromFrontend()->postJson($path, [])->assertStatus(422);
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function apiGuestEndpoints(): array
    {
        return [
            'register' => ['/api/auth/register', 6],
            'login' => ['/api/auth/login', 20],
            'forgot-password' => ['/api/auth/forgot-password', 6],
            'reset-password' => ['/api/auth/reset-password', 6],
        ];
    }

    public function test_reset_password_throttle_blocks_valid_token_until_decay(): void
    {
        // 秒の境目をまたぐと 59 秒後の確認が揺れるため、時刻を固定してから数える。
        $this->freezeTime();
        $user = User::factory()->create();
        $payload = [
            'token' => Password::createToken($user),
            'email' => $user->email,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ];

        for ($i = 0; $i < 6; $i++) {
            $this->fromFrontend()->postJson('/api/auth/reset-password', [
                ...$payload,
                'token' => 'invalid',
            ])->assertStatus(422);
        }

        $this->travel(59)->seconds();

        // 制限中は正しい token でも処理されない。
        $this->fromFrontend()->postJson('/api/auth/reset-password', $payload)
            ->assertStatus(429)
            ->assertJson(['code' => 'rate_limited']);
        $this->assertFalse(Hash::check('new-password', $user->fresh()->password));

        $this->travel(2)->seconds();

        $this->fromFrontend()->postJson('/api/auth/reset-password', $payload)
            ->assertOk()
            ->assertJson(['code' => 'password_reset']);
        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
    }

    public function test_reset_password_succeeds_within_the_limit_after_failures(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->fromFrontend()->postJson('/api/auth/reset-password', [
                'token' => 'invalid',
                'email' => $user->email,
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])->assertStatus(422);
        }

        $this->fromFrontend()->postJson('/api/auth/reset-password', [
            'token' => Password::createToken($user),
            'email' => $user->email,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk();
        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
    }

    public function test_forgot_password_throttle_blocks_until_decay(): void
    {
        $this->freezeTime();
        Notification::fake();
        $user = User::factory()->create();

        for ($i = 0; $i < 6; $i++) {
            $this->fromFrontend()->postJson('/api/auth/forgot-password', [])->assertStatus(422);
        }

        $this->travel(59)->seconds();

        $this->fromFrontend()->postJson('/api/auth/forgot-password', ['email' => $user->email])
            ->assertStatus(429);
        Notification::assertNothingSent();

        $this->travel(2)->seconds();

        $this->fromFrontend()->postJson('/api/auth/forgot-password', ['email' => $user->email])
            ->assertOk();
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_web_and_api_guest_endpoints_share_the_same_count(): void
    {
        // Inertia 撤去までの web route が API の制限の抜け道にならないこと。
        for ($i = 0; $i < 6; $i++) {
            $this->post('/forgot-password', []);
        }

        $this->fromFrontend()->postJson('/api/auth/forgot-password', [])
            ->assertStatus(429)
            ->assertJson(['code' => 'rate_limited']);
        $this->post('/forgot-password', [])->assertStatus(429);

        // 別 endpoint は数えない。
        $this->fromFrontend()->postJson('/api/auth/register', [])->assertStatus(422);
    }

    #[DataProvider('guestEndpoints')]
    public function test_web_and_api_guest_endpoint_counts_are_shared(string $webPath, string $apiPath): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->post($webPath, []);
        }

        $this->fromFrontend()->postJson($apiPath, [])
            ->assertStatus(429)
            ->assertJson(['code' => 'rate_limited'])
            ->assertHeader('Retry-After');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function guestEndpoints(): array
    {
        return [
            'register' => ['/register', '/api/auth/register'],
            'forgot-password' => ['/forgot-password', '/api/auth/forgot-password'],
            'reset-password' => ['/reset-password', '/api/auth/reset-password'],
        ];
    }

    /**
     * 認証済み endpoint は user id で数えるため、web（session guard）と API（Sanctum）で
     * 同じ user として解決されることも含めて確認する。
     */
    #[DataProvider('authenticatedEndpoints')]
    public function test_web_and_api_authenticated_endpoint_counts_are_shared(string $webPath, string $apiPath): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user)->post($webPath, []);
        }

        $this->actingAs($user)->fromFrontend()->postJson($apiPath, [])
            ->assertStatus(429)
            ->assertJson(['code' => 'rate_limited'])
            ->assertHeader('Retry-After');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function authenticatedEndpoints(): array
    {
        return [
            'verification-notification' => ['/email/verification-notification', '/api/auth/email/verification-notification'],
            'confirm-password' => ['/confirm-password', '/api/auth/confirm-password'],
        ];
    }

    public function test_web_and_api_login_share_the_per_ip_count(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->post('/login', ['email' => "user{$i}@example.com", 'password' => 'wrong-password']);
        }

        $this->fromFrontend()->postJson('/api/auth/login', [
            'email' => 'user20@example.com',
            'password' => 'wrong-password',
        ])
            ->assertStatus(429)
            ->assertJson(['code' => 'rate_limited'])
            ->assertHeader('Retry-After');
    }

    public function test_web_and_api_login_share_the_lockout_per_email_and_ip(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
        }

        // web の失敗で lock された email は、正しい password でも API から login できない。
        $this->fromFrontend()->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])
            ->assertStatus(429)
            ->assertJson(['code' => 'rate_limited'])
            ->assertHeader('Retry-After');
        $this->assertGuest();
    }

    /**
     * Inertia 撤去までの web 側は標準の 429 画面で済ませる（§5.5.2）。JSON の契約は API だけ。
     */
    public function test_web_throttle_returns_plain_429_to_inertia_requests(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->post('/forgot-password', []);
        }

        $this->withHeader('X-Inertia', 'true')->post('/forgot-password', [])
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertHeaderMissing('X-Inertia');
    }

    public function test_web_login_is_throttled_per_ip_across_emails(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->post('/login', ['email' => "user{$i}@example.com", 'password' => 'wrong-password']);
        }

        $this->post('/login', ['email' => 'user20@example.com', 'password' => 'wrong-password'])
            ->assertStatus(429);
    }

    public function test_login_is_throttled_per_ip_across_emails(): void
    {
        // email+IP の組ごとの失敗 5 回制限だけでは、email を変えれば無制限に試せる。
        for ($i = 0; $i < 20; $i++) {
            $this->fromFrontend()->postJson('/api/auth/login', [
                'email' => "user{$i}@example.com",
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        $this->fromFrontend()->postJson('/api/auth/login', [
            'email' => 'user20@example.com',
            'password' => 'wrong-password',
        ])
            ->assertStatus(429)
            ->assertJson(['code' => 'rate_limited'])
            ->assertHeader('Retry-After');

        $this->fromFrontend()->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->postJson('/api/auth/login', [
                'email' => 'user20@example.com',
                'password' => 'wrong-password',
            ])
            ->assertStatus(422);
    }

    public function test_login_per_ip_throttle_expires_after_decay(): void
    {
        // 秒の境目をまたぐと 59 秒後の確認が揺れるため、時刻を固定してから数える。
        $this->freezeTime();
        $user = User::factory()->create();

        for ($i = 0; $i < 20; $i++) {
            $this->fromFrontend()->postJson('/api/auth/login', [
                'email' => "user{$i}@example.com",
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        $this->travel(59)->seconds();

        $this->fromFrontend()->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(429);

        $this->travel(2)->seconds();

        $this->fromFrontend()->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_authenticated_endpoints_are_throttled_per_user(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user)->fromFrontend()->postJson('/api/auth/confirm-password', []);
        }

        $this->actingAs($user)->fromFrontend()
            ->postJson('/api/auth/confirm-password', [])
            ->assertStatus(429);

        $this->actingAs(User::factory()->create())->fromFrontend()
            ->postJson('/api/auth/confirm-password', [])
            ->assertStatus(422);
    }

    public function test_verification_notification_is_throttled_independently_of_confirm_password(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user)->fromFrontend()->postJson('/api/auth/confirm-password', []);
        }

        $this->actingAs($user)->fromFrontend()
            ->postJson('/api/auth/email/verification-notification')
            ->assertOk();
    }
}
