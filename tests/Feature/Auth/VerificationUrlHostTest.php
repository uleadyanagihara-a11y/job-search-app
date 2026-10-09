<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * メール認証リンクの host/scheme が request header から作られないこと（host header poisoning）。
 * Nginx は client の Host を X-Forwarded-Host に転送し、trustProxies('*') がそれを信用する。
 */
class VerificationUrlHostTest extends TestCase
{
    use RefreshDatabase;

    private const ATTACKER_HEADERS = [
        'X-Forwarded-Host' => 'evil.example.test',
        'X-Forwarded-Proto' => 'https',
    ];

    public function test_registration_mail_link_ignores_forwarded_host(): void
    {
        Notification::fake();

        $this->fromFrontend()->withHeaders(self::ATTACKER_HEADERS)
            ->postJson('/api/auth/register', [
                'name' => 'Victim',
                'email' => 'victim@example.com',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])->assertSuccessful();

        $this->assertLinkPointsToApplicationUrl(User::where('email', 'victim@example.com')->firstOrFail());
    }

    public function test_resent_mail_link_ignores_forwarded_host(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->fromFrontend()->withHeaders(self::ATTACKER_HEADERS)
            ->postJson('/api/auth/email/verification-notification')
            ->assertOk();

        $this->assertLinkPointsToApplicationUrl($user);
    }

    public function test_resent_mail_link_ignores_host_header(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        // テストクライアントは HTTP_HOST を URL の host で上書きするため、絶対 URL で Host を変える。
        $this->actingAs($user)->fromFrontend()
            ->postJson('http://evil.example.test/api/auth/email/verification-notification')
            ->assertOk();

        $this->assertLinkPointsToApplicationUrl($user);
    }

    private function assertLinkPointsToApplicationUrl(User $user): void
    {
        // toMail() は request 後に呼ぶが、URL generator は直前の request を保持しているため
        // 対策が無ければ request の host がここに現れる。
        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user) {
            $this->assertStringStartsWith(
                rtrim(config('app.url'), '/').'/auth/email/verify/',
                $notification->toMail($user)->actionUrl,
            );

            return true;
        });
    }
}
