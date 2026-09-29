<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->fromFrontend()->putJson('/api/profile/password', [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertNoContent();
        $this->assertTrue(Hash::check('new-password', $user->refresh()->password));
    }

    public function test_correct_password_must_be_provided_to_update_password(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->fromFrontend()->putJson('/api/profile/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertStatus(422);
        $response->assertJson(['code' => 'validation_failed']);
        $response->assertJsonValidationErrors('current_password');
        $this->assertTrue(Hash::check('password', $user->refresh()->password));
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
