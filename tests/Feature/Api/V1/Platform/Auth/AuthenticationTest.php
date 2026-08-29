<?php

namespace Tests\Feature\Api\V1\Platform\Auth;

use App\Models\Platform\PlatformRole;
use App\Models\Platform\PlatformUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Admin panel authentication — Build Spec §18.
 *
 * The last two tests are the ones that matter most: they pin the guard
 * separation the spec calls non-negotiable, so a later refactor that quietly
 * merges the two login paths fails here instead of in production.
 */
class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(array $attributes = []): PlatformUser
    {
        return PlatformUser::factory()->withRole(PlatformRole::SUPER_ADMIN)->create($attributes);
    }

    public function test_an_administrator_can_sign_in(): void
    {
        $admin = $this->admin(['email' => 'ops@platform.test']);

        $response = $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'ops@platform.test',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.uuid', $admin->uuid)
            ->assertJsonPath('data.roles.0.code', PlatformRole::SUPER_ADMIN);

        $this->assertAuthenticatedAs($admin, 'platform');
    }

    public function test_the_auto_increment_id_is_never_exposed(): void
    {
        $this->admin(['email' => 'ops@platform.test']);

        $response = $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'ops@platform.test',
            'password' => 'password',
        ]);

        $response->assertOk()->assertJsonMissingPath('data.id');
    }

    public function test_signing_in_records_the_login(): void
    {
        $admin = $this->admin(['email' => 'ops@platform.test']);

        $this->assertNull($admin->last_login_at);

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'ops@platform.test',
            'password' => 'password',
        ])->assertOk();

        $this->assertNotNull($admin->fresh()->last_login_at);
    }

    public function test_a_wrong_password_is_rejected(): void
    {
        $this->admin(['email' => 'ops@platform.test']);

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'ops@platform.test',
            'password' => 'not-the-password',
        ])->assertStatus(422);

        $this->assertGuest('platform');
    }

    public function test_a_deactivated_administrator_cannot_sign_in(): void
    {
        $this->admin(['email' => 'ops@platform.test', 'is_active' => false]);

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'ops@platform.test',
            'password' => 'password',
        ])->assertStatus(422);

        $this->assertGuest('platform');
    }

    public function test_login_is_rate_limited_after_five_attempts(): void
    {
        $this->admin(['email' => 'ops@platform.test']);

        foreach (range(1, 5) as $ignored) {
            $this->postJson('/api/v1/admin/auth/login', [
                'email' => 'ops@platform.test',
                'password' => 'wrong',
            ])->assertStatus(422);
        }

        // The sixth is refused as throttled even though it now has the right
        // password — §18 caps attempts at five per fifteen minutes.
        $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'ops@platform.test',
            'password' => 'password',
        ])->assertStatus(422);

        $this->assertGuest('platform');
    }

    public function test_an_authenticated_admin_can_read_their_own_record(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'platform')
            ->getJson('/api/v1/admin/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', $admin->email);
    }

    public function test_admin_endpoints_reject_a_guest(): void
    {
        $this->getJson('/api/v1/admin/auth/me')->assertStatus(401);
        $this->getJson('/api/v1/admin/organizations')->assertStatus(401);
    }

    /*
    |--------------------------------------------------------------------------
    | The guard separation — Build Spec §18, acceptance test 11
    |--------------------------------------------------------------------------
    */

    public function test_a_tenant_user_cannot_authenticate_on_the_platform_guard(): void
    {
        // Same address and password as an admin would use; only the table
        // differs. The platform guard must still refuse it.
        User::create([
            'name' => 'Tenant Staff',
            'email' => 'staff@tenant.test',
            'password' => Hash::make('password'),
        ]);

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'staff@tenant.test',
            'password' => 'password',
        ])->assertStatus(422);

        $this->assertGuest('platform');
    }

    public function test_a_tenant_session_does_not_unlock_the_admin_panel(): void
    {
        $tenantUser = User::create([
            'name' => 'Tenant Staff',
            'email' => 'staff@tenant.test',
            'password' => Hash::make('password'),
        ]);

        $this->actingAs($tenantUser, 'web')
            ->getJson('/api/v1/admin/organizations')
            ->assertStatus(401);
    }

    public function test_there_is_no_self_registration_endpoint(): void
    {
        // §18: administrators are created by administrators.
        $this->postJson('/api/v1/admin/auth/register', [
            'name' => 'Intruder',
            'email' => 'intruder@platform.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertStatus(404);
    }
}
