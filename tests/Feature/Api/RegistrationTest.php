<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function fromFrontend(): TestCase
    {
        return $this->withHeader('Referer', config('app.url'));
    }

    public function test_users_can_register(): void
    {
        Notification::fake();

        $response = $this->fromFrontend()->postJson('/api/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'data' => ['email' => 'test@example.com', 'email_verified_at' => null],
            'meta' => ['redirect_to' => '/dashboard'],
        ]);
        $this->assertAuthenticated();
        Notification::assertSentTo(User::first(), VerifyEmail::class);
    }

    public function test_registration_validates_input(): void
    {
        User::factory()->create(['email' => 'test@example.com']);

        $response = $this->fromFrontend()->postJson('/api/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'mismatch',
        ]);

        $response->assertStatus(422);
        $response->assertJson(['code' => 'validation_failed']);
        $response->assertJsonValidationErrors(['email', 'password']);
        $this->assertGuest();
    }

    public function test_authenticated_users_cannot_register(): void
    {
        $response = $this->actingAs(User::factory()->create())->fromFrontend()
            ->postJson('/api/auth/register', []);

        $response->assertStatus(409)->assertJson(['code' => 'already_authenticated']);
    }

    public function test_register_is_throttled(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->fromFrontend()->postJson('/api/auth/register', []);
        }

        $this->fromFrontend()->postJson('/api/auth/register', [])
            ->assertStatus(429)
            ->assertJson(['code' => 'rate_limited']);
    }
}
