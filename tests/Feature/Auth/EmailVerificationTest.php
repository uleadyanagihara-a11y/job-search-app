<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * 署名付きメール認証 Web callback（docs/inertia-removal-migration.md §5.4）。
 */
class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const FRONTEND_URL = 'https://spa.example.test';

    protected function setUp(): void
    {
        parent::setUp();

        // redirect 先が APP_URL ではなく FRONTEND_URL 基準であることを区別できるようにする。
        config(['app.frontend_url' => self::FRONTEND_URL]);
    }

    public function test_email_verification_screen_can_be_rendered(): void
    {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->get('/verify-email');

        $response->assertStatus(200);
    }

    public function test_verification_mail_points_to_the_callback_path(): void
    {
        $user = User::factory()->unverified()->create();

        $mail = (new VerifyEmail)->toMail($user);

        $this->assertStringStartsWith(
            rtrim(config('app.url'), '/').'/auth/email/verify/'.$user->id.'/',
            $mail->actionUrl,
        );
    }

    public function test_email_can_be_verified(): void
    {
        $user = User::factory()->unverified()->create();

        Event::fake();

        $response = $this->actingAs($user)->get($this->verificationUrl($user));

        Event::assertDispatched(Verified::class);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $response->assertRedirect(self::FRONTEND_URL.'/dashboard?verified=1');
    }

    public function test_already_verified_email_is_not_verified_again(): void
    {
        $user = User::factory()->create();
        $verifiedAt = $user->email_verified_at;

        Event::fake();

        $response = $this->actingAs($user)->get($this->verificationUrl($user));

        Event::assertNotDispatched(Verified::class);
        $this->assertEquals($verifiedAt, $user->fresh()->email_verified_at);
        $response->assertRedirect(self::FRONTEND_URL.'/dashboard?verified=1');
    }

    public function test_email_is_not_verified_with_invalid_hash(): void
    {
        $user = User::factory()->unverified()->create();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1('wrong-email')]
        );

        $response = $this->actingAs($user)->get($verificationUrl);

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $response->assertRedirect(self::FRONTEND_URL.'/verify-email?error=invalid-or-expired');
    }

    public function test_email_is_not_verified_with_tampered_signature(): void
    {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->get($this->verificationUrl($user).'tampered');

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $response->assertRedirect(self::FRONTEND_URL.'/verify-email?error=invalid-or-expired');
    }

    public function test_email_is_not_verified_with_expired_link(): void
    {
        $user = User::factory()->unverified()->create();
        $verificationUrl = $this->verificationUrl($user);

        $this->travel(61)->minutes();

        $response = $this->actingAs($user)->get($verificationUrl);

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $response->assertRedirect(self::FRONTEND_URL.'/verify-email?error=invalid-or-expired');
    }

    public function test_link_of_another_user_is_rejected_before_checking_verified_state(): void
    {
        $owner = User::factory()->unverified()->create();
        // ログイン中の user が verified でも「認証済み」扱いにせず user-mismatch を返す。
        $other = User::factory()->create();

        Event::fake();

        $response = $this->actingAs($other)->get($this->verificationUrl($owner));

        Event::assertNotDispatched(Verified::class);
        $this->assertFalse($owner->fresh()->hasVerifiedEmail());
        $response->assertRedirect(self::FRONTEND_URL.'/verify-email?error=user-mismatch');
    }

    public function test_guest_is_redirected_to_spa_login_with_intended_callback(): void
    {
        $user = User::factory()->unverified()->create();
        $verificationUrl = $this->verificationUrl($user);

        $response = $this->get($verificationUrl);

        $response->assertRedirect(self::FRONTEND_URL.'/login?verification_required=1');
        $response->assertSessionHas('url.intended', $verificationUrl);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_guest_with_invalid_signature_is_rejected_without_storing_intended(): void
    {
        $user = User::factory()->unverified()->create();

        $response = $this->get($this->verificationUrl($user).'tampered');

        $response->assertRedirect(self::FRONTEND_URL.'/verify-email?error=invalid-or-expired');
        $response->assertSessionMissing('url.intended');
    }

    public function test_callback_is_throttled(): void
    {
        $user = User::factory()->unverified()->create();
        $verificationUrl = $this->verificationUrl($user).'tampered';

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user)->get($verificationUrl)
                ->assertRedirect(self::FRONTEND_URL.'/verify-email?error=invalid-or-expired');
        }

        $this->actingAs($user)->get($verificationUrl)
            ->assertRedirect(self::FRONTEND_URL.'/verify-email?error=too-many-requests');
    }

    public function test_callback_throttle_counts_guest_requests(): void
    {
        $user = User::factory()->unverified()->create();
        $verificationUrl = $this->verificationUrl($user);

        for ($i = 0; $i < 6; $i++) {
            $this->get($verificationUrl)
                ->assertRedirect(self::FRONTEND_URL.'/login?verification_required=1');
        }

        // 7 回目は正しいリンクでも throttle が signed/auth より先に弾く。
        $this->get($verificationUrl)
            ->assertRedirect(self::FRONTEND_URL.'/verify-email?error=too-many-requests');
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_guest_can_verify_after_logging_in_via_api(): void
    {
        $user = User::factory()->unverified()->create();
        $verificationUrl = $this->verificationUrl($user);

        $this->get($verificationUrl)
            ->assertRedirect(self::FRONTEND_URL.'/login?verification_required=1');

        $redirectTo = $this->fromFrontend()->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->json('meta.redirect_to');

        $this->assertSame(
            parse_url($verificationUrl, PHP_URL_PATH).'?'.parse_url($verificationUrl, PHP_URL_QUERY),
            $redirectTo,
        );

        $this->get($redirectTo)->assertRedirect(self::FRONTEND_URL.'/dashboard?verified=1');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    private function verificationUrl(User $user): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())]
        );
    }
}
