<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * ロールと企業の停止に関わる既存 API の変更（docs/domain-api-contract.md M2.2・M2.7）。
 */
class RoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeker_resource_has_seeker_role_and_null_company(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.role', 'seeker')
            ->assertJsonPath('data.company_id', null);
    }

    public function test_company_member_resource_has_company_role_and_company_id(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->companyMember($company)->create();

        foreach (['/api/user', '/api/profile'] as $uri) {
            $this->actingAs($user)->getJson($uri)
                ->assertOk()
                ->assertJsonPath('data.role', 'company')
                ->assertJsonPath('data.company_id', $company->id);
        }
    }

    public function test_register_always_creates_a_seeker_even_if_role_is_sent(): void
    {
        Notification::fake();

        $this->fromFrontend()->postJson('/api/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role' => 'company',
        ])
            ->assertCreated()
            ->assertJsonPath('data.role', 'seeker')
            ->assertJsonPath('data.company_id', null);

        $this->assertSame(Role::Seeker, User::query()->where('email', 'test@example.com')->sole()->role);
    }

    public function test_login_returns_role(): void
    {
        $user = User::factory()->companyMember()->create();

        $this->fromFrontend()->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.role', 'company');
    }

    public function test_member_of_suspended_company_cannot_login_with_correct_password(): void
    {
        $user = User::factory()->companyMember(Company::factory()->suspended()->create())->create();

        $this->fromFrontend()->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertForbidden()
            ->assertExactJson([
                'code' => 'company_suspended',
                'message' => 'この企業アカウントは現在利用できません。運営にお問い合わせください。',
            ]);

        $this->assertGuest('web');
        // 停止で拒否した request は失敗回数に数えない。
        $this->assertSame(0, RateLimiter::attempts($user->email.'|127.0.0.1'));
    }

    public function test_member_of_suspended_company_with_wrong_password_gets_the_usual_422(): void
    {
        // password が誤りのときは、停止中かどうかを知らせない。
        $user = User::factory()->companyMember(Company::factory()->suspended()->create())->create();

        $this->fromFrontend()->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertStatus(422)
            ->assertJson(['code' => 'validation_failed'])
            ->assertOnlyJsonValidationErrors(['email']);

        $this->assertGuest('web');
        $this->assertSame(1, RateLimiter::attempts($user->email.'|127.0.0.1'));
    }

    public function test_logged_in_member_is_locked_out_when_company_is_suspended(): void
    {
        // api.company.active（M2.2）。ログイン中に停止されたら、次の request で締め出す。
        $company = Company::factory()->create();
        $member = User::factory()->companyMember($company)->create();

        $this->actingAs($member)->fromFrontend()->getJson('/api/user')->assertOk();

        $company->forceFill(['suspended_at' => now()])->save();

        // 実際の request は user を毎回 DB から読むため、前の request で読んだ relation を持ち越さない。
        $this->actingAs($member->fresh())->fromFrontend()->getJson('/api/user')
            ->assertForbidden()
            ->assertJson(['code' => 'company_suspended']);
        $this->assertGuest('web');
    }

    public function test_unverified_member_of_suspended_company_gets_company_suspended_first(): void
    {
        // api.company.active は api.verified より前に動く（M2.4）。
        $member = User::factory()->unverified()->companyMember(Company::factory()->suspended()->create())->create();

        $this->actingAs($member)->fromFrontend()->getJson('/api/seeker-profile')
            ->assertForbidden()
            ->assertJson(['code' => 'company_suspended']);
    }

    public function test_member_of_suspended_company_can_still_logout(): void
    {
        $member = User::factory()->companyMember(Company::factory()->suspended()->create())->create();

        $this->actingAs($member)->fromFrontend()->postJson('/api/auth/logout')->assertNoContent();
        $this->assertGuest('web');
    }

    public function test_seeker_and_member_of_active_company_are_not_affected(): void
    {
        $this->actingAs(User::factory()->create())->fromFrontend()->getJson('/api/seeker-profile')->assertOk();
        $this->actingAs(User::factory()->companyMember()->create())->fromFrontend()->getJson('/api/profile')->assertOk();
    }
}
