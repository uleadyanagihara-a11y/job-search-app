<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_fetch_the_current_user(): void
    {
        $response = $this->getJson('/api/user');

        $response->assertStatus(401);
        $response->assertJson([
            'code' => 'unauthenticated',
        ]);
    }

    /**
     * API token は使わない（personal_access_tokens テーブルも無い）ため、bearer token は
     * 照合せず無視する。照合すると存在しないテーブルへの SQL で 500 になる。
     */
    #[DataProvider('bearerTokens')]
    public function test_bearer_token_is_ignored_and_guest_gets_401(string $token): void
    {
        $response = $this->withToken($token)->getJson('/api/user');

        $response->assertStatus(401);
        $response->assertJson([
            'code' => 'unauthenticated',
        ]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function bearerTokens(): array
    {
        return [
            'plain token' => ['abc'],
            'sanctum id|token format' => ['1|'.str_repeat('a', 40)],
        ];
    }

    public function test_session_authenticated_user_is_not_affected_by_bearer_token(): void
    {
        $user = User::factory()->create();

        $response = $this->fromFrontend()
            ->actingAs($user)
            ->withToken('1|'.str_repeat('a', 40))
            ->getJson('/api/user');

        $response->assertOk();
        $response->assertJsonPath('data.id', $user->id);
    }

    public function test_authenticated_user_can_fetch_their_own_resource(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/user');

        $response->assertOk();
        $response->assertJson([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
        $response->assertJsonPath('data.email_verified_at', fn ($value) => $value !== null);
        $response->assertJsonMissingPath('data.password');
        $response->assertJsonMissingPath('data.remember_token');
    }

    public function test_unverified_user_resource_reports_null_email_verified_at(): void
    {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->getJson('/api/user');

        $response->assertOk();
        $response->assertJsonPath('data.email_verified_at', null);
    }
}
