<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_login_screen_lockout_stays_a_validation_error(): void
    {
        // API は 429 だが、Inertia の画面は撤去まで従来どおり email 欄のエラーで表示する。
        // 文言の秒数を値で比べるため、時刻を固定して秒の境目をまたがないようにする。
        $this->freezeTime();
        Event::fake([Lockout::class]);
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
                ->assertSessionHasErrors(['email' => trans('auth.failed')]);
        }
        Event::assertNotDispatched(Lockout::class);

        // 正しい password でも弾かれ、そのエラーが auth.failed ではなくロックアウト由来であること。
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(302)
            ->assertSessionHasErrors(['email' => trans('auth.throttle', ['seconds' => 60, 'minutes' => 1])]);
        $this->assertGuest();
        Event::assertDispatchedTimes(Lockout::class, 1);

        // 同じロックアウトを API から叩くと 429 になる（web だけ 422 扱いのまま）。
        $this->fromFrontend()->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])
            ->assertStatus(429)
            ->assertJson(['code' => 'rate_limited'])
            ->assertHeader('Retry-After', 60);
        $this->assertGuest();

        // decay 経過後は正しい password で login できる。
        $this->travel(61)->seconds();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }
}
