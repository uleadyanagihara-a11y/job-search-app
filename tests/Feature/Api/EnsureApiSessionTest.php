<?php

namespace Tests\Feature\Api;

use App\Http\Middleware\Api\EnsureApiGuest;
use App\Http\Middleware\Api\EnsureApiSession;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `api.session` middleware（docs/inertia-removal-migration.md §5.5.1）。
 * fromFrontend() を付けない request は Sanctum が stateful と判定せず session が無い。
 */
class EnsureApiSessionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, string, list<class-string>}>
     */
    public static function sessionOnlyRoutes(): array
    {
        $guest = [EnsureFrontendRequestsAreStateful::class, ThrottleRequests::class, EnsureApiGuest::class, EnsureApiSession::class];

        return [
            'register' => ['POST', '/api/auth/register', $guest],
            'login' => ['POST', '/api/auth/login', $guest],
            'logout' => ['POST', '/api/auth/logout', [EnsureFrontendRequestsAreStateful::class, Authenticate::class, EnsureApiSession::class]],
            'confirm-password' => ['POST', '/api/auth/confirm-password', [EnsureFrontendRequestsAreStateful::class, Authenticate::class, ThrottleRequests::class, EnsureApiSession::class]],
            'delete profile' => ['DELETE', '/api/profile', [EnsureFrontendRequestsAreStateful::class, Authenticate::class, ThrottleRequests::class, EnsureApiSession::class]],
        ];
    }

    /**
     * @param  list<class-string>  $expected
     */
    #[DataProvider('sessionOnlyRoutes')]
    public function test_session_is_checked_after_stateful_auth_throttle_and_guest(string $method, string $uri, array $expected): void
    {
        // priority 一覧に無い middleware（api.guest/api.session）は無条件に最後へ回るのではなく
        // 相対位置を保つため、記述順の変更でも順序が崩れ得る。並べ替え後の実際の順序を検査する。
        // FormRequest と controller は middleware の後に解決されるので、ここに並ぶものより必ず後になる。
        $router = $this->app['router'];
        $route = $router->getRoutes()->match(Request::create($uri, $method));

        $middleware = array_map(
            fn (string $m) => explode(':', $m, 2)[0],
            $router->gatherRouteMiddleware($route),
        );

        $this->assertSame($expected, array_values(array_intersect($middleware, [
            EnsureFrontendRequestsAreStateful::class,
            Authenticate::class,
            ThrottleRequests::class,
            EnsureApiGuest::class,
            EnsureApiSession::class,
        ])));
    }

    public function test_register_without_session_is_rejected_before_creating_user(): void
    {
        Event::fake([Registered::class]);

        $this->postJson('/api/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertBadRequest()->assertExactJson([
            'code' => 'session_required',
            'message' => 'この操作にはブラウザのセッションが必要です。ページを再読み込みしてください。',
        ]);

        $this->assertDatabaseCount('users', 0);
        Event::assertNotDispatched(Registered::class);
        $this->assertGuest();
    }

    public function test_register_without_session_is_rejected_before_validation(): void
    {
        $this->postJson('/api/auth/register', [])
            ->assertBadRequest()
            ->assertJson(['code' => 'session_required']);
    }

    public function test_login_without_session_does_not_reveal_whether_password_is_correct(): void
    {
        $user = User::factory()->create();

        foreach (['password', 'wrong-password'] as $password) {
            $this->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => $password,
            ])->assertBadRequest()->assertJson(['code' => 'session_required']);
        }

        $this->assertGuest();
    }

    public function test_login_without_session_is_rejected_before_validation(): void
    {
        $this->postJson('/api/auth/login', [])
            ->assertBadRequest()
            ->assertJson(['code' => 'session_required']);
    }

    public function test_sessionless_request_still_consumes_rate_limit(): void
    {
        // throttle が api.session より先に動くため、session 無しの request も回数に数える。
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/auth/register', [])->assertBadRequest();
        }

        $this->postJson('/api/auth/register', [])
            ->assertTooManyRequests()
            ->assertJson(['code' => 'rate_limited']);
    }

    #[DataProvider('authenticatedSessionOnlyRoutes')]
    public function test_guest_without_session_is_unauthenticated_before_session_check(string $method, string $uri): void
    {
        $this->json($method, $uri, ['password' => 'password'])
            ->assertUnauthorized()
            ->assertJson(['code' => 'unauthenticated']);
    }

    #[DataProvider('authenticatedSessionOnlyRoutes')]
    public function test_user_authenticated_without_session_is_rejected_as_session_only(string $method, string $uri): void
    {
        // 将来 token 認証を入れても、これらは session 専用 endpoint のまま 400 を返す（§5.5.2）。
        // User は HasApiTokens を持たないため Sanctum::actingAs は使えず、guard へ直接 user を載せる。
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->json($method, $uri, ['password' => 'password'])
            ->assertBadRequest()
            ->assertJson(['code' => 'session_required']);

        $this->assertNotNull($user->fresh());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function authenticatedSessionOnlyRoutes(): array
    {
        return [
            'logout' => ['POST', '/api/auth/logout'],
            'confirm-password' => ['POST', '/api/auth/confirm-password'],
            'delete profile' => ['DELETE', '/api/profile'],
        ];
    }

    public function test_password_confirm_middleware_returns_session_required_without_session(): void
    {
        // api.session と併用しない route でも、session 無しは 423 ではなく同じ 400 になる。
        Route::middleware(['api', 'auth:sanctum', 'api.password.confirm'])
            ->get('/api/__test/password-confirmed-only', fn () => response()->json(['ok' => true]));

        $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/__test/password-confirmed-only')
            ->assertBadRequest()
            ->assertJson(['code' => 'session_required']);
    }

    public function test_password_confirm_middleware_still_requires_confirmation_with_session(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'api.password.confirm'])
            ->get('/api/__test/password-confirmed-only', fn () => response()->json(['ok' => true]));

        $this->actingAs(User::factory()->create())
            ->fromFrontend()
            ->getJson('/api/__test/password-confirmed-only')
            ->assertStatus(423)
            ->assertJson(['code' => 'password_confirmation_required']);
    }
}
