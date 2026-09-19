<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private function fromFrontend(): TestCase
    {
        return $this->withHeader('Referer', config('app.url'));
    }

    public function test_reset_link_is_sent_with_spa_url(): void
    {
        Notification::fake();
        config(['app.frontend_url' => 'http://localhost:8081']);
        $user = User::factory()->create(['email' => 'a+b@example.com']);

        $this->fromFrontend()->postJson('/api/auth/forgot-password', ['email' => $user->email])
            ->assertOk()
            ->assertJson(['code' => 'password_reset_link_sent']);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user) {
            $this->assertSame(
                'http://localhost:8081/reset-password/'.$n->token.'?email=a%2Bb%40example.com',
                $n->toMail($user)->actionUrl,
            );

            return true;
        });
    }

    public function test_forgot_password_does_not_reveal_unknown_emails(): void
    {
        Notification::fake();

        $known = User::factory()->create();
        $a = $this->fromFrontend()->postJson('/api/auth/forgot-password', ['email' => $known->email]);
        $b = $this->fromFrontend()->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.com']);
        // 送信抑制中（RESET_THROTTLED）も同じ応答になる。
        $c = $this->fromFrontend()->postJson('/api/auth/forgot-password', ['email' => $known->email]);

        $this->assertSame($a->getContent(), $b->getContent());
        $this->assertSame($a->getContent(), $c->getContent());
        Notification::assertNothingSentTo(User::factory()->make(['email' => 'nobody@example.com']));
    }

    public function test_password_can_be_reset_with_a_valid_token(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $this->fromFrontend()->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk()->assertJson(['code' => 'password_reset']);

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
    }

    public function test_reset_rotates_the_remember_token_and_dispatches_event(): void
    {
        Event::fake([PasswordReset::class]);
        $user = User::factory()->create(['remember_token' => 'old-remember-token']);

        $this->fromFrontend()->postJson('/api/auth/reset-password', [
            'token' => Password::createToken($user),
            'email' => $user->email,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk();

        $token = $user->fresh()->remember_token;
        $this->assertNotSame('old-remember-token', $token);
        $this->assertSame(60, strlen($token));
        Event::assertDispatched(PasswordReset::class);
    }

    public function test_reset_with_invalid_token_returns_token_error(): void
    {
        $user = User::factory()->create();

        $response = $this->fromFrontend()->postJson('/api/auth/reset-password', [
            'token' => 'invalid',
            'email' => $user->email,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('token');
        $this->assertFalse(Hash::check('new-password', $user->fresh()->password));
    }

    public function test_reset_with_unknown_email_is_indistinguishable_from_invalid_token(): void
    {
        $response = $this->fromFrontend()->postJson('/api/auth/reset-password', [
            'token' => 'invalid',
            'email' => 'nobody@example.com',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('token');
        $response->assertJsonMissingValidationErrors('email');
    }
}
