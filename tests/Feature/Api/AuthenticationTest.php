<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * SanctumはReferer/Originがsanctum.statefulに一致するリクエストだけを
     * 「frontend」として扱い、session middlewareを有効化する。login/logoutは
     * sessionを書き換えるため、SPAからのリクエストを模してヘッダーを付与する。
     */
    private function fromFrontend(): TestCase
    {
        return $this->withHeader('Referer', config('app.url'));
    }

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
