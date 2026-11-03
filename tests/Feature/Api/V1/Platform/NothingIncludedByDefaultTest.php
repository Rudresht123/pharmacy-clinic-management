<?php

namespace Tests\Feature\Api\V1\Platform;

use App\Models\Platform\Module;
use App\Models\Platform\Organization;
use App\Models\Platform\OrganizationModule;
use App\Models\Platform\Plan;
use App\Models\Platform\PlatformRole;
use App\Models\Platform\PlatformUser;
use App\Models\Tenant\Location;
use App\Models\Tenant\Role;
use App\Services\Tenancy\TenantConnectionService;
use App\Services\Tenant\DefaultRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

/**
 * What it means for a new organization to start with no modules at all, and for
 * an existing one not to notice the change.
 */
class NothingIncludedByDefaultTest extends TenantTestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/masterdb/2026_11_03_000000_modules_are_granted_not_included.php';

    private function asAdmin(): static
    {
        return $this->actingAs(PlatformUser::factory()->withRole(PlatformRole::SUPER_ADMIN)->create(), 'platform');
    }

    /** @param list<string> $keys */
    private function grantThroughTheScreen(Organization $organization, array $keys): void
    {
        $this->asAdmin()
            ->putJson("/api/v1/admin/organizations/{$organization->uuid}/modules", [
                'modules' => array_map(fn (string $key) => ['key' => $key, 'is_enabled' => true], $keys),
            ])
            ->assertOk();

        $this->flushSession();
        $this->app['auth']->forgetGuards();
    }

    private function branch(Organization $organization): Location
    {
        return $this->onTenant($organization, fn () => Location::on(TenantConnectionService::CONNECTION)->create([
            'name' => 'Main',
            'code' => 'MAIN',
            'type' => Location::CLINIC,
            'status' => 'active',
        ]));
    }

    /** @return list<string>|null null when the branch has no such role */
    private function roleHolds(Organization $organization, string $slug, int $branchId): ?array
    {
        return $this->onTenant($organization, function () use ($slug, $branchId) {
            return Role::on(TenantConnectionService::CONNECTION)
                ->where('slug', Role::slugAt($slug, $branchId))
                ->first()
                ?->capabilityKeys();
        });
    }

    public function test_a_new_organization_can_use_nothing_until_it_is_granted(): void
    {
        $organization = $this->provisionOrganization('N');
        $this->revokeFoundationModules($organization);

        $this->signInAsOwner($organization);

        $me = $this->getJson('/api/v1/tenant/auth/me')->assertOk();

        $this->assertSame([], $me->json('data.modules'));

        // The owner is refused too: the module was never sold.
        $this->getJson('/api/v1/tenant/customers')->assertForbidden();

        $this->assertSame(['settings.subscription'], $me->json('data.capabilities'));
    }

    /**
     * A lock with no way back in. Without the Configuration module the
     * subscription screen would otherwise answer 403 to its own owner.
     */
    public function test_the_owner_can_reach_the_subscription_with_no_modules_at_all(): void
    {
        $organization = $this->provisionOrganization('N');
        $this->revokeFoundationModules($organization);

        $this->signInAsOwner($organization);

        $this->getJson('/api/v1/tenant/subscription')->assertOk();
    }

    /**
     * What belongs to the organization stays on once sold, or the owner could
     * switch off the very screen that switches it back on. The rest is theirs.
     */
    public function test_an_owner_cannot_switch_off_what_is_organization_level(): void
    {
        $organization = $this->provisionOrganization('N');
        $this->signInAsOwner($organization);

        foreach (['settings', 'branches'] as $key) {
            $this->putJson('/api/v1/tenant/module-settings', ['modules' => [$key => false]])
                ->assertStatus(422)
                ->assertJsonValidationErrors("modules.{$key}");
        }

        $this->putJson('/api/v1/tenant/module-settings', ['modules' => ['billing' => false]])->assertOk();

        $this->assertNotContains('billing', $this->getJson('/api/v1/tenant/auth/me')->json('data.modules'));
    }

    /** Every module shows at a branch, and each one the branch can run is its choice. */
    public function test_a_branch_can_switch_off_what_used_to_be_always_on(): void
    {
        $organization = $this->provisionOrganization('N');
        $here = $this->branch($organization);
        $there = $this->onTenant($organization, fn () => Location::on(TenantConnectionService::CONNECTION)->create([
            'name' => 'Other', 'code' => 'OTHER', 'type' => Location::CLINIC, 'status' => 'active',
        ]));

        $this->signInAsOwner($organization);

        $rows = collect($this->getJson("/api/v1/tenant/locations/{$here->id}/modules")->assertOk()->json('data.modules'))
            ->keyBy('key');

        $this->assertEqualsCanonicalizing(self::FOUNDATION_MODULES, $rows->keys()->all());
        $this->assertTrue($rows['settings']['is_org_level']);
        $this->assertTrue($rows['branches']['is_org_level']);
        $this->assertFalse($rows['billing']['is_org_level']);

        $this->putJson("/api/v1/tenant/locations/{$here->id}/modules", ['overrides' => ['billing' => false]])->assertOk();

        $running = fn (int $location) => $this->withHeader('X-Branch-Id', (string) $location)
            ->getJson('/api/v1/tenant/auth/me')
            ->json('data.modules');

        $this->assertNotContains('billing', $running($here->id));
        $this->assertContains('billing', $running($there->id));

        // Organization-level: no such choice to make at one branch.
        $this->withHeader('X-Branch-Id', (string) $here->id)
            ->putJson("/api/v1/tenant/locations/{$here->id}/modules", ['overrides' => ['settings' => false]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('overrides.settings');
    }

    /** The rule every level applies still holds for the ones newly switchable. */
    public function test_a_branch_cannot_switch_off_what_a_running_module_needs(): void
    {
        $organization = $this->provisionOrganization('N');
        $here = $this->branch($organization);
        $this->grantModule($organization, 'appointments');

        $this->signInAsOwner($organization);

        $this->putJson("/api/v1/tenant/locations/{$here->id}/modules", ['overrides' => ['customers' => false]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('overrides');
    }

    /** The owner's own toggles are not refused over what only a super admin can grant. */
    public function test_a_branch_can_switch_modules_while_foundation_is_unsold(): void
    {
        $organization = $this->provisionOrganization('N');
        $branch = $this->branch($organization);

        $this->revokeFoundationModules($organization);
        $this->grantModule($organization, 'medicines');
        $this->grantModule($organization, 'documents');

        $this->signInAsOwner($organization);

        $this->putJson("/api/v1/tenant/locations/{$branch->id}/modules", ['modules' => ['medicines', 'documents']])
            ->assertOk();
    }

    public function test_starting_roles_fill_in_when_their_modules_are_granted(): void
    {
        $organization = $this->provisionOrganization('N');
        $this->revokeFoundationModules($organization);
        $this->grantThroughTheScreen($organization, ['branches']);

        // Only Branches so far: nothing a branch role can hold, so no roles.
        $branch = $this->branch($organization);
        $this->assertNull($this->roleHolds($organization, 'branch-manager', $branch->id));

        $this->grantThroughTheScreen($organization, ['branches', 'customers']);

        $manager = $this->roleHolds($organization, 'branch-manager', $branch->id);
        $this->assertContains('customers.view', $manager);
        $this->assertNotContains('billing.view', $manager);

        // Granted later, and the same role gains it.
        $this->grantThroughTheScreen($organization, ['branches', 'customers', 'billing']);

        $this->assertContains('billing.view', $this->roleHolds($organization, 'branch-manager', $branch->id));

        // A role whose module has just arrived is written now.
        $this->assertContains('billing.view', $this->roleHolds($organization, 'accountant', $branch->id));
    }

    /** It only ever adds what the grant made possible. */
    public function test_a_grant_does_not_undo_what_a_branch_chose(): void
    {
        $organization = $this->provisionOrganization('N');
        $branch = $this->branch($organization);

        $this->onTenant($organization, function () use ($organization, $branch) {
            app(DefaultRoleSeeder::class)->seedBranch($organization, $branch);

            $connection = TenantConnectionService::CONNECTION;

            // The branch trims one role and deletes another.
            $manager = Role::on($connection)->where('slug', Role::slugAt('branch-manager', $branch->id))->firstOrFail();
            $manager->syncCapabilities(array_values(array_diff($manager->capabilityKeys(), ['customers.delete'])));

            Role::on($connection)->where('slug', Role::slugAt('accountant', $branch->id))->firstOrFail()->delete();
        });

        $this->grantThroughTheScreen($organization, [
            'dashboard', 'branches', 'people', 'customers', 'exports', 'settings', 'billing',
            'appointments',
        ]);

        $manager = $this->roleHolds($organization, 'branch-manager', $branch->id);

        // Gained from the new module; the trim stays trimmed.
        $this->assertContains('appointments.view', $manager);
        $this->assertNotContains('customers.delete', $manager);

        // Billing was already there, so that role was deleted on purpose.
        $this->assertNull($this->roleHolds($organization, 'accountant', $branch->id));

        // Appointments was not, so nobody can have deleted the Receptionist.
        $this->assertNotNull($this->roleHolds($organization, 'receptionist', $branch->id));
    }

    public function test_the_migration_keeps_every_existing_organization_whole(): void
    {
        $this->artisan('modules:sync');

        $existing = Organization::factory()->active()->create();
        $removed = Organization::factory()->active()->create();
        $removed->delete();

        $plan = Plan::query()->create(['slug' => 'pro', 'name' => 'Pro', 'status' => Plan::PUBLISHED, 'monthly_price' => 1000, 'annual_price' => 10000]);
        $free = Plan::query()->where('slug', 'complimentary')->sole();

        $migration = require base_path(self::MIGRATION);
        $migration->up();

        // Twice, because it may well be run twice.
        $migration->up();

        $foundation = ['dashboard', 'branches', 'people', 'customers', 'exports', 'settings', 'billing'];
        $ids = Module::whereIn('key', $foundation)->pluck('id');

        foreach ([$existing, $removed] as $organization) {
            $this->assertSame(
                count($foundation),
                OrganizationModule::where('organization_id', $organization->id)->whereIn('module_id', $ids)->count(),
            );
        }

        $this->assertEqualsCanonicalizing($foundation, $plan->fresh()->moduleKeys());

        // Complimentary stays empty, or every new organization gets them again.
        $this->assertSame([], $free->fresh()->moduleKeys());
    }
}
