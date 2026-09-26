<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\BranchMembership;
use App\Models\Tenant\BranchRoleCapability;
use App\Models\Tenant\Location;
use App\Models\Tenant\ModuleLock;
use App\Models\Tenant\Role;
use App\Models\Tenant\User as TenantUser;
use App\Services\Tenancy\TenantConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

/**
 * One role, two branches, two different answers.
 *
 * The level that did not exist. A role was one row shared by everybody who
 * held it, so giving Delhi's receptionists less than Lucknow's meant cloning
 * the role — and a chain ended up with a copy per branch, drifting apart until
 * the fifth one quietly had a deletion permission nobody meant to grant.
 *
 * A branch may only SUBTRACT, which is what makes "a branch can never grant
 * what the organization did not" true by construction rather than by a check
 * somebody has to remember. These tests hold that line from both directions:
 * what a branch can do, and what it must not be able to do.
 */
class BranchRoleOverrideTest extends TenantTestCase
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

        $this->organization = $this->provisionOrganization('O');
        $this->grantModule($this->organization, 'appointments');

        $this->onTenant($this->organization, function () {
            $this->delhi = Location::on('organization')->create([
                'name' => 'Delhi', 'code' => 'O-DEL', 'type' => Location::CLINIC, 'is_active' => true,
            ])->id;

            $this->lucknow = Location::on('organization')->create([
                'name' => 'Lucknow', 'code' => 'O-LKO', 'type' => Location::CLINIC, 'is_active' => true,
            ])->id;
        });

        // The organization's own role, for branches to use — the only kind a
        // branch may customise.
        $this->role = $this->staffRoleId($this->organization);

        $this->placeStaffAt($this->organization, $this->delhi);
        $this->placeStaffAt($this->organization, $this->lucknow);

        $this->setStaffCapabilities($this->organization, [
            'customers.view',
            'customers.edit',
        ]);
    }

    /** Take a capability off this role, at one branch. */
    private function removeAt(int $locationId, string $capability): void
    {
        $this->onTenant($this->organization, fn () => BranchRoleCapability::on('organization')->create([
            'location_id' => $locationId,
            'role_id' => $this->role,
            'capability' => $capability,
        ]));
    }

    /** Mark a capability as one no branch may remove. */
    private function lock(string $capability): void
    {
        $this->onTenant($this->organization, fn () => Role::on('organization')
            ->findOrFail($this->role)
            ->capabilities()
            ->where('capability', $capability)
            ->update(['is_locked' => true]));
    }

    /** What the signed-in person holds, as the app itself would answer. */
    private function capabilitiesAt(int $locationId): array
    {
        return $this->withHeader('X-Branch-Id', (string) $locationId)
            ->getJson('/api/v1/tenant/auth/me')
            ->assertOk()
            ->json('data.capabilities');
    }

    /**
     * TEST 4 — the whole point: the same role, two branches, two answers,
     * with nothing cloned and neither branch's decision reaching the other.
     */
    public function test_one_role_answers_differently_at_two_branches(): void
    {
        $this->removeAt($this->delhi, 'customers.edit');

        $this->signInAsStaff($this->organization);

        $this->assertNotContains('customers.edit', $this->capabilitiesAt($this->delhi));
        $this->assertContains('customers.edit', $this->capabilitiesAt($this->lucknow));

        // And what was not customised is untouched at both.
        $this->assertContains('customers.view', $this->capabilitiesAt($this->delhi));
        $this->assertContains('customers.view', $this->capabilitiesAt($this->lucknow));
    }

    /** TEST 2 — the override reaches the API, not only the list of names. */
    public function test_a_removed_capability_is_refused_by_the_api(): void
    {
        $customer = $this->onTenant($this->organization, fn () => \App\Models\Tenant\Customer::on('organization')
            ->create(['name' => 'Asha Rane', 'phone' => '9876500001', 'is_active' => true])->id);

        $this->removeAt($this->delhi, 'customers.edit');

        $this->signInAsStaff($this->organization);

        $this->withHeader('X-Branch-Id', (string) $this->delhi)
            ->putJson("/api/v1/tenant/customers/{$customer}", ['name' => 'Asha R.'])
            ->assertForbidden();

        $this->withHeader('X-Branch-Id', (string) $this->lucknow)
            ->putJson("/api/v1/tenant/customers/{$customer}", ['name' => 'Asha R.'])
            ->assertOk();
    }

    /**
     * A lock takes effect on a branch that had ALREADY removed the capability.
     *
     * The row is left where it is rather than deleted, and ignored while the
     * lock stands — so unlocking restores the branch's own decision instead of
     * silently discarding it.
     */
    public function test_locking_overrules_a_customisation_already_made(): void
    {
        $this->removeAt($this->delhi, 'customers.edit');

        $this->signInAsStaff($this->organization);
        $this->assertNotContains('customers.edit', $this->capabilitiesAt($this->delhi));

        $this->lock('customers.edit');

        $this->signInAsStaff($this->organization);
        $this->assertContains('customers.edit', $this->capabilitiesAt($this->delhi));

        // The branch's decision survived the lock, and comes back with it.
        $this->onTenant($this->organization, function () {
            $this->assertDatabaseCount('branch_role_capabilities', 1, 'organization');

            Role::on('organization')->findOrFail($this->role)
                ->capabilities()->where('capability', 'customers.edit')
                ->update(['is_locked' => false]);
        });

        $this->signInAsStaff($this->organization);
        $this->assertNotContains('customers.edit', $this->capabilitiesAt($this->delhi));
    }

    /**
     * An ORGANIZATION-scoped role is head office's, and a branch does not get
     * to clip what head office may do while standing in its building.
     *
     * Both halves of the union are checked here, because this is exactly where
     * a chain instead of a union would show: the branch removes the capability
     * from the role it IS allowed to customise, and the person keeps it anyway,
     * because their organization role granted it independently.
     */
    public function test_a_branch_cannot_customise_an_organization_scoped_role(): void
    {
        $headOffice = $this->onTenant($this->organization, function () {
            $role = Role::on('organization')->create([
                'name' => 'Network Desk',
                'slug' => 'head-office-test',
                'scope' => Role::SCOPE_ORGANIZATION,
            ]);

            $role->syncCapabilities(['customers.edit']);

            // Head office sits on the user; the branch membership stays, which
            // is what lets them work at Delhi at all.
            TenantUser::on(TenantConnectionService::CONNECTION)
                ->where('email', self::STAFF_EMAIL)
                ->firstOrFail()
                ->forceFill(['role_id' => $role->id])
                ->save();

            return $role->id;
        });

        // Delhi takes it off the branch role it may customise…
        $this->removeAt($this->delhi, 'customers.edit');

        // …and aims a row at head office's role, which is never consulted.
        $this->onTenant($this->organization, fn () => BranchRoleCapability::on('organization')->create([
            'location_id' => $this->delhi,
            'role_id' => $headOffice,
            'capability' => 'customers.edit',
        ]));

        $this->signInAsStaff($this->organization);

        $this->assertContains('customers.edit', $this->capabilitiesAt($this->delhi));
    }

    /**
     * TEST 1 — a switched-off module outranks every role decision under it,
     * customised or not. Level two still answers before level three.
     */
    public function test_a_disabled_module_beats_a_capability_the_branch_left_alone(): void
    {
        $this->setStaffCapabilities($this->organization, ['appointments.view']);
        $this->disableModuleAtBranch($this->organization, $this->delhi, 'appointments');

        $this->signInAsStaff($this->organization);

        $this->assertNotContains('appointments.view', $this->capabilitiesAt($this->delhi));
        $this->assertContains('appointments.view', $this->capabilitiesAt($this->lucknow));
    }

    /** A module the organization has locked cannot be switched off at a branch. */
    public function test_a_locked_module_cannot_be_switched_off_by_a_branch(): void
    {
        $this->onTenant($this->organization, fn () => ModuleLock::on('organization')
            ->create(['module_key' => 'appointments']));

        $this->signInAsOwner($this->organization);

        $this->putJson("/api/v1/tenant/locations/{$this->delhi}/modules", ['modules' => []])
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.modules.0',
                'Appointments is required by your organisation and cannot be switched off here.',
            );

        // And it is still running there.
        $this->getJson("/api/v1/tenant/locations/{$this->delhi}/modules")
            ->assertOk()
            ->assertJsonPath('data.modules.0.is_enabled', true)
            ->assertJsonPath('data.modules.0.is_locked', true);
    }
}
