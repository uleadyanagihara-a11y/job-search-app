<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PasswordConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_can_be_confirmed(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->fromFrontend()
            ->postJson('/api/auth/confirm-password', ['password' => 'password']);

        $response->assertNoContent();
        $response->assertSessionHas('auth.password_confirmed_at');
    }

    public function test_password_is_not_confirmed_with_an_invalid_password(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->fromFrontend()
            ->postJson('/api/auth/confirm-password', ['password' => 'wrong-password']);

        $response->assertStatus(422)->assertJsonValidationErrors('password');
        $response->assertSessionMissing('auth.password_confirmed_at');
    }

    public function test_confirm_password_requires_authentication(): void
    {
        $this->fromFrontend()->postJson('/api/auth/confirm-password', ['password' => 'password'])
            ->assertStatus(401)
            ->assertJson(['code' => 'unauthenticated']);
    }
}
