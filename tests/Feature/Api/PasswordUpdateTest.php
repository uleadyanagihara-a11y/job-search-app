<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_can_be_updated(): void
    {
        $user = User::factory()->create();
        $originalRememberToken = $user->remember_token;

        $response = $this->actingAs($user)->fromFrontend()->putJson('/api/profile/password', [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertNoContent();

        $user->refresh();

        $this->assertTrue(Hash::check('new-password', $user->password));
        // 他端末の remember cookie を無効化するため remember_token が再生成されていること。
        $this->assertNotSame($originalRememberToken, $user->remember_token);
    }

    public function test_updating_password_invalidates_sessions_on_other_devices_but_keeps_the_current_one(): void
    {
        $user = User::factory()->create();
        // AuthenticateSession（Sanctum stateful pipeline）が各 session に保存する旧 password の hash。
        $oldPasswordHash = Auth::guard('web')->hashPasswordForCookie($user->password);

        // 変更操作をした端末: 旧 hash を持つ session から更新し、新 hash に書き換えられて残る。
        $response = $this->actingAs($user)->fromFrontend()
            ->withSession(['password_hash_web' => $oldPasswordHash])
            ->putJson('/api/profile/password', [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response->assertNoContent();
        $response->assertSessionHas(
            'password_hash_web',
            Auth::guard('web')->hashPasswordForCookie($user->refresh()->password),
        );

        // 他端末: 旧 hash のままの session は次の API request で 401 になり session も破棄される。
        $this->flushSession();

        $response = $this->actingAs($user)->fromFrontend()
            ->withSession(['password_hash_web' => $oldPasswordHash])
            ->getJson('/api/user');

        $response->assertStatus(401);
        $response->assertJson(['code' => 'unauthenticated']);
        $response->assertSessionMissing('password_hash_web');
    }

    public function test_correct_password_must_be_provided_to_update_password(): void
    {
        $user = User::factory()->create();
        $originalRememberToken = $user->remember_token;

        $response = $this->actingAs($user)->fromFrontend()->putJson('/api/profile/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertStatus(422);
        $response->assertJson(['code' => 'validation_failed']);
        $response->assertJsonValidationErrors('current_password');

        $user->refresh();

        $this->assertTrue(Hash::check('password', $user->password));
        $this->assertSame($originalRememberToken, $user->remember_token);
    }

    public function test_password_confirmation_must_match(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->fromFrontend()->putJson('/api/profile/password', [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'different-password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('password');
        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    public function test_password_update_requires_authentication(): void
    {
        $this->fromFrontend()->putJson('/api/profile/password', [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
            ->assertStatus(401)
            ->assertJson(['code' => 'unauthenticated']);
    }
}
