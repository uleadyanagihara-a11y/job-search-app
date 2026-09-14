<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
