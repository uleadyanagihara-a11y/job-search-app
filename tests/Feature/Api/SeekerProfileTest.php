<?php

namespace Tests\Feature\Api;

use App\Http\Middleware\Api\EnsureApiEmailIsVerified;
use App\Models\SeekerProfile;
use App\Models\User;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * GET/PUT /api/seeker-profile（docs/domain-api-contract.md M2.3・M2.4・M2.7）。
 */
class SeekerProfileTest extends TestCase
{
    use RefreshDatabase;

    private const VALID = [
        'desired_prefecture' => 13,
        'desired_job_type' => 'Web エンジニア',
        'self_pr' => "Laravel と Vue で\n3 年開発してきました。",
    ];

    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/seeker-profile')->assertUnauthorized()->assertJson(['code' => 'unauthenticated']);
        $this->putJson('/api/seeker-profile', self::VALID)->assertUnauthorized()->assertJson(['code' => 'unauthenticated']);
    }

    public function test_unverified_seeker_gets_email_unverified(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->getJson('/api/seeker-profile')
            ->assertForbidden()->assertJson(['code' => 'email_unverified']);
        $this->actingAs($user)->putJson('/api/seeker-profile', self::VALID)
            ->assertForbidden()->assertJson(['code' => 'email_unverified']);

        $this->assertDatabaseCount('seeker_profiles', 0);
    }

    public function test_company_member_gets_403_for_both_get_and_put(): void
    {
        $user = User::factory()->companyMember()->create();

        $this->actingAs($user)->getJson('/api/seeker-profile')
            ->assertForbidden()->assertJson(['code' => 'forbidden']);
        $this->actingAs($user)->putJson('/api/seeker-profile', self::VALID)
            ->assertForbidden()->assertJson(['code' => 'forbidden']);

        $this->assertDatabaseCount('seeker_profiles', 0);
    }

    public function test_company_member_gets_403_rather_than_422_for_invalid_input(): void
    {
        // 認可は入力の検証より先（§5.5.5）。
        $this->actingAs(User::factory()->companyMember()->create())
            ->putJson('/api/seeker-profile', ['self_pr' => str_repeat('あ', 2001)])
            ->assertForbidden()->assertJson(['code' => 'forbidden']);
    }

    public function test_seeker_without_a_row_gets_all_null_fields(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/seeker-profile')
            ->assertOk()
            ->assertExactJson(['data' => [
                'desired_prefecture' => null,
                'desired_job_type' => null,
                'self_pr' => null,
                'updated_at' => null,
            ]]);

        $this->assertDatabaseCount('seeker_profiles', 0);
    }

    public function test_put_creates_the_row_and_get_returns_it(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/api/seeker-profile', self::VALID)
            ->assertOk()
            ->assertJson(['data' => self::VALID])
            ->assertJsonPath('data.updated_at', fn ($value) => is_string($value) && str_ends_with($value, 'Z'));

        $this->actingAs($user)->getJson('/api/seeker-profile')
            ->assertOk()
            ->assertJson(['data' => self::VALID]);

        $this->assertDatabaseCount('seeker_profiles', 1);
        $this->assertDatabaseHas('seeker_profiles', ['user_id' => $user->id, 'desired_prefecture' => 13]);
    }

    public function test_put_replaces_and_sets_omitted_fields_to_null(): void
    {
        $user = User::factory()->create();
        $user->seekerProfile()->create(self::VALID);

        $this->actingAs($user)->putJson('/api/seeker-profile', ['desired_job_type' => '営業'])
            ->assertOk()
            ->assertJson(['data' => [
                'desired_prefecture' => null,
                'desired_job_type' => '営業',
                'self_pr' => null,
            ]]);

        $this->assertDatabaseCount('seeker_profiles', 1);
        $this->assertDatabaseHas('seeker_profiles', [
            'user_id' => $user->id,
            'desired_prefecture' => null,
            'desired_job_type' => '営業',
            'self_pr' => null,
        ]);
    }

    public function test_seeker_only_updates_their_own_row(): void
    {
        $other = User::factory()->create();
        $other->seekerProfile()->create(self::VALID);

        $this->actingAs(User::factory()->create())->putJson('/api/seeker-profile', ['self_pr' => 'こんにちは'])
            ->assertOk();

        $this->assertSame(self::VALID['self_pr'], SeekerProfile::query()->findOrFail($other->id)->self_pr);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidInputs(): array
    {
        return [
            'self_pr 2001 文字' => [['self_pr' => str_repeat('あ', 2001)], 'self_pr'],
            'desired_job_type 101 文字' => [['desired_job_type' => str_repeat('あ', 101)], 'desired_job_type'],
            '都道府県 0' => [['desired_prefecture' => 0], 'desired_prefecture'],
            '都道府県 48' => [['desired_prefecture' => 48], 'desired_prefecture'],
            '都道府県が文字列' => [['desired_prefecture' => '東京都'], 'desired_prefecture'],
            'self_pr が配列' => [['self_pr' => ['a']], 'self_pr'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    #[DataProvider('invalidInputs')]
    public function test_invalid_input_gets_422(array $input, string $field): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/api/seeker-profile', $input)
            ->assertStatus(422)
            ->assertJson(['code' => 'validation_failed'])
            ->assertOnlyJsonValidationErrors([$field]);

        $this->assertDatabaseCount('seeker_profiles', 0);
    }

    public function test_self_pr_of_2000_characters_is_accepted(): void
    {
        $this->actingAs(User::factory()->create())
            ->putJson('/api/seeker-profile', ['self_pr' => str_repeat('あ', 2000)])
            ->assertOk();
    }

    public function test_validation_messages_use_japanese_attribute_names(): void
    {
        $this->actingAs(User::factory()->create())
            ->putJson('/api/seeker-profile', ['self_pr' => str_repeat('あ', 2001)])
            ->assertJsonPath('errors.self_pr.0', fn (string $message) => str_contains($message, '自己 PR'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function methods(): array
    {
        return ['GET' => ['GET'], 'PUT' => ['PUT']];
    }

    #[DataProvider('methods')]
    public function test_email_verification_runs_before_route_binding(string $method): void
    {
        // §5.5.5「middleware の実行順」。EnsureApiSessionTest と同じく、並べ替え後の実際の順序を検査する。
        $router = $this->app['router'];
        $route = $router->getRoutes()->match(Request::create('/api/seeker-profile', $method));

        $middleware = array_map(
            fn (string $m) => explode(':', $m, 2)[0],
            $router->gatherRouteMiddleware($route),
        );

        $this->assertSame(
            [Authenticate::class, EnsureApiEmailIsVerified::class, SubstituteBindings::class],
            array_values(array_intersect($middleware, [
                Authenticate::class,
                EnsureApiEmailIsVerified::class,
                SubstituteBindings::class,
            ])),
        );
    }

    public function test_unverified_user_gets_email_unverified_instead_of_404_for_a_missing_binding(): void
    {
        // 業務 API は今後 route binding を持つ。未確認の user に存在しない ID で 404 を返さないこと。
        Route::middleware(['api', 'auth:sanctum', 'api.verified'])
            ->get('/api/__test/users/{user}', fn (User $user) => response()->json(['id' => $user->id]));

        $this->actingAs(User::factory()->unverified()->create())->getJson('/api/__test/users/999999')
            ->assertForbidden()
            ->assertJson(['code' => 'email_unverified']);
    }
}
