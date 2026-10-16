<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_can_authenticate_via_the_api(): void
    {
        $user = User::factory()->create();

        $response = $this->fromFrontend()->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertOk();
        $response->assertJson([
            'data' => [
                'id' => $user->id,
                'email' => $user->email,
            ],
            'meta' => [
                'redirect_to' => '/dashboard',
            ],
        ]);
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_returns_intended_verification_callback_as_relative_redirect_to(): void
    {
        $user = User::factory()->create();
        $intended = rtrim(config('app.url'), '/').'/auth/email/verify/1/abc?expires=1&signature=xyz';

        $response = $this->fromFrontend()
            ->withSession(['url.intended' => $intended])
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'password',
            ]);

        $response->assertOk();
        $response->assertJsonPath('meta.redirect_to', '/auth/email/verify/1/abc?expires=1&signature=xyz');
        $response->assertSessionMissing('url.intended');
    }

    /**
     * 外部 host や scheme が付いていても捨て、同一オリジンの相対 path だけを返す。
     */
    #[DataProvider('intendedUrlsWithForeignOrigin')]
    public function test_login_drops_origin_of_intended_verification_callback(string $intended): void
    {
        $user = User::factory()->create();

        $response = $this->fromFrontend()
            ->withSession(['url.intended' => $intended])
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'password',
            ]);

        $response->assertOk();
        $response->assertJsonPath('meta.redirect_to', '/auth/email/verify/1/abc?expires=1&signature=xyz');
        $response->assertSessionMissing('url.intended');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function intendedUrlsWithForeignOrigin(): array
    {
        return [
            'external host' => ['https://evil.example.test/auth/email/verify/1/abc?expires=1&signature=xyz'],
            'protocol-relative' => ['//evil.example.test/auth/email/verify/1/abc?expires=1&signature=xyz'],
            'javascript scheme' => ['javascript:/auth/email/verify/1/abc?expires=1&signature=xyz'],
        ];
    }

    #[DataProvider('intendedUrlsOutsideVerificationCallback')]
    public function test_login_ignores_intended_url_outside_verification_callback(string $intended): void
    {
        $user = User::factory()->create();

        $response = $this->fromFrontend()
            ->withSession(['url.intended' => $intended])
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'password',
            ]);

        $response->assertOk();
        $response->assertJsonPath('meta.redirect_to', '/dashboard');
        $response->assertSessionMissing('url.intended');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function intendedUrlsOutsideVerificationCallback(): array
    {
        return [
            'external page' => ['https://evil.example.test/dashboard'],
            'other external site' => ['https://example.com/other'],
            'prefix lookalike' => ['https://evil.example.test/auth/email/verify-evil'],
            'own page' => ['http://localhost/profile'],
            'javascript scheme' => ['javascript:alert(1)'],
            // ブラウザが `..` を解決すると /profile へ移動してしまう。
            'dot segments' => ['/auth/email/verify/1/../../../profile'],
            'encoded dot segments' => ['/auth/email/verify/%2e%2e/%2e%2e/%2e%2e/profile'],
            'extra segment' => ['/auth/email/verify/1/abc/extra'],
            'missing hash' => ['/auth/email/verify/1'],
            'empty' => [''],
        ];
    }

    public function test_users_can_not_authenticate_with_an_invalid_password(): void
    {
        $user = User::factory()->create();

        $response = $this->fromFrontend()->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);
        $response->assertJson(['code' => 'validation_failed']);
        $response->assertJsonValidationErrors('email');
        $this->assertGuest();
    }

    public function test_login_is_locked_after_five_failures_for_the_same_email_and_ip(): void
    {
        // Retry-After を値で比べるため、時刻を固定して秒の境目をまたがないようにする。
        $this->freezeTime();
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->fromFrontend()->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        // 6 回目は正しい password でも 429。Web 画面と違い email 欄の validation error にはしない。
        $this->fromFrontend()->postJson('/api/auth/login', [
            'email' => strtoupper($user->email),
            'password' => 'password',
        ])
            ->assertStatus(429)
            ->assertJson(['code' => 'rate_limited'])
            ->assertHeader('Retry-After', 60);
        $this->assertGuest();

        // 別 IP からは同じ email でも login できる（email 単位で止めると第三者に締め出されるため）。
        $this->fromFrontend()->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'password',
            ])
            ->assertOk();
    }

    public function test_login_lockout_expires_after_decay(): void
    {
        // 秒の境目をまたぐと 59 秒後の確認が揺れるため、時刻を固定してから数える。
        $this->freezeTime();
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->fromFrontend()->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        $this->travel(59)->seconds();

        // Retry-After は固定値ではなく残り秒数。
        $this->fromFrontend()->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])
            ->assertStatus(429)
            ->assertHeader('Retry-After', 1);

        $this->travel(2)->seconds();

        $this->fromFrontend()->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_lockout_counts_email_case_insensitively(): void
    {
        $user = User::factory()->create(['email' => 'taro@example.com']);

        // 表記ごとに別カウンタなら各 2 回以下で lock されず、最後の login は通ってしまう。
        foreach (['TARO@EXAMPLE.COM', 'Taro@Example.com', 'taro@EXAMPLE.com', 'TARO@EXAMPLE.COM', 'Taro@Example.com'] as $email) {
            $this->fromFrontend()->postJson('/api/auth/login', [
                'email' => $email,
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        $this->fromFrontend()->postJson('/api/auth/login', [
            'email' => 'taro@example.com',
            'password' => 'password',
        ])
            ->assertStatus(429)
            ->assertJson(['code' => 'rate_limited']);
        $this->assertGuest();
    }

    public function test_successful_login_resets_failure_count(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 4; $i++) {
            $this->fromFrontend()->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ]);
        }

        $this->fromFrontend()->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();
        $this->app['auth']->guard('web')->logout();

        for ($i = 0; $i < 5; $i++) {
            $this->fromFrontend()->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }
    }

    public function test_already_authenticated_users_cannot_call_login(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $response = $this->actingAs($user)->fromFrontend()->postJson('/api/auth/login', [
            'email' => $other->email,
            'password' => 'password',
        ]);

        $response->assertStatus(409);
        $response->assertJson(['code' => 'already_authenticated']);
    }

    public function test_users_can_logout_via_the_api(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->fromFrontend()->postJson('/api/auth/logout');

        $response->assertStatus(204);
        $response->assertNoContent();

        // auth:sanctum middleware が Auth::shouldUse('sanctum') を呼ぶため、この時点で
        // デフォルトguardは'sanctum'に変わっている。assertGuest()は
        // default guardを見るため、実際にlogout()したguard('web')を明示して検証する
        // （プロセス内でguardをキャッシュし続けるテストクライアント特有の事情で、
        // 実際のHTTPリクエストではguardが毎回作り直されるため問題にならない）。
        $this->assertGuest('web');
    }

    public function test_guests_cannot_call_logout(): void
    {
        $response = $this->fromFrontend()->postJson('/api/auth/logout');

        $response->assertStatus(401);
        $response->assertJson(['code' => 'unauthenticated']);
    }
}
