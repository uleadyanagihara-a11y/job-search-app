<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EmailVerificationNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function fromFrontend(): TestCase
    {
        return $this->withHeader('Referer', config('app.url'));
    }

    public function test_verification_notification_can_be_resent(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->fromFrontend()
            ->postJson('/api/auth/email/verification-notification')
            ->assertOk()
            ->assertJson(['code' => 'verification_link_sent']);

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_verification_notification_is_rejected_for_verified_users(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->fromFrontend()
            ->postJson('/api/auth/email/verification-notification')
            ->assertStatus(409)
            ->assertJson(['code' => 'conflict']);

        Notification::assertNothingSent();
    }

    public function test_verification_notification_requires_authentication(): void
    {
        $this->fromFrontend()->postJson('/api/auth/email/verification-notification')
            ->assertStatus(401)
            ->assertJson(['code' => 'unauthenticated']);
    }
}
