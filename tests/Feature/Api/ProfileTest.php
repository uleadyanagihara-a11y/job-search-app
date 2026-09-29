<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_can_be_fetched(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/profile');

        $response->assertOk();
        $response->assertExactJson([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at->clone()->utc()->format('Y-m-d\TH:i:s\Z'),
            ],
        ]);
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->fromFrontend()->patchJson('/api/profile', [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $response->assertOk();
        $response->assertJson([
            'data' => [
                'id' => $user->id,
                'name' => 'Test User',
                'email' => 'test@example.com',
                'email_verified_at' => null,
            ],
        ]);

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->fromFrontend()->patchJson('/api/profile', [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.email_verified_at', fn ($value) => $value !== null);
        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_profile_cannot_be_updated_to_an_email_taken_by_another_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $response = $this->actingAs($user)->fromFrontend()->patchJson('/api/profile', [
            'name' => 'Test User',
            'email' => $other->email,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['code' => 'validation_failed']);
        $response->assertJsonValidationErrors('email');
        $this->assertNotSame($other->email, $user->refresh()->email);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->fromFrontend()->deleteJson('/api/profile', [
            'password' => 'password',
        ]);

        $response->assertNoContent();
        // auth:sanctum が default guard を sanctum に切り替えるため、実際に logout した
        // web guard を明示して検証する（Api/AuthenticationTest の logout と同じ事情）。
        $this->assertGuest('web');
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->fromFrontend()->deleteJson('/api/profile', [
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);
        $response->assertJson(['code' => 'validation_failed']);
        $response->assertJsonValidationErrors('password');
        $this->assertNotNull($user->fresh());
    }

    public function test_guests_cannot_access_profile_endpoints(): void
    {
        $this->getJson('/api/profile')
            ->assertStatus(401)
            ->assertJson(['code' => 'unauthenticated']);

        $this->fromFrontend()->patchJson('/api/profile', ['name' => 'x', 'email' => 'x@example.com'])
            ->assertStatus(401)
            ->assertJson(['code' => 'unauthenticated']);

        $this->fromFrontend()->deleteJson('/api/profile', ['password' => 'password'])
            ->assertStatus(401)
            ->assertJson(['code' => 'unauthenticated']);
    }
}
