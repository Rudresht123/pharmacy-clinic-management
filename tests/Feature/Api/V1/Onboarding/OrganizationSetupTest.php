<?php

namespace Tests\Feature\Api\V1\Onboarding;

use App\Models\Platform\Organization;
use App\Models\Platform\OrganizationType;
use App\Models\Platform\PlatformRole;
use App\Models\Platform\PlatformUser;
use App\Models\Tenant\User as TenantUser;
use App\Services\Tenancy\DatabaseService;
use App\Services\Tenancy\TenantConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The invited owner's setup flow — the step that actually creates the first
 * user in a tenant database. Until this runs, an organization can be fully
 * provisioned and still have nobody who can sign in.
 */
class OrganizationSetupTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $createdDatabases = [];

    protected function tearDown(): void
    {
        foreach ($this->createdDatabases as $databaseName) {
            (new TenantConnectionService)->disconnect();
            DatabaseService::drop($databaseName);
        }

        parent::tearDown();
    }

    private function provisionOrganization(): Organization
    {
        $type = OrganizationType::create([
            'name' => 'Pharmacy '.uniqid(),
            'slug' => 'pharmacy-'.uniqid(),
            'is_active' => true,
        ]);

        $admin = PlatformUser::factory()->withRole(PlatformRole::SUPER_ADMIN)->create();
        $unique = uniqid();

        $created = $this->actingAs($admin, 'platform')
            ->postJson('/api/v1/admin/organizations', [
                'organization_name' => 'Setup Test '.$unique,
                'organization_code' => 'ST'.$unique,
                'organization_type_id' => $type->id,
                'subdomain' => 'setup-'.$unique,
                'email' => "owner-{$unique}@example.com",
            ])->json('data');

        $organization = Organization::where('uuid', $created['uuid'])->firstOrFail();
        $this->createdDatabases[] = $organization->database_name;

        return $organization;
    }

    public function test_the_owner_can_complete_setup_and_then_sign_in(): void
    {
        $organization = $this->provisionOrganization();

        $this->postJson("/api/v1/organization-setup/{$organization->setup_token}", [
            'admin_name' => 'Priya Sharma',
            'password' => 'Str0ng!Pass',
            'password_confirmation' => 'Str0ng!Pass',
        ])
            ->assertOk()
            ->assertJsonPath('data.email', $organization->email);

        // The owner now exists in the tenant's own database.
        (new TenantConnectionService)->connect($organization->database_name);

        $user = TenantUser::on(TenantConnectionService::CONNECTION)
            ->where('email', $organization->email)
            ->firstOrFail();

        $this->assertSame('Priya Sharma', $user->name);
        $this->assertSame(TenantUser::OWNER, $user->role);
        $this->assertTrue((bool) $user->is_active);

        (new TenantConnectionService)->disconnect();

        // And the token is spent.
        $organization->refresh();
        $this->assertTrue($organization->is_setup_completed);
        $this->assertNull($organization->setup_token);

        // The whole point of the flow: these credentials now work.
        $this->postJson("http://{$organization->subdomain}.hms.local/api/v1/tenant/auth/login", [
            'email' => $organization->email,
            'password' => 'Str0ng!Pass',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.role', TenantUser::OWNER);
    }

    public function test_a_weak_password_is_rejected_with_the_rule_the_form_promises(): void
    {
        $organization = $this->provisionOrganization();

        $this->postJson("/api/v1/organization-setup/{$organization->setup_token}", [
            'admin_name' => 'Priya Sharma',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_mismatched_confirmation_is_rejected(): void
    {
        $organization = $this->provisionOrganization();

        $this->postJson("/api/v1/organization-setup/{$organization->setup_token}", [
            'admin_name' => 'Priya Sharma',
            'password' => 'Str0ng!Pass',
            'password_confirmation' => 'Different!1',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_the_token_cannot_be_used_twice(): void
    {
        $organization = $this->provisionOrganization();
        $token = $organization->setup_token;

        $this->postJson("/api/v1/organization-setup/{$token}", [
            'admin_name' => 'Priya Sharma',
            'password' => 'Str0ng!Pass',
            'password_confirmation' => 'Str0ng!Pass',
        ])->assertOk();

        $this->postJson("/api/v1/organization-setup/{$token}", [
            'admin_name' => 'Someone Else',
            'password' => 'An0ther!Pass',
            'password_confirmation' => 'An0ther!Pass',
        ])->assertStatus(404);
    }
}
