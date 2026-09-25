<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\ActivityLog;
use App\Models\Tenant\BranchMembership;
use App\Models\Tenant\Location;
use App\Models\Tenant\Role;
use App\Models\Tenant\User as TenantUser;
use App\Models\Tenant\UserPermissionOverride;
use App\Services\Permissions\Permission;
use App\Services\Tenant\DefaultRoleSeeder;
use App\Support\Roles\RoleTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

/**
 * Branch managers, the seeded role library, and per-person denies.
 *
 * What is asserted: assigning a manager moves the membership and its role as
 * well as the column; changing one revokes the outgoing manager without
 * evicting them from the branch; a manager administers their own branch and
 * nobody else's; a deny takes a capability off one person without touching
 * the role; and none of it lets anybody grant themselves anything.
 */
class BranchManagerTest extends TenantTestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private int $gurgaon;

    private int $noida;

    /** Role id by slug, read once — see setUp. @var array<string, int> */
    private array $roles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');

        $this->organization = $this->provisionOrganization('M');

        foreach (['appointments', 'pharmacy', 'medicines', 'documents'] as $module) {
            $this->grantModule($this->organization, $module);
        }

        /*
         * Provisioning seeded the library before those modules were granted,
         * so it is rebuilt now the organization actually holds them — the
         * seeder is idempotent by name, so the existing rows are dropped
         * first rather than skipped.
         */
        $this->onTenant($this->organization, function () {
            Role::on('organization')
                ->whereIn('slug', array_column(RoleTemplates::all(), 'slug'))
                ->get()
                ->each(function (Role $role) {
                    $role->capabilities()->delete();
                    $role->delete();
                });

            app(DefaultRoleSeeder::class)->seed($this->organization);

            foreach (['Gurgaon' => 'M-GGN', 'Noida' => 'M-NOI'] as $name => $code) {
                $id = Location::on('organization')->create([
                    'name' => $name,
                    'code' => $code,
                    'type' => Location::CLINIC,
                    'is_active' => true,
                ])->id;

                $name === 'Gurgaon' ? $this->gurgaon = $id : $this->noida = $id;
            }

            /*
             * Read once, here. Looking a role up through onTenant() from
             * inside another onTenant() closure disconnects the outer one on
             * its way out — the inner `finally` does not know it was nested.
             */
            $this->roles = Role::on('organization')->pluck('id', 'slug')->all();
        });

        $this->signInAsOwner($this->organization);
    }

    /** A member of staff with no branch and no role yet. */
    private function makeStaff(string $email, string $name = 'Rahul'): int
    {
        return (int) $this->onTenant($this->organization, fn () => TenantUser::on('organization')->create([
            'name' => $name,
            'email' => $email,
            'password' => bcrypt(self::PASSWORD),
            'is_active' => true,
            'role' => TenantUser::STAFF,
        ])->id);
    }

    private function roleId(string $slug): int
    {
        return (int) ($this->roles[$slug] ?? 0);
    }

    public function test_the_role_library_is_seeded_from_what_the_organization_holds(): void
    {
        $slugs = $this->onTenant(
            $this->organization,
            fn () => Role::on('organization')->pluck('slug')->all(),
        );

        foreach (['branch-manager', 'doctor', 'receptionist', 'pharmacist', 'head-office'] as $expected) {
            $this->assertContains($expected, $slugs);
        }

        /*
         * A branch manager holds `people.roles` — they write their own
         * branch's roles — and must NOT hold the two that lift branch scope,
         * which is the whole boundary the role exists to have.
         */
        $manager = $this->onTenant(
            $this->organization,
            fn () => Role::on('organization')->with('capabilities')->where('slug', 'branch-manager')->first(),
        );

        $this->assertContains('people.roles', $manager->capabilityKeys());
        $this->assertNotContains('people.across_branches', $manager->capabilityKeys());
        $this->assertNotContains('people.assign_branch', $manager->capabilityKeys());
        $this->assertNotContains('settings.manage', $manager->capabilityKeys());
    }

    public function test_assigning_a_manager_gives_them_the_membership_and_the_role(): void
    {
        $rahul = $this->makeStaff('rahul@example.com');

        $this->putJson("/api/v1/tenant/locations/{$this->gurgaon}/manager", ['user_id' => $rahul])
            ->assertOk()
            ->assertJsonPath('data.manager_id', $rahul);

        $this->onTenant($this->organization, function () use ($rahul) {
            $membership = BranchMembership::on('organization')
                ->where('user_id', $rahul)
                ->where('location_id', $this->gurgaon)
                ->first();

            // The column alone would be a name that grants nothing.
            $this->assertNotNull($membership);
            $this->assertSame($this->roleId('branch-manager'), (int) $membership->role_id);
        });
    }

    public function test_changing_the_manager_revokes_the_role_but_keeps_the_membership(): void
    {
        $rahul = $this->makeStaff('rahul@example.com', 'Rahul');
        $amit = $this->makeStaff('amit@example.com', 'Amit');

        $this->putJson("/api/v1/tenant/locations/{$this->gurgaon}/manager", ['user_id' => $rahul])
            ->assertOk();

        $this->putJson("/api/v1/tenant/locations/{$this->gurgaon}/manager", ['user_id' => $amit])
            ->assertOk()
            ->assertJsonPath('data.manager_id', $amit);

        $this->onTenant($this->organization, function () use ($rahul) {
            $previous = BranchMembership::on('organization')
                ->where('user_id', $rahul)
                ->where('location_id', $this->gurgaon)
                ->first();

            /*
             * Still at Gurgaon, holding nothing there. Somebody stepping down
             * from running a branch usually still works at it, and deleting
             * the row would erase the record of their ever having been there.
             */
            $this->assertNotNull($previous);
            $this->assertNull($previous->role_id);
        });
    }

    public function test_the_change_is_audited(): void
    {
        $rahul = $this->makeStaff('rahul@example.com');

        $this->putJson("/api/v1/tenant/locations/{$this->gurgaon}/manager", ['user_id' => $rahul])
            ->assertOk();

        $this->onTenant($this->organization, function () {
            $entry = ActivityLog::on('organization')
                ->where('entity_type', 'Location')
                ->where('entity_id', $this->gurgaon)
                ->where('event', 'updated')
                ->latest('id')
                ->first();

            $this->assertNotNull($entry, 'The manager change was not recorded.');
            $this->assertArrayHasKey('manager_id', $entry->after ?? []);
        });
    }

    public function test_an_owner_cannot_be_made_a_branch_manager(): void
    {
        $ownerId = (int) $this->onTenant(
            $this->organization,
            fn () => TenantUser::on('organization')->where('role', TenantUser::OWNER)->value('id'),
        );

        $this->putJson("/api/v1/tenant/locations/{$this->gurgaon}/manager", ['user_id' => $ownerId])
            ->assertStatus(422);
    }

    public function test_appointing_a_manager_needs_its_own_capability(): void
    {
        $rahul = $this->makeStaff('rahul@example.com');

        // Everything about branches EXCEPT naming who runs one.
        $this->setStaffCapabilities($this->organization, [
            'branches.view', 'branches.create', 'branches.edit', 'people.view', 'people.edit',
        ]);
        $this->placeStaffAt($this->organization, $this->gurgaon);
        $this->signInAsStaff($this->organization);

        $this->putJson("/api/v1/tenant/locations/{$this->gurgaon}/manager", ['user_id' => $rahul])
            ->assertStatus(403);
    }

    public function test_a_branch_manager_reaches_their_own_branch_and_no_other(): void
    {
        // The seeded staff member becomes the Gurgaon manager.
        $this->onTenant($this->organization, function () {
            $staff = TenantUser::on('organization')->where('email', self::STAFF_EMAIL)->firstOrFail();

            BranchMembership::on('organization')->updateOrCreate(
                ['user_id' => $staff->id, 'location_id' => $this->gurgaon],
                ['role_id' => $this->roleId('branch-manager'), 'is_primary' => true],
            );

            $staff->forceFill(['role_id' => null])->save();

            Location::on('organization')->whereKey($this->gurgaon)
                ->update(['manager_id' => $staff->id]);
        });

        // Somebody who works at Noida and nowhere else.
        $neha = $this->makeStaff('neha@example.com', 'Neha');

        $this->onTenant($this->organization, fn () => BranchMembership::on('organization')->create([
            'user_id' => $neha,
            'location_id' => $this->noida,
            'role_id' => $this->roleId('receptionist'),
        ]));

        $this->signInAsStaff($this->organization);

        // Not in the staff list…
        $names = collect($this->getJson('/api/v1/tenant/users')->assertOk()->json('data'))
            ->pluck('email')
            ->all();

        $this->assertNotContains('neha@example.com', $names);

        // …and not reachable by id: 404, so the id is never confirmed.
        $this->getJson("/api/v1/tenant/users/{$neha}")->assertStatus(404);

        // Nor can they act at Noida at all.
        $this->withHeader('X-Branch-Id', (string) $this->noida)
            ->getJson('/api/v1/tenant/users')
            ->assertStatus(403);
    }

    public function test_a_deny_takes_a_capability_off_one_person_without_touching_the_role(): void
    {
        $neha = $this->makeStaff('neha@example.com', 'Neha');

        $this->onTenant($this->organization, fn () => BranchMembership::on('organization')->create([
            'user_id' => $neha,
            'location_id' => $this->gurgaon,
            'role_id' => $this->roleId('receptionist'),
        ]));

        $this->putJson("/api/v1/tenant/users/{$neha}/permissions", [
            'denied' => ['customers.edit'],
        ])->assertOk();

        $this->onTenant($this->organization, function () use ($neha) {
            $user = TenantUser::on('organization')
                ->with(['memberships.role.capabilities', 'permissionOverrides'])
                ->findOrFail($neha);

            $held = app(Permission::class)->heldBy($user, $this->gurgaon);

            $this->assertNotContains('customers.edit', $held);
            // The rest of the role is untouched.
            $this->assertContains('customers.view', $held);

            // And the ROLE itself still grants it — this is one person's
            // exception, not an edit to what a receptionist is.
            $role = Role::on('organization')->with('capabilities')->find($this->roleId('receptionist'));
            $this->assertContains('customers.edit', $role->capabilityKeys());
        });
    }

    public function test_a_deny_is_enforced_by_the_api_not_only_reported(): void
    {
        $this->setStaffCapabilities($this->organization, [
            'customers.view', 'customers.create', 'customers.edit',
        ]);
        $this->placeStaffAt($this->organization, $this->gurgaon);

        $staffId = (int) $this->onTenant(
            $this->organization,
            fn () => TenantUser::on('organization')->where('email', self::STAFF_EMAIL)->value('id'),
        );

        $this->onTenant($this->organization, fn () => UserPermissionOverride::on('organization')->create([
            'user_id' => $staffId,
            'location_id' => null,
            'capability' => 'customers.create',
            'effect' => UserPermissionOverride::DENY,
        ]));

        $this->signInAsStaff($this->organization);

        // Reading is still theirs; creating is not.
        $this->getJson('/api/v1/tenant/customers')->assertOk();

        $this->postJson('/api/v1/tenant/customers', [
            'name' => 'Denied Patient',
            'phone' => '9000000099',
        ])->assertStatus(403);
    }

    public function test_the_permissions_screen_refuses_somebody_out_of_reach(): void
    {
        $neha = $this->makeStaff('neha@example.com', 'Neha');

        $this->onTenant($this->organization, fn () => BranchMembership::on('organization')->create([
            'user_id' => $neha,
            'location_id' => $this->noida,
            'role_id' => $this->roleId('receptionist'),
        ]));

        $this->setStaffCapabilities($this->organization, ['people.view', 'people.edit']);
        $this->placeStaffAt($this->organization, $this->gurgaon);
        $this->signInAsStaff($this->organization);

        $this->getJson("/api/v1/tenant/users/{$neha}/permissions")->assertStatus(404);
        $this->putJson("/api/v1/tenant/users/{$neha}/permissions", ['denied' => []])
            ->assertStatus(404);
    }

    public function test_an_owner_cannot_be_denied_anything(): void
    {
        $ownerId = (int) $this->onTenant(
            $this->organization,
            fn () => TenantUser::on('organization')->where('role', TenantUser::OWNER)->value('id'),
        );

        $this->putJson("/api/v1/tenant/users/{$ownerId}/permissions", [
            'denied' => ['customers.edit'],
        ])->assertStatus(422);
    }

    public function test_a_retired_capability_cannot_be_denied(): void
    {
        $neha = $this->makeStaff('neha@example.com', 'Neha');

        $this->putJson("/api/v1/tenant/users/{$neha}/permissions", [
            'denied' => ['customers.teleport'],
        ])->assertStatus(422);
    }
}
