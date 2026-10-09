<?php

namespace Tests\Feature\Api;

use App\Http\Middleware\Api\EnsureApiEmailIsVerified;
use App\Models\User;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * `api.verified` middleware（docs/inertia-removal-migration.md §5.5.1）。
 * まだ使う route が無いため、テスト用 route で検査する。
 */
class EnsureApiEmailIsVerifiedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'auth:sanctum', 'api.verified'])
            ->get('/api/__test/verified-only', fn () => response()->json(['ok' => true]));

        // auth:sanctum の付け忘れを模した route。
        Route::middleware(['api', 'api.verified'])
            ->get('/api/__test/verified-without-auth', fn () => response()->json(['ok' => true]));
    }

    public function test_unverified_user_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->getJson('/api/__test/verified-only')
            ->assertForbidden()
            ->assertJson(['code' => 'email_unverified']);
    }

    public function test_verified_user_passes(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/__test/verified-only')
            ->assertOk()
            ->assertExactJson(['ok' => true]);
    }

    public function test_guest_is_rejected_as_unauthenticated_before_verification_check(): void
    {
        $this->getJson('/api/__test/verified-only')
            ->assertUnauthorized()
            ->assertJson(['code' => 'unauthenticated']);
    }

    public function test_guest_is_rejected_even_without_auth_sanctum(): void
    {
        $this->getJson('/api/__test/verified-without-auth')
            ->assertUnauthorized()
            ->assertExactJson(['code' => 'unauthenticated', 'message' => '認証が必要です。']);
    }

    public function test_auth_runs_before_verification_check_regardless_of_route_order(): void
    {
        // 実行順は route の記述順ではなく $middlewarePriority で決まる（§5.5.1）。
        // api.verified を priority 一覧の auth より前に足すと落ちる。
        $route = Route::middleware(['api', 'api.verified', 'auth:sanctum'])
            ->get('/api/__test/reversed', fn () => 'ok');

        $middleware = array_map(
            fn ($m) => explode(':', $m)[0],
            $this->app['router']->gatherRouteMiddleware($route),
        );

        $this->assertSame(
            [Authenticate::class, EnsureApiEmailIsVerified::class],
            array_values(array_intersect($middleware, [Authenticate::class, EnsureApiEmailIsVerified::class])),
        );
    }
}
