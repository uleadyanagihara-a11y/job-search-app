<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\ThrottleEmailVerificationCallback;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ValidateSignature;
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

    public function test_url_in_verification_mail_verifies_email(): void
    {
        $user = User::factory()->unverified()->create();

        // 他のテストは verificationUrl() で自作した URL を使うため、VerifyEmail が実際に
        // 生成する URL（createUrlUsing の差し替えや有効期限設定を含む）で往復させる。
        $actionUrl = (new VerifyEmail)->toMail($user)->actionUrl;

        $this->actingAs($user)->get($actionUrl)
            ->assertRedirect(self::FRONTEND_URL.'/dashboard?verified=1');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_url_in_verification_mail_expires_after_60_minutes(): void
    {
        $early = User::factory()->unverified()->create();
        $late = User::factory()->unverified()->create();
        $earlyUrl = (new VerifyEmail)->toMail($early)->actionUrl;
        $lateUrl = (new VerifyEmail)->toMail($late)->actionUrl;

        $this->travel(59)->minutes();
        $this->actingAs($early)->get($earlyUrl)
            ->assertRedirect(self::FRONTEND_URL.'/dashboard?verified=1');

        $this->travel(2)->minutes();
        $this->actingAs($late)->get($lateUrl)
            ->assertRedirect(self::FRONTEND_URL.'/verify-email?error=invalid-or-expired');
        $this->assertFalse($late->fresh()->hasVerifiedEmail());
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

    public function test_link_sent_before_email_change_does_not_verify_new_email(): void
    {
        $user = User::factory()->unverified()->create();
        $oldLink = $this->verificationUrl($user);

        $this->actingAs($user)->fromFrontend()->patchJson('/api/profile', [
            'name' => $user->name,
            'email' => 'changed@example.com',
        ])->assertOk();

        // auth:sanctum が default guard を sanctum に切り替えたまま残る（テストは同じ app を
        // 使い回すため）。本番はリクエストごとに app が作り直されるので web に戻す。
        $this->app['auth']->shouldUse('web');

        $this->actingAs($user)->get($oldLink)
            ->assertRedirect(self::FRONTEND_URL.'/verify-email?error=invalid-or-expired');
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_verified_user_with_stale_hash_is_not_treated_as_verified(): void
    {
        // hash 判定は verified 判定より先。古いリンクで「認証済み」と見せない。
        $user = User::factory()->create();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1('old@example.com')]
        );

        $this->actingAs($user)->get($verificationUrl)
            ->assertRedirect(self::FRONTEND_URL.'/verify-email?error=invalid-or-expired');
    }

    public function test_signed_link_with_swapped_id_and_hash_is_rejected_by_signature(): void
    {
        $owner = User::factory()->unverified()->create();
        $other = User::factory()->unverified()->create();

        // 署名はそのままで id と hash を $other のものに差し替える。controller の id/hash 判定は
        // 通ってしまうため、signed が無ければ $other が認証される。
        $verificationUrl = str_replace(
            '/auth/email/verify/'.$owner->id.'/'.sha1($owner->getEmailForVerification()),
            '/auth/email/verify/'.$other->id.'/'.sha1($other->getEmailForVerification()),
            $this->verificationUrl($owner),
        );

        $response = $this->actingAs($other)->get($verificationUrl);

        $this->assertFalse($owner->fresh()->hasVerifiedEmail());
        $this->assertFalse($other->fresh()->hasVerifiedEmail());
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
        $this->flushSession();

        $this->get($verificationUrl)
            ->assertRedirect(self::FRONTEND_URL.'/verify-email?error=too-many-requests')
            ->assertSessionMissing('url.intended');
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_throttled_valid_link_does_not_verify(): void
    {
        $user = User::factory()->unverified()->create();
        $tamperedUrl = $this->verificationUrl($user).'tampered';

        Event::fake();

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user)->get($tamperedUrl);
        }

        // ログイン済み・正しいリンクで、throttle 以外に止めるものが無い状態でも認証させない。
        $this->actingAs($user)->get($this->verificationUrl($user))
            ->assertRedirect(self::FRONTEND_URL.'/verify-email?error=too-many-requests');
        Event::assertNotDispatched(Verified::class);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_callback_throttle_resets_after_decay(): void
    {
        $user = User::factory()->unverified()->create();
        $tamperedUrl = $this->verificationUrl($user).'tampered';

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user)->get($tamperedUrl);
        }

        $this->travel(61)->seconds();

        $this->actingAs($user)->get($this->verificationUrl($user))
            ->assertRedirect(self::FRONTEND_URL.'/dashboard?verified=1');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_callback_throttle_is_counted_per_ip(): void
    {
        $user = User::factory()->unverified()->create();
        $tamperedUrl = $this->verificationUrl($user).'tampered';

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user)->get($tamperedUrl);
        }

        $this->actingAs($user)
            ->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->get($this->verificationUrl($user))
            ->assertRedirect(self::FRONTEND_URL.'/dashboard?verified=1');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_callback_middleware_runs_throttle_then_signed_then_auth(): void
    {
        // 実行順は route の記述順ではなく $middlewarePriority で決まり、`route:list -v` にも
        // 出ないため、並べ替え後の実際の順序を検査する（§5.4/§5.5.1）。
        // Breeze の上書き等で bootstrap/app.php の priority 設定が消えると落ちる。
        $router = $this->app['router'];
        $middleware = $router->gatherRouteMiddleware(
            $router->getRoutes()->getByName('verification.verify'),
        );

        $this->assertSame(
            [ThrottleEmailVerificationCallback::class, ValidateSignature::class, Authenticate::class],
            array_values(array_intersect($middleware, [
                ThrottleEmailVerificationCallback::class,
                ValidateSignature::class,
                Authenticate::class,
            ])),
        );
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
