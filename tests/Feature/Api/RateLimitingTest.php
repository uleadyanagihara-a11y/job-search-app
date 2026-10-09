<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
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

    public function test_guest_endpoints_are_throttled_per_ip(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->fromFrontend()->postJson('/api/auth/forgot-password', []);
        }

        $this->fromFrontend()->postJson('/api/auth/forgot-password', [])
            ->assertStatus(429)
            ->assertJson(['code' => 'rate_limited']);

        $this->fromFrontend()->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->postJson('/api/auth/forgot-password', [])
            ->assertStatus(422);
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
