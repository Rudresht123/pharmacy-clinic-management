<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\Location;
use App\Models\Tenant\ModuleLock;
use App\Models\Tenant\Role;
use App\Models\Tenant\User as TenantUser;
use App\Services\Permissions\EffectivePermissions;
use App\Services\Tenancy\TenantConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

/**
 * The screens a branch manager customises a role from, and the walls around
 * them.
 *
 * Most of this file is about refusals. A branch manager is trusted with their
 * own branch and nothing else, and every one of these tests is a way that
 * trust could be turned into more than it was meant to be: granting above the
 * organization, removing something locked, or reaching the branch next door.
 */
class BranchRolePermissionApiTest extends TenantTestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private int $delhi;

    private int $lucknow;

    private int $role;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');

        $this->organization = $this->provisionOrganization('P');
        $this->grantModule($this->organization, 'appointments');

        $this->onTenant($this->organization, function () {
            $this->delhi = Location::on('organization')->create([
                'name' => 'Delhi', 'code' => 'P-DEL', 'type' => Location::CLINIC, 'is_active' => true,
            ])->id;

            $this->lucknow = Location::on('organization')->create([
                'name' => 'Lucknow', 'code' => 'P-LKO', 'type' => Location::CLINIC, 'is_active' => true,
            ])->id;
        });

        $this->role = $this->staffRoleId($this->organization);

        $this->placeStaffAt($this->organization, $this->delhi);

        $this->setStaffCapabilities($this->organization, [
            'customers.view',
            'customers.edit',
            'customers.create',
            // What makes them a branch manager for the purposes of this screen.
            'people.view',
            'people.roles',
        ]);
    }

    private function url(?int $location = null): string
    {
        return "/api/v1/tenant/locations/{$location}/roles/{$this->role}/permissions";
    }

    /** The owner sets what no branch may take away. */
    private function lockAsOwner(array $locked): void
    {
        $this->signInAsOwner($this->organization);

        $role = $this->onTenant($this->organization, fn () => Role::on('organization')
            ->with('capabilities')->findOrFail($this->role));

        $this->putJson("/api/v1/tenant/roles/{$this->role}", [
            'name' => $role->name,
            'capabilities' => $role->capabilityKeys(),
            'locked' => $locked,
        ])->assertOk();
    }

    public function test_a_branch_manager_reads_what_they_may_change(): void
    {
        $this->lockAsOwner(['customers.view']);

        $this->signInAsStaff($this->organization);

        $body = $this->withHeader('X-Branch-Id', (string) $this->delhi)
            ->getJson($this->url($this->delhi))
            ->assertOk()
            ->json('data');

        $this->assertContains('customers.edit', $body['inherited']);
        $this->assertSame([], $body['removed']);
        $this->assertSame(['customers.view'], $body['locked']);
    }

    public function test_a_branch_manager_removes_a_capability_at_their_own_branch(): void
    {
        $this->signInAsStaff($this->organization);

        $this->withHeader('X-Branch-Id', (string) $this->delhi)
            ->putJson($this->url($this->delhi), [
                'capabilities' => ['customers.view', 'customers.create', 'people.view', 'people.roles'],
            ])
            ->assertOk()
            ->assertJsonPath('data.removed', ['customers.edit']);

        // And it is gone from what they actually hold.
        $capabilities = $this->withHeader('X-Branch-Id', (string) $this->delhi)
            ->getJson('/api/v1/tenant/auth/me')
            ->assertOk()
            ->json('data.capabilities');

        $this->assertNotContains('customers.edit', $capabilities);
    }

    /**
     * TEST 3 — the boundary. A branch asking for something the organization
     * never granted this role is refused rather than ignored.
     */
    public function test_a_branch_cannot_grant_beyond_the_organizations_role(): void
    {
        $this->signInAsStaff($this->organization);

        $this->withHeader('X-Branch-Id', (string) $this->delhi)
            ->putJson($this->url($this->delhi), [
                'capabilities' => ['customers.view', 'customers.delete'],
            ])
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.capabilities.0',
                'Your organisation has not granted this role: customers.delete.',
            );
    }

    /** TEST 7 — a locked capability is not a branch's to remove. */
    public function test_a_branch_cannot_remove_a_locked_capability(): void
    {
        $this->lockAsOwner(['customers.view']);

        $this->signInAsStaff($this->organization);

        $this->withHeader('X-Branch-Id', (string) $this->delhi)
            ->putJson($this->url($this->delhi), [
                'capabilities' => ['customers.edit', 'customers.create', 'people.view', 'people.roles'],
            ])
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.capabilities.0',
                'Locked by your organisation and cannot be removed here: customers.view.',
            );
    }

    /**
     * The one change that cannot be undone by whoever makes it: switching off
     * the capability this very screen needs, on the role they hold here.
     */
    public function test_a_branch_manager_cannot_lock_themselves_out(): void
    {
        $this->signInAsStaff($this->organization);

        $this->withHeader('X-Branch-Id', (string) $this->delhi)
            ->putJson($this->url($this->delhi), [
                // Everything except people.roles — which is theirs, here.
                'capabilities' => ['customers.view', 'customers.edit', 'customers.create', 'people.view'],
            ])
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.capabilities.0',
                'You hold this role here, so switching off "Manage roles" would leave nobody at '
                    .'this branch able to switch it back on. Ask your organisation owner to make '
                    .'this change.',
            );

        // Still theirs, and still able to open the screen.
        $this->withHeader('X-Branch-Id', (string) $this->delhi)
            ->getJson($this->url($this->delhi))
            ->assertOk()
            ->assertJsonPath('data.removed', []);
    }

    /** The owner is exempt: they can always undo it. */
    public function test_an_owner_may_switch_off_manage_roles(): void
    {
        $this->signInAsOwner($this->organization);

        $this->withHeader('X-Branch-Id', (string) $this->delhi)
            ->putJson($this->url($this->delhi), [
                'capabilities' => ['customers.view', 'customers.edit', 'customers.create', 'people.view'],
            ])
            ->assertOk()
            ->assertJsonPath('data.removed', ['people.roles']);
    }

    /** TEST 6 — and never the branch next door. */
    public function test_a_branch_manager_cannot_customise_another_branch(): void
    {
        $this->signInAsStaff($this->organization);

        $this->withHeader('X-Branch-Id', (string) $this->delhi)
            ->putJson($this->url($this->lucknow), ['capabilities' => []])
            ->assertNotFound();

        $this->withHeader('X-Branch-Id', (string) $this->delhi)
            ->getJson($this->url($this->lucknow))
            ->assertNotFound();
    }

    /** A role a branch wrote for itself is edited, not overridden. */
    public function test_a_branch_owned_role_is_not_customised_through_this_screen(): void
    {
        $own = $this->onTenant($this->organization, function () {
            $role = Role::on('organization')->create([
                'name' => 'Delhi Front Desk',
                'slug' => 'delhi-front-desk',
                'scope' => Role::SCOPE_BRANCH,
                'location_id' => $this->delhi,
            ]);

            $role->syncCapabilities(['customers.view']);

            return $role->id;
        });

        $this->signInAsStaff($this->organization);

        $this->withHeader('X-Branch-Id', (string) $this->delhi)
            ->getJson("/api/v1/tenant/locations/{$this->delhi}/roles/{$own}/permissions")
            ->assertForbidden();
    }

    /** Locking is the organization's, and a branch manager is not it. */
    public function test_a_branch_manager_cannot_lock_anything(): void
    {
        $this->signInAsStaff($this->organization);

        $this->withHeader('X-Branch-Id', (string) $this->delhi)
            ->putJson('/api/v1/tenant/module-locks', ['modules' => ['appointments']])
            ->assertForbidden();
    }

    /**
     * Locking a module a branch had already switched off turns it back on
     * there, rather than leaving the organization holding both answers.
     */
    public function test_locking_a_module_switches_it_back_on_where_it_was_off(): void
    {
        $this->disableModuleAtBranch($this->organization, $this->delhi, 'appointments');

        $this->signInAsOwner($this->organization);

        $this->getJson("/api/v1/tenant/locations/{$this->delhi}/modules")
            ->assertOk()
            ->assertJsonPath('data.modules.0.is_enabled', false);

        $this->putJson('/api/v1/tenant/module-locks', ['modules' => ['appointments']])
            ->assertOk()
            ->assertJsonPath('data.modules.0.is_locked', true);

        $this->getJson("/api/v1/tenant/locations/{$this->delhi}/modules")
            ->assertOk()
            ->assertJsonPath('data.modules.0.is_enabled', true)
            ->assertJsonPath('data.modules.0.is_locked', true);
    }

    /**
     * The debugging screen: not what somebody may do, but which of the six
     * levels decided it.
     */
    public function test_effective_permissions_name_the_level_that_decided(): void
    {
        $this->signInAsStaff($this->organization);

        $this->withHeader('X-Branch-Id', (string) $this->delhi)
            ->putJson($this->url($this->delhi), [
                'capabilities' => ['customers.view', 'customers.create', 'people.view', 'people.roles'],
            ])->assertOk();

        $staff = $this->onTenant($this->organization, fn () => TenantUser::on(TenantConnectionService::CONNECTION)
            ->where('email', self::STAFF_EMAIL)
            ->value('id'));

        $rows = collect(
            $this->withHeader('X-Branch-Id', (string) $this->delhi)
                ->getJson("/api/v1/tenant/users/{$staff}/effective-permissions")
                ->assertOk()
                ->json('data.capabilities')
        )->keyBy('capability');

        // Granted by the role on their membership here.
        $this->assertTrue($rows['customers.view']['allowed']);
        $this->assertSame(EffectivePermissions::BRANCH_ROLE, $rows['customers.view']['source']);

        // Granted by the organization, taken away by this branch — which is
        // the answer that was impossible to see before any of this existed.
        $this->assertFalse($rows['customers.edit']['allowed']);
        $this->assertSame(EffectivePermissions::BRANCH_OVERRIDE, $rows['customers.edit']['source']);

        // Never sold to this organization at all.
        $this->assertFalse($rows['pharmacy.view']['allowed']);
        $this->assertSame(EffectivePermissions::NOT_SOLD, $rows['pharmacy.view']['source']);

        // Sold and running, but nothing they hold grants it.
        $this->assertFalse($rows['customers.delete']['allowed']);
        $this->assertSame(EffectivePermissions::NO_ROLE, $rows['customers.delete']['source']);
    }

    /** A module switched off here outranks the role that grants it. */
    public function test_effective_permissions_report_a_module_switched_off(): void
    {
        $this->setStaffCapabilities($this->organization, ['appointments.view', 'people.view']);
        $this->disableModuleAtBranch($this->organization, $this->delhi, 'appointments');

        $staff = $this->onTenant($this->organization, fn () => TenantUser::on(TenantConnectionService::CONNECTION)
            ->where('email', self::STAFF_EMAIL)
            ->value('id'));

        $this->signInAsStaff($this->organization);

        $rows = collect(
            $this->withHeader('X-Branch-Id', (string) $this->delhi)
                ->getJson("/api/v1/tenant/users/{$staff}/effective-permissions")
                ->assertOk()
                ->json('data.capabilities')
        )->keyBy('capability');

        $this->assertFalse($rows['appointments.view']['allowed']);
        $this->assertSame(EffectivePermissions::MODULE_OFF, $rows['appointments.view']['source']);
    }

    /** Nothing is locked until somebody locks it. */
    public function test_module_locks_start_empty(): void
    {
        $this->signInAsOwner($this->organization);

        $this->getJson('/api/v1/tenant/module-locks')
            ->assertOk()
            ->assertJsonPath('data.modules.0.key', 'appointments')
            ->assertJsonPath('data.modules.0.is_locked', false);

        $this->onTenant($this->organization, function () {
            $this->assertSame(0, ModuleLock::on('organization')->count());
        });
    }
}
