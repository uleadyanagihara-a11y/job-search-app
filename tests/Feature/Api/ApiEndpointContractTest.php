<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 全 API endpoint の異常系 response が JSON error contract（docs/inertia-removal-migration.md §5.1）
 * に従うことを横断的に確認する。endpoint 固有の振る舞いは endpoint ごとの test で確認する。
 *
 * ENDPOINTS に無い API route を追加すると test_every_api_route_is_listed が失敗するので、
 * 追加時はここに access・limiter・必須 field を登録する。
 */
class ApiEndpointContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * access: auth = auth:sanctum、guest = api.guest。
     * required: 空 body で送ったときに validation error になる field。
     *
     * @var array<string, array{access: 'auth'|'guest', limiter: string|null, required: list<string>}>
     */
    private const ENDPOINTS = [
        'GET /api/user' => ['access' => 'auth', 'limiter' => null, 'required' => []],
        'POST /api/auth/register' => ['access' => 'guest', 'limiter' => 'auth.register', 'required' => ['name', 'email', 'password']],
        'POST /api/auth/login' => ['access' => 'guest', 'limiter' => 'auth.login', 'required' => ['email', 'password']],
        'POST /api/auth/logout' => ['access' => 'auth', 'limiter' => null, 'required' => []],
        'POST /api/auth/forgot-password' => ['access' => 'guest', 'limiter' => 'auth.forgot-password', 'required' => ['email']],
        'POST /api/auth/reset-password' => ['access' => 'guest', 'limiter' => 'auth.reset-password', 'required' => ['token', 'email', 'password']],
        'POST /api/auth/email/verification-notification' => ['access' => 'auth', 'limiter' => 'auth.verification-notification', 'required' => []],
        'POST /api/auth/confirm-password' => ['access' => 'auth', 'limiter' => 'auth.confirm-password', 'required' => ['password']],
        'GET /api/profile' => ['access' => 'auth', 'limiter' => null, 'required' => []],
        'PATCH /api/profile' => ['access' => 'auth', 'limiter' => null, 'required' => ['name', 'email']],
        'DELETE /api/profile' => ['access' => 'auth', 'limiter' => 'auth.confirm-password', 'required' => ['password']],
        'PUT /api/profile/password' => ['access' => 'auth', 'limiter' => 'auth.confirm-password', 'required' => ['current_password', 'password']],
    ];

    /**
     * AppServiceProvider::configureRateLimiting() の 1 分あたりの上限。
     */
    private const LIMITS = ['auth.login' => 20];

    private const DEFAULT_LIMIT = 6;

    public function test_every_api_route_is_listed(): void
    {
        $actual = [];

        foreach ($this->app['router']->getRoutes() as $route) {
            /** @var Route $route */
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            $middleware = $route->gatherMiddleware();
            $throttle = array_values(array_filter($middleware, fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')));

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $actual[$method.' /'.$route->uri()] = [
                    'access' => match (true) {
                        in_array('auth:sanctum', $middleware, true) => 'auth',
                        in_array('api.guest', $middleware, true) => 'guest',
                        default => 'public',
                    },
                    'limiter' => isset($throttle[0]) ? substr($throttle[0], strlen('throttle:')) : null,
                ];
            }
        }

        $expected = array_map(fn (array $e) => ['access' => $e['access'], 'limiter' => $e['limiter']], self::ENDPOINTS);

        ksort($actual);
        ksort($expected);
        $this->assertSame($expected, $actual);
    }

    #[DataProvider('authEndpoints')]
    public function test_guest_gets_unauthenticated_contract(string $method, string $uri): void
    {
        $this->fromFrontend()->json($method, $uri)
            ->assertStatus(401)
            ->assertJsonStructure(['code', 'message'])
            ->assertJsonMissingPath('errors')
            ->assertJson(['code' => 'unauthenticated']);
    }

    #[DataProvider('guestEndpoints')]
    public function test_authenticated_user_gets_already_authenticated_contract(string $method, string $uri): void
    {
        $this->actingAs(User::factory()->create())->fromFrontend()->json($method, $uri)
            ->assertStatus(409)
            ->assertJsonStructure(['code', 'message'])
            ->assertJsonMissingPath('errors')
            ->assertJson(['code' => 'already_authenticated']);
    }

    /**
     * @param  list<string>  $required
     */
    #[DataProvider('endpointsWithInput')]
    public function test_empty_body_gets_validation_contract(string $method, string $uri, string $access, array $required): void
    {
        $this->asAccess($access)->fromFrontend()->json($method, $uri, [])
            ->assertStatus(422)
            ->assertJsonStructure(['code', 'message', 'errors'])
            ->assertJson(['code' => 'validation_failed'])
            ->assertOnlyJsonValidationErrors($required);
    }

    /**
     * @param  list<string>  $required
     */
    #[DataProvider('throttledEndpoints')]
    public function test_throttled_endpoint_gets_rate_limited_contract(string $method, string $uri, string $access, string $limiter, array $required): void
    {
        Notification::fake();
        $client = $this->asAccess($access, User::factory()->unverified()->create());

        // 上限内の空 body request は、入力の無い endpoint なら成功し、それ以外は 422 になる。
        for ($i = 0; $i < (self::LIMITS[$limiter] ?? self::DEFAULT_LIMIT); $i++) {
            $client->fromFrontend()->json($method, $uri, [])->assertStatus($required === [] ? 200 : 422);
        }

        $client->fromFrontend()->json($method, $uri, [])
            ->assertStatus(429)
            ->assertJsonStructure(['code', 'message'])
            ->assertJsonMissingPath('errors')
            ->assertJson(['code' => 'rate_limited'])
            ->assertHeader('Retry-After');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function authEndpoints(): array
    {
        return self::rows(fn (array $e) => $e['access'] === 'auth', fn () => []);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function guestEndpoints(): array
    {
        return self::rows(fn (array $e) => $e['access'] === 'guest', fn () => []);
    }

    /**
     * @return array<string, array{string, string, string, list<string>}>
     */
    public static function endpointsWithInput(): array
    {
        return self::rows(fn (array $e) => $e['required'] !== [], fn (array $e) => [$e['access'], $e['required']]);
    }

    /**
     * @return array<string, array{string, string, string, string, list<string>}>
     */
    public static function throttledEndpoints(): array
    {
        return self::rows(fn (array $e) => $e['limiter'] !== null, fn (array $e) => [$e['access'], $e['limiter'], $e['required']]);
    }

    /**
     * @param  callable(array): bool  $filter
     * @param  callable(array): list<mixed>  $extra
     * @return array<string, list<mixed>>
     */
    private static function rows(callable $filter, callable $extra): array
    {
        $rows = [];

        foreach (array_filter(self::ENDPOINTS, $filter) as $key => $endpoint) {
            [$method, $uri] = explode(' ', $key, 2);
            $rows[$key] = [$method, $uri, ...$extra($endpoint)];
        }

        return $rows;
    }

    private function asAccess(string $access, ?User $user = null): static
    {
        return $access === 'auth' ? $this->actingAs($user ?? User::factory()->create()) : $this;
    }
}
