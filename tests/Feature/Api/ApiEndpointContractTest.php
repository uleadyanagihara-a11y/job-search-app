<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
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
     * limiter: throttle の limiter 名。
     * roles: 使えるロール（auth のときだけ。guest は []）。ここに無いロールは `403 forbidden` になることを、
     *        ここにあるロールは 401・403 にならないことを、全ロールについて検査する。
     *        UserFactory の既定が seeker のため、endpoint ごとの test が企業担当者を書き忘れてもここで気づける。
     * required: 空 body で送ったときに validation error になる field。
     *
     * @var array<string, array{access: 'auth'|'guest', limiter: string|null, roles: list<'seeker'|'company'>, required: list<string>}>
     */
    private const ENDPOINTS = [
        'GET /api/user' => ['access' => 'auth', 'limiter' => null, 'roles' => self::ALL_ROLES, 'required' => []],
        'POST /api/auth/register' => ['access' => 'guest', 'limiter' => 'auth.register', 'roles' => [], 'required' => ['name', 'email', 'password']],
        'POST /api/auth/login' => ['access' => 'guest', 'limiter' => 'auth.login', 'roles' => [], 'required' => ['email', 'password']],
        'POST /api/auth/logout' => ['access' => 'auth', 'limiter' => null, 'roles' => self::ALL_ROLES, 'required' => []],
        'POST /api/auth/forgot-password' => ['access' => 'guest', 'limiter' => 'auth.forgot-password', 'roles' => [], 'required' => ['email']],
        'POST /api/auth/reset-password' => ['access' => 'guest', 'limiter' => 'auth.reset-password', 'roles' => [], 'required' => ['token', 'email', 'password']],
        'POST /api/auth/email/verification-notification' => ['access' => 'auth', 'limiter' => 'auth.verification-notification', 'roles' => self::ALL_ROLES, 'required' => []],
        'POST /api/auth/confirm-password' => ['access' => 'auth', 'limiter' => 'auth.confirm-password', 'roles' => self::ALL_ROLES, 'required' => ['password']],
        'GET /api/profile' => ['access' => 'auth', 'limiter' => null, 'roles' => self::ALL_ROLES, 'required' => []],
        'PATCH /api/profile' => ['access' => 'auth', 'limiter' => 'auth.confirm-password', 'roles' => self::ALL_ROLES, 'required' => ['name', 'email']],
        'DELETE /api/profile' => ['access' => 'auth', 'limiter' => 'auth.confirm-password', 'roles' => self::ALL_ROLES, 'required' => ['password']],
        'PUT /api/profile/password' => ['access' => 'auth', 'limiter' => 'auth.confirm-password', 'roles' => self::ALL_ROLES, 'required' => ['current_password', 'password']],
        'POST /api/company-invitations/lookup' => ['access' => 'guest', 'limiter' => 'auth.invitation', 'roles' => [], 'required' => ['token']],
        'POST /api/company-invitations/accept' => ['access' => 'guest', 'limiter' => 'auth.invitation', 'roles' => [], 'required' => ['token', 'name', 'password']],
        // 項目はすべて任意のため、空 body でも 200（送らなかった field は null）。
        'GET /api/seeker-profile' => ['access' => 'auth', 'limiter' => null, 'roles' => ['seeker'], 'required' => []],
        'PUT /api/seeker-profile' => ['access' => 'auth', 'limiter' => null, 'roles' => ['seeker'], 'required' => []],
    ];

    private const ALL_ROLES = ['seeker', 'company'];

    /**
     * roles に無いロールを `403 forbidden` ではなく `404 not_found` で拒否する endpoint
     * （他人の非公開 resource。docs/inertia-removal-migration.md §5.5.5「403と404の使い分け」）。
     *
     * @var list<string>
     */
    private const ROLE_DENIED_AS_NOT_FOUND = [];

    /**
     * path parameter のある auth endpoint の、resource の見える範囲（docs/domain-api-contract.md の冒頭）。
     *
     * owner: 持ち主（企業担当者なら自社、求職者なら本人）だけが使える。同じロールの別の user
     *        （担当者なら別の企業の担当者）が持ち主の resource を指定したら、公開中でも存在しない id と同じ
     *        `404 not_found` になり、DB が変わらないことを test_resource_of_another_owner_is_not_found で検査する。
     *        Vue でボタンを隠しても、他社の id を直接指定した request は防げないため、ここで API 側を全部確かめる。
     * public: 公開中の resource で、roles のロールなら持ち主でなくても使える（求職者が見る公開中の求人など）。
     *
     * path parameter のある auth route は、ここに書かないと test_every_parameterized_endpoint_declares_resource_scope が落ちる。
     *
     * @var array<string, 'owner'|'public'>
     */
    private const RESOURCE_SCOPES = [
        // 例: 'PATCH /api/company/job-postings/{jobPosting}' => 'owner',
    ];

    private const NOT_FOUND_BODY = ['code' => 'not_found', 'message' => '対象のリソースが見つかりません。'];

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

    /**
     * 停止中の企業の担当者を締め出す api.company.active を付けない auth:sanctum の route
     * （docs/domain-api-contract.md M2.2）。logout は停止中でもできるようにする。
     */
    private const WITHOUT_COMPANY_CHECK = ['POST /api/auth/logout'];

    public function test_every_auth_route_checks_company_suspension(): void
    {
        $missing = [];

        foreach ($this->app['router']->getRoutes() as $route) {
            /** @var Route $route */
            $middleware = $route->gatherMiddleware();

            if (! in_array('auth:sanctum', $middleware, true) || in_array('api.company.active', $middleware, true)) {
                continue;
            }

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $missing[] = $method.' /'.$route->uri();
            }
        }

        $this->assertSame(self::WITHOUT_COMPANY_CHECK, $missing);
    }

    #[DataProvider('companyCheckedEndpoints')]
    public function test_member_of_suspended_company_gets_company_suspended_contract(string $method, string $uri): void
    {
        $member = User::factory()->companyMember(Company::factory()->suspended()->create())->create();

        // 空 body でも validation（422）や処理より前に締め出し、logout する。
        // path parameter は存在しない id にする（route binding の 404 より前に締め出す。M2.4）。
        $this->actingAs($member)->fromFrontend()->json($method, $this->withMissingParameters($uri), [])
            ->assertForbidden()
            ->assertExactJson([
                'code' => 'company_suspended',
                'message' => 'この企業アカウントは現在利用できません。運営にお問い合わせください。',
            ]);

        $this->assertGuest('web');
    }

    #[DataProvider('authEndpoints')]
    public function test_guest_gets_unauthenticated_contract(string $method, string $uri): void
    {
        $this->fromFrontend()->json($method, $this->withMissingParameters($uri))
            ->assertStatus(401)
            ->assertJsonStructure(['code', 'message'])
            ->assertJsonMissingPath('errors')
            ->assertJson(['code' => 'unauthenticated']);
    }

    public function test_roles_are_declared_for_auth_endpoints_only(): void
    {
        $this->assertSame(array_column(Role::cases(), 'value'), self::ALL_ROLES);

        foreach (self::ENDPOINTS as $key => $endpoint) {
            if ($endpoint['access'] === 'guest') {
                $this->assertSame([], $endpoint['roles'], $key);

                continue;
            }

            $this->assertNotSame([], $endpoint['roles'], $key);
            $this->assertSame([], array_diff($endpoint['roles'], self::ALL_ROLES), $key);
        }

        $this->assertSame([], array_diff(self::ROLE_DENIED_AS_NOT_FOUND, array_keys(self::authEndpoints())));
    }

    #[DataProvider('forbiddenRoleEndpoints')]
    public function test_role_outside_endpoint_roles_is_denied(string $method, string $uri, string $role): void
    {
        $user = $this->userWithRole($role);
        $asNotFound = in_array("{$method} {$uri}", self::ROLE_DENIED_AS_NOT_FOUND, true);

        // 空 body でも validation（422）より前に拒否する（ロール違いを FormRequest より先に判定する。CLAUDE.md）。
        $this->actingAs($user)->fromFrontend()->json($method, $this->withParameters($uri, $user), [])
            ->assertStatus($asNotFound ? 404 : 403)
            ->assertExactJson($asNotFound
                ? self::NOT_FOUND_BODY
                : ['code' => 'forbidden', 'message' => 'この操作は許可されていません。']);
    }

    public function test_every_parameterized_endpoint_declares_resource_scope(): void
    {
        $parameterized = array_keys(array_filter(self::authEndpoints(), fn (array $row) => str_contains($row[1], '{')));
        $declared = array_keys(self::RESOURCE_SCOPES);

        sort($parameterized);
        sort($declared);
        $this->assertSame($parameterized, $declared, 'path parameter のある auth endpoint は RESOURCE_SCOPES に owner か public を書く');

        // ロール違いを 404 で隠す endpoint は、同じロールの他人にも存在を隠す。
        $this->assertSame([], array_diff(self::ROLE_DENIED_AS_NOT_FOUND, array_keys(self::RESOURCE_SCOPES, 'owner', true)));
    }

    /**
     * 他社の担当者が他社の求人を PATCH する、のような request を API が拒否することを、
     * owner の endpoint とその roles の全組について検査する（docs/inertia-removal-migration.md §5.5.5「403と404の使い分け」）。
     *
     * 1 件も無い間に data provider を空にすると PHPUnit が error にするため、test の中で回す。
     */
    public function test_resource_of_another_owner_is_not_found(): void
    {
        $rows = self::roleRows(fn (array $e, string $role, string $key) => (self::RESOURCE_SCOPES[$key] ?? null) === 'owner'
            && in_array($role, $e['roles'], true));

        if ($rows === []) {
            $this->markTestSkipped('RESOURCE_SCOPES に owner の endpoint がまだ無い');
        }

        Notification::fake();

        foreach ($rows as $label => [$method, $uri, $role]) {
            $owner = $this->userWithRole($role);
            $intruder = $this->userWithRole($role); // 企業担当者なら、companyMember() が別の企業を作る

            [$path, $resources] = $this->resolveParameters($uri, $owner);
            $before = $this->snapshot($resources);

            // 空 body でも validation（422）より前に 404（Policy を FormRequest より先に呼ぶ。CLAUDE.md）。
            $this->actingAs($intruder)->fromFrontend()->json($method, $path, [])
                ->assertStatus(404)
                ->assertExactJson(self::NOT_FOUND_BODY);

            // 存在しない id と同じ response にして、他社の resource があることを知らせない。
            $this->actingAs($intruder)->fromFrontend()->json($method, $this->withMissingParameters($uri), [])
                ->assertStatus(404)
                ->assertExactJson(self::NOT_FOUND_BODY);

            $this->assertSame($before, $this->snapshot($resources), "{$label}: 他人の request で DB が変わった");
        }
    }

    #[DataProvider('allowedRoleEndpoints')]
    public function test_role_in_endpoint_roles_is_not_rejected(string $method, string $uri, string $role): void
    {
        Notification::fake();
        $user = $this->userWithRole($role);

        // path parameter は user が使える resource で埋めるため、404 も拒否として扱う。
        $status = $this->actingAs($user)->fromFrontend()->json($method, $this->withParameters($uri, $user), [])->status();

        $this->assertNotContains($status, [401, 403, 404], "{$method} {$uri} as {$role}");
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
     * @param  list<string>  $roles
     * @param  list<string>  $required
     */
    #[DataProvider('endpointsWithInput')]
    public function test_empty_body_gets_validation_contract(string $method, string $uri, array $roles, array $required): void
    {
        $user = $roles === [] ? null : $this->userWithRole($roles[0]);

        $this->asUser($user)->fromFrontend()->json($method, $this->withParameters($uri, $user), [])
            ->assertStatus(422)
            ->assertJsonStructure(['code', 'message', 'errors'])
            ->assertJson(['code' => 'validation_failed'])
            ->assertOnlyJsonValidationErrors($required);
    }

    /**
     * @param  list<string>  $roles
     * @param  list<string>  $required
     */
    #[DataProvider('throttledEndpoints')]
    public function test_throttled_endpoint_gets_rate_limited_contract(string $method, string $uri, array $roles, string $limiter, array $required): void
    {
        Notification::fake();
        $user = $roles === [] ? null : $this->userWithRole($roles[0], verified: false);
        $client = $this->asUser($user);
        $uri = $this->withParameters($uri, $user);

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
    public static function companyCheckedEndpoints(): array
    {
        return array_diff_key(self::authEndpoints(), array_flip(self::WITHOUT_COMPANY_CHECK));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function guestEndpoints(): array
    {
        return self::rows(fn (array $e) => $e['access'] === 'guest', fn () => []);
    }

    /**
     * roles が空（guest）なら未ログインで、そうでなければ roles の先頭のロールで送る。
     *
     * @return array<string, array{string, string, list<string>, list<string>}>
     */
    public static function endpointsWithInput(): array
    {
        return self::rows(fn (array $e) => $e['required'] !== [], fn (array $e) => [$e['roles'], $e['required']]);
    }

    /**
     * @return array<string, array{string, string, list<string>, string, list<string>}>
     */
    public static function throttledEndpoints(): array
    {
        return self::rows(fn (array $e) => $e['limiter'] !== null, fn (array $e) => [$e['roles'], $e['limiter'], $e['required']]);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function forbiddenRoleEndpoints(): array
    {
        return self::roleRows(fn (array $e, string $role) => ! in_array($role, $e['roles'], true));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function allowedRoleEndpoints(): array
    {
        return self::roleRows(fn (array $e, string $role) => in_array($role, $e['roles'], true));
    }

    /**
     * auth の endpoint とロールの組のうち、$filter に合うもの。
     *
     * @param  callable(array, string, string): bool  $filter  endpoint の定義、ロール、`METHOD /uri` の key
     * @return array<string, array{string, string, string}>
     */
    private static function roleRows(callable $filter): array
    {
        $rows = [];

        foreach (self::authEndpoints() as $key => [$method, $uri]) {
            foreach (self::ALL_ROLES as $role) {
                if ($filter(self::ENDPOINTS[$key], $role, $key)) {
                    $rows["{$key} as {$role}"] = [$method, $uri, $role];
                }
            }
        }

        return $rows;
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

    private function userWithRole(string $role, bool $verified = true): User
    {
        $factory = $verified ? User::factory() : User::factory()->unverified();

        return match (Role::from($role)) {
            Role::Seeker => $factory->create(),
            Role::Company => $factory->companyMember()->create(),
        };
    }

    /**
     * uri の path parameter（`{job}` など）を、resource を作って埋める。
     * 存在しない id だと Policy より前の route binding で 404 になり、ロールの検査にならないため。
     */
    private function withParameters(string $uri, ?User $user): string
    {
        return $this->resolveParameters($uri, $user)[0];
    }

    /**
     * withParameters() と同じだが、作った resource も返す（DB が変わっていないかの検査用）。
     *
     * @return array{string, list<Model>}
     */
    private function resolveParameters(string $uri, ?User $user): array
    {
        $resources = [];

        $path = preg_replace_callback(
            '/\{(\w+)\??\}/',
            function (array $m) use ($user, &$resources) {
                $resources[] = $resource = $this->resourceFor($m[1], $user);

                return (string) $resource->getRouteKey();
            },
            $uri,
        );

        return [$path, $resources];
    }

    /**
     * resource の DB 上の現在の値（削除されていれば null）。
     *
     * @param  list<Model>  $resources
     * @return list<array<string, mixed>|null>
     */
    private function snapshot(array $resources): array
    {
        return array_map(fn (Model $resource) => $resource->fresh()?->getAttributes(), $resources);
    }

    /**
     * path parameter を存在しない id で埋める（route binding より前に返す 401・403 の検査用）。
     */
    private function withMissingParameters(string $uri): string
    {
        return preg_replace('/\{\w+\??\}/', '0', $uri);
    }

    /**
     * path parameter の resource を作る。
     *
     * $user のロールが endpoint の roles に入っていれば $user が使える resource にする
     * （企業担当者なら自社の求人、求職者なら公開中の求人など）。roles に無いロールの $user には、
     * 同じ作り方で「他人の resource」が渡り、ROLE_DENIED_AS_NOT_FOUND かどうかで 404 / 403 を検査する。
     * path parameter のある route を足したら、その名前の分岐をここに足す。
     */
    private function resourceFor(string $parameter, ?User $user): Model
    {
        return match ($parameter) {
            // 例: 'jobPosting' => JobPosting::factory()->for($user?->companyMember?->company ?? Company::factory())->published()->create(),
            // owner の endpoint では $user が持ち主になる（他社の担当者からの request は test_resource_of_another_owner_is_not_found が送る）。
            default => $this->fail("path parameter {{$parameter}} の resource の作り方が無い。ApiEndpointContractTest::resourceFor() に足す"),
        };
    }

    private function asUser(?User $user): static
    {
        return $user === null ? $this : $this->actingAs($user);
    }
}
