<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\BranchMembership;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Location;
use App\Models\Tenant\LocationModule;
use App\Models\Tenant\Role;
use App\Models\Tenant\User;
use App\Services\Permissions\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

/**
 * The three-level permission flow, end to end.
 *
 * The cases that matter are not "a role can be saved" but the ones where the
 * levels disagree: a role holding a capability whose module was never sold, a
 * branch that has the module switched off under it, an owner who bypasses
 * roles and must still not bypass entitlements, and the several ways an owner
 * could accidentally hand somebody the keys.
 *
 * Each of these fails without the thing it is testing.
 */
class PermissionTest extends TenantTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');
    }

    /** An organization with two branches; the staff member works at the first. */
    /** @return array{0: Organization, 1: int, 2: int} */
    private function network(): array
    {
        $organization = $this->provisionOrganization('P');

        [$here, $there] = $this->onTenant($organization, function () {
            $here = Location::on('organization')->create([
                'name' => 'Gurgaon', 'code' => 'P-GGN',
                'type' => Location::CLINIC, 'is_active' => true,
            ]);

            $there = Location::on('organization')->create([
                'name' => 'Delhi', 'code' => 'P-DEL',
                'type' => Location::CLINIC, 'is_active' => true,
            ]);

            return [$here->id, $there->id];
        });

        // Their membership, not a column: branch now comes from where somebody
        // is a member, and one person can be a member of several.
        $this->placeStaffAt($organization, $here);

        return [$organization, $here, $there];
    }

    /*
    |--------------------------------------------------------------------------
    | Level three — the role
    |--------------------------------------------------------------------------
    */

    public function test_a_capability_the_role_does_not_hold_is_refused(): void
    {
        [$organization] = $this->network();

        // The seeded staff role holds customers.create and customers.edit but not customers.delete.
        $customerId = $this->onTenant($organization, fn () => Customer::on('organization')->create([
            'name' => 'Asha Rane', 'phone' => '9876500021', 'is_active' => true,
        ])->id);

        $this->signInAsStaff($organization);
        $this->deleteJson("/api/v1/tenant/customers/{$customerId}")->assertStatus(403);

        // Granted, the very same request goes through — so the refusal above
        // was the capability and not something else about being staff.
        $this->setStaffCapabilities($organization, ['customers.view', 'customers.delete']);

        $this->signInAsStaff($organization);
        $this->deleteJson("/api/v1/tenant/customers/{$customerId}")->assertOk();
    }

    /**
     * Adding, changing and removing are three separate permissions.
     *
     * The whole point of splitting `manage`: an owner can now write a role
     * that takes a new patient's details but cannot touch an existing record,
     * or one that corrects records but cannot destroy them. A single key could
     * express neither.
     */
    public function test_creating_editing_and_removing_are_three_permissions(): void
    {
        [$organization] = $this->network();

        $customerId = $this->onTenant($organization, fn () => Customer::on('organization')->create([
            'name' => 'Asha Rane', 'phone' => '9876500031', 'is_active' => true,
        ])->id);

        $body = ['name' => 'Asha Rane', 'phone' => '9876500031', 'is_active' => true];

        // Only allowed to add. Editing and removing are refused.
        $this->setStaffCapabilities($organization, ['customers.view', 'customers.create']);
        $this->signInAsStaff($organization);

        $this->postJson('/api/v1/tenant/customers', [
            'name' => 'Bina Rao', 'phone' => '9876500032', 'is_active' => true,
        ])->assertCreated();
        $this->putJson("/api/v1/tenant/customers/{$customerId}", $body)->assertStatus(403);
        $this->deleteJson("/api/v1/tenant/customers/{$customerId}")->assertStatus(403);

        // Only allowed to edit. Adding and removing are refused.
        $this->setStaffCapabilities($organization, ['customers.view', 'customers.edit']);
        $this->signInAsStaff($organization);

        $this->postJson('/api/v1/tenant/customers', [
            'name' => 'Chetan Rao', 'phone' => '9876500033', 'is_active' => true,
        ])->assertStatus(403);
        $this->putJson("/api/v1/tenant/customers/{$customerId}", $body)->assertOk();
        $this->deleteJson("/api/v1/tenant/customers/{$customerId}")->assertStatus(403);

        // Only allowed to remove.
        $this->setStaffCapabilities($organization, ['customers.view', 'customers.delete']);
        $this->signInAsStaff($organization);

        $this->putJson("/api/v1/tenant/customers/{$customerId}", $body)->assertStatus(403);
        $this->deleteJson("/api/v1/tenant/customers/{$customerId}")->assertOk();
    }

    /**
     * The seeded role kept exactly what it could do before the split.
     *
     * `customers.manage` became create and edit, and not delete — which is
     * what that one key allowed. A migration that widened it would have handed
     * every existing member of staff a power nobody granted them.
     */
    public function test_the_split_migration_preserved_what_staff_could_already_do(): void
    {
        [$organization] = $this->network();

        $held = $this->onTenant($organization, fn () => Role::on('organization')
            ->where('slug', Role::SEEDED_STAFF)
            ->firstOrFail()
            ->load('capabilities')
            ->capabilityKeys());

        sort($held);

        $this->assertSame([
            'appointments.book',
            'appointments.cancel',
            'appointments.queue',
            'appointments.view',
            'branches.view',
            'customers.create',
            'customers.edit',
            'customers.view',
        ], $held);

        // The keys the split replaced are gone, not lying around unmatched.
        foreach (['customers.manage', 'customers.remove', 'branches.manage'] as $retired) {
            $this->assertNotContains($retired, $held);
        }
    }

    /**
     * "No role" now means both ways of holding one are empty.
     *
     * A person's capabilities are the union of their organization-scoped role
     * and the branch-scoped role on their membership. Clearing only the first
     * leaves the second granting everything it always did.
     */
    public function test_a_staff_member_with_no_role_can_do_nothing(): void
    {
        [$organization, $here] = $this->network();

        $this->onTenant($organization, function () use ($here) {
            $user = User::on('organization')->where('email', self::STAFF_EMAIL)->firstOrFail();

            $user->forceFill(['role_id' => null])->save();

            BranchMembership::on('organization')
                ->where('user_id', $user->id)
                ->where('location_id', $here)
                ->update(['role_id' => null]);
        });

        $this->signInAsStaff($organization);

        // Not "everything", which is what a null role would mean if absence
        // were read as no restriction.
        $this->getJson('/api/v1/tenant/customers')->assertStatus(403);
        $this->getJson('/api/v1/tenant/locations')->assertStatus(403);
    }

    /**
     * Nothing is reachable by typing the URL.
     *
     * The client hides what somebody may not use, but hiding is a courtesy — a
     * guard in a bundle can be edited out, and the only thing that actually
     * stands between a person and a record is the server. So every gated route
     * is walked here by a member of staff holding nothing, and every one of
     * them has to refuse.
     *
     * Real ids throughout: a made-up id 404s in SubstituteBindings before the
     * permission middleware ever runs, which would make this pass while
     * proving nothing.
     */
    public function test_every_gated_route_refuses_somebody_holding_nothing(): void
    {
        [$organization, $here] = $this->network();

        $this->grantModule($organization, 'appointments');

        [$customerId, $userId, $roleId] = $this->onTenant($organization, fn () => [
            Customer::on('organization')->create([
                'name' => 'Asha Rane', 'phone' => '9876500041', 'is_active' => true,
            ])->id,
            User::on('organization')->where('email', self::STAFF_EMAIL)->value('id'),
            Role::on('organization')->where('slug', Role::SEEDED_STAFF)->value('id'),
        ]);

        // A role that holds nothing at all.
        $this->setStaffCapabilities($organization, []);
        $this->signInAsStaff($organization);

        $routes = [
            ['get', '/api/v1/tenant/customers'],
            ['get', "/api/v1/tenant/customers/{$customerId}"],
            ['post', '/api/v1/tenant/customers'],
            ['put', "/api/v1/tenant/customers/{$customerId}"],
            ['delete', "/api/v1/tenant/customers/{$customerId}"],

            ['get', '/api/v1/tenant/locations'],
            ['post', '/api/v1/tenant/locations'],
            ['put', "/api/v1/tenant/locations/{$here}"],
            ['delete', "/api/v1/tenant/locations/{$here}"],

            ['get', '/api/v1/tenant/users'],
            ['post', '/api/v1/tenant/users'],
            ['put', "/api/v1/tenant/users/{$userId}"],
            ['delete', "/api/v1/tenant/users/{$userId}"],

            ['get', '/api/v1/tenant/doctors'],
            ['post', '/api/v1/tenant/doctors'],
            ['get', '/api/v1/tenant/availability/day'],
            ['get', '/api/v1/tenant/appointments'],
            ['post', '/api/v1/tenant/appointments'],

            ['get', '/api/v1/tenant/history'],
            ['put', '/api/v1/tenant/settings/fields/customer'],

            // Levels two and three: the owner's alone, not delegatable.
            ['get', '/api/v1/tenant/roles'],
            ['post', '/api/v1/tenant/roles'],
            ['put', "/api/v1/tenant/roles/{$roleId}"],
            ['delete', "/api/v1/tenant/roles/{$roleId}"],
            ['get', "/api/v1/tenant/roles/{$roleId}/members"],
            ['get', "/api/v1/tenant/locations/{$here}/modules"],
            ['put', "/api/v1/tenant/locations/{$here}/modules"],
        ];

        foreach ($routes as [$method, $url]) {
            $this->{$method.'Json'}($url)->assertStatus(
                403,
                strtoupper($method)." {$url} was reachable by somebody holding nothing."
            );
        }
    }

    public function test_the_owner_bypasses_roles_but_not_entitlements(): void
    {
        [$organization] = $this->network();

        // No role exists that holds this, and the owner still may.
        $this->signInAsOwner($organization);
        $this->getJson('/api/v1/tenant/history')->assertOk();

        // But appointments were never sold to this organization.
        $this->getJson('/api/v1/tenant/doctors')->assertStatus(403);
    }

    /**
     * One person, two branches, a different role at each.
     *
     * The whole reason membership replaced `users.location_id`. Rahul is a
     * receptionist at Lucknow and a manager at Delhi, and neither role leaks
     * into the other — the Delhi permission to remove a patient means nothing
     * when he is working at Lucknow.
     *
     * Asked of the service rather than over HTTP: which branch a request is
     * happening in is resolved by the branch middleware, which is the next
     * step. This proves the resolution underneath it.
     */
    public function test_a_role_at_one_branch_does_not_reach_another(): void
    {
        [$organization, $lucknow, $delhi] = $this->network();

        [$user, $reception, $manager] = $this->onTenant(
            $organization,
            function () use ($lucknow, $delhi) {
                $reception = Role::on('organization')->create([
                    'name' => 'Reception', 'slug' => 'reception', 'scope' => Role::SCOPE_BRANCH,
                ]);
                $reception->syncCapabilities(['customers.view']);

                $manager = Role::on('organization')->create([
                    'name' => 'Manager', 'slug' => 'manager', 'scope' => Role::SCOPE_BRANCH,
                ]);
                $manager->syncCapabilities(['customers.view', 'customers.delete']);

                $user = User::on('organization')->where('email', self::STAFF_EMAIL)->firstOrFail();
                $user->forceFill(['role_id' => null])->save();

                BranchMembership::on('organization')->updateOrCreate(
                    ['user_id' => $user->id, 'location_id' => $lucknow],
                    ['role_id' => $reception->id, 'is_primary' => true],
                );

                BranchMembership::on('organization')->create([
                    'user_id' => $user->id,
                    'location_id' => $delhi,
                    'role_id' => $manager->id,
                ]);

                return [$user->fresh()->load('memberships.role'), $reception->id, $manager->id];
            }
        );

        $permission = $this->onTenant($organization, fn () => app(Permission::class));

        $this->onTenant($organization, function () use ($permission, $organization, $user, $lucknow, $delhi) {
            // Seeing patients: both branches, because both roles allow it.
            $this->assertTrue($permission->allows($organization, $user, 'customers.view', $lucknow));
            $this->assertTrue($permission->allows($organization, $user, 'customers.view', $delhi));

            // Removing one: only where the role that allows it was granted.
            $this->assertFalse($permission->allows($organization, $user, 'customers.delete', $lucknow));
            $this->assertTrue($permission->allows($organization, $user, 'customers.delete', $delhi));

            // And the capability list differs by branch, which is the fact the
            // client has to be told rather than left to work out.
            $this->assertNotContains(
                'customers.delete',
                $permission->capabilitiesFor($organization, $user, $lucknow),
            );
            $this->assertContains(
                'customers.delete',
                $permission->capabilitiesFor($organization, $user, $delhi),
            );
        });

        $this->assertNotSame($reception, $manager);
    }

    /**
     * An organization-scoped role applies at every branch.
     *
     * The other half of the union: head office's role is not confined to a
     * branch, so it holds wherever they are asked about.
     */
    public function test_an_organization_role_reaches_every_branch(): void
    {
        [$organization, $lucknow, $delhi] = $this->network();

        $user = $this->onTenant($organization, function () use ($lucknow) {
            $orgRole = Role::on('organization')->create([
                'name' => 'Head office', 'slug' => 'head-office',
                'scope' => Role::SCOPE_ORGANIZATION,
            ]);
            $orgRole->syncCapabilities(['customers.view']);

            $user = User::on('organization')->where('email', self::STAFF_EMAIL)->firstOrFail();
            $user->forceFill(['role_id' => $orgRole->id])->save();

            // A membership holding nothing: they belong to the branch but the
            // reach comes from the organization role, not from being there.
            BranchMembership::on('organization')->updateOrCreate(
                ['user_id' => $user->id, 'location_id' => $lucknow],
                ['role_id' => null],
            );

            return $user->fresh()->load(['memberships', 'permissionRole.capabilities']);
        });

        $this->onTenant($organization, function () use ($organization, $user, $lucknow, $delhi) {
            $permission = app(Permission::class);

            $this->assertTrue($permission->allows($organization, $user, 'customers.view', $lucknow));
            $this->assertTrue($permission->allows($organization, $user, 'customers.view', $delhi));
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Level one beats level three
    |--------------------------------------------------------------------------
    */

    public function test_a_role_cannot_be_given_a_capability_the_organization_lacks(): void
    {
        [$organization] = $this->network();

        $this->signInAsOwner($organization);

        $this->postJson('/api/v1/tenant/roles', [
            'name' => 'Receptionist',
            'capabilities' => ['customers.view', 'appointments.book'],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('capabilities.1');

        // Sold, and the same request is accepted.
        $this->grantModule($organization, 'appointments');

        $this->postJson('/api/v1/tenant/roles', [
            'name' => 'Receptionist',
            'capabilities' => ['customers.view', 'appointments.book'],
        ])->assertCreated();
    }

    public function test_a_capability_survives_its_module_being_withdrawn_but_stops_working(): void
    {
        [$organization] = $this->network();

        $this->grantModule($organization, 'appointments');
        $this->setStaffCapabilities($organization, ['appointments.view']);

        $this->signInAsStaff($organization);
        $this->getJson('/api/v1/tenant/doctors')->assertOk();

        // Taken back at the platform. The role row is untouched — an
        // entitlement lapsing must not destroy what the owner configured, or
        // renewing would mean setting every role up again.
        $organization->moduleEntitlements()->update(['is_enabled' => false]);

        $this->signInAsStaff($organization);
        $this->getJson('/api/v1/tenant/doctors')->assertStatus(403);

        $this->assertContains(
            'appointments.view',
            $this->onTenant($organization, fn () => Role::on('organization')
                ->where('slug', Role::SEEDED_STAFF)
                ->firstOrFail()
                ->load('capabilities')
                ->capabilityKeys()),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Level two — the branch
    |--------------------------------------------------------------------------
    */

    public function test_a_branch_with_no_rows_inherits_everything(): void
    {
        [$organization] = $this->network();

        $this->grantModule($organization, 'appointments');
        $this->setStaffCapabilities($organization, ['appointments.view']);

        $this->assertSame(
            0,
            $this->onTenant($organization, fn () => LocationModule::on('organization')->count()),
            'Level two must be opt-in: nothing is written until the owner decides something.'
        );

        $this->signInAsStaff($organization);
        $this->getJson('/api/v1/tenant/doctors')->assertOk();
    }

    public function test_a_module_switched_off_at_a_branch_is_refused_there(): void
    {
        [$organization, $here, $there] = $this->network();

        $this->grantModule($organization, 'appointments');
        $this->setStaffCapabilities($organization, ['appointments.view']);

        $this->disableModuleAtBranch($organization, $here, 'appointments');

        // The staff member works at `here`.
        $this->signInAsStaff($organization);
        $this->getJson('/api/v1/tenant/doctors')->assertStatus(403);

        // Move them to the other branch and the same request is fine, so it
        // is the branch being refused and not the person.
        $this->removeStaffFrom($organization, $here);
        $this->placeStaffAt($organization, $there);

        $this->signInAsStaff($organization);
        $this->getJson('/api/v1/tenant/doctors')->assertOk();
    }

    public function test_the_owner_is_not_narrowed_by_any_one_branch(): void
    {
        [$organization, $here] = $this->network();

        $this->grantModule($organization, 'appointments');
        $this->disableModuleAtBranch($organization, $here, 'appointments');

        // The owner works across the network, so one branch opting out does
        // not take the module away from them.
        $this->signInAsOwner($organization);
        $this->getJson('/api/v1/tenant/doctors')->assertOk();
    }

    public function test_switching_a_branch_back_on_removes_the_row_rather_than_storing_true(): void
    {
        [$organization, $here] = $this->network();

        $this->grantModule($organization, 'appointments');
        $this->signInAsOwner($organization);

        $this->putJson("/api/v1/tenant/locations/{$here}/modules", ['modules' => []])
            ->assertOk()
            ->assertJsonPath('data.modules.0.is_enabled', false);

        $this->assertSame(
            1,
            $this->onTenant($organization, fn () => LocationModule::on('organization')->count()),
        );

        $this->putJson("/api/v1/tenant/locations/{$here}/modules", [
            'modules' => ['appointments'],
        ])
            ->assertOk()
            ->assertJsonPath('data.modules.0.is_enabled', true);

        /*
         * Back to no row at all, not a row saying true. A stored true would
         * freeze this branch at today's answer, and a module withdrawn from
         * the organization would go on reading as switched on here.
         */
        $this->assertSame(
            0,
            $this->onTenant($organization, fn () => LocationModule::on('organization')->count()),
        );
    }

    public function test_a_branch_cannot_be_given_a_module_the_organization_lacks(): void
    {
        [$organization, $here] = $this->network();

        $this->signInAsOwner($organization);

        $this->putJson("/api/v1/tenant/locations/{$here}/modules", [
            'modules' => ['appointments'],
        ])->assertStatus(422)->assertJsonValidationErrors('modules.0');
    }

    /**
     * A branch cannot run a module without the one it depends on: switching
     * medicines off while prescriptions stays on is refused, and nothing is
     * written.
     */
    public function test_a_branch_cannot_switch_off_what_another_module_needs(): void
    {
        // The migration seeds a frozen catalogue; the pharmacy modules arrive
        // the way a deploy adds them.
        $this->artisan('modules:sync');

        [$organization, $here] = $this->network();

        $this->grantModule($organization, 'medicines');
        $this->grantModule($organization, 'prescriptions');
        $this->signInAsOwner($organization);

        $this->putJson("/api/v1/tenant/locations/{$here}/modules", [
            'modules' => ['prescriptions'],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('modules')
            ->assertJsonPath('errors.modules.0', 'Prescriptions needs Medicines. Enable it too.');

        $this->assertSame(
            0,
            $this->onTenant($organization, fn () => LocationModule::on('organization')->count()),
        );

        // Both off, or both on, are consistent.
        $this->putJson("/api/v1/tenant/locations/{$here}/modules", ['modules' => []])->assertOk();
        $this->putJson("/api/v1/tenant/locations/{$here}/modules", [
            'modules' => ['medicines', 'prescriptions'],
        ])->assertOk();
    }

    /**
     * Level two stays the owner's; level three no longer does.
     *
     * Which modules run at a branch is not delegatable at all — a branch that
     * could switch its own on could re-open a door the owner closed.
     *
     * Roles are different. In a large organization the owner is not going to
     * write every one, so a branch writes its own — bounded not by central
     * authorship but by the cascade: it may only draw on the modules that
     * branch was given. What it still cannot do is touch the organization's
     * own roles.
     */
    public function test_level_two_stays_the_owners_and_level_three_does_not(): void
    {
        [$organization, $here] = $this->network();

        $this->setStaffCapabilities($organization, [
            'branches.view', 'branches.create', 'branches.edit', 'branches.delete',
            'people.view', 'people.create', 'people.edit', 'people.delete',
            'people.roles',
        ]);

        $this->signInAsStaff($organization);

        // Level two: refused, whatever else they hold.
        $this->getJson("/api/v1/tenant/locations/{$here}/modules")->assertStatus(403);

        // Level three: they may read the roles they are assigning...
        $this->getJson('/api/v1/tenant/roles')->assertOk();

        // ...and write one for their own branch.
        $mine = $this->postJson('/api/v1/tenant/roles', [
            'name' => 'Front desk',
            'capabilities' => ['customers.view'],
        ])->assertCreated()->json('data');

        $this->assertSame($here, $mine['location_id']);

        // But not touch one the organization wrote.
        $orgRoleId = $this->staffRoleId($organization);

        $this->putJson("/api/v1/tenant/roles/{$orgRoleId}", [
            'name' => 'Renamed',
            'capabilities' => [],
        ])->assertStatus(403);

        $this->deleteJson("/api/v1/tenant/roles/{$orgRoleId}")->assertStatus(403);
    }

    /** Without `people.roles` a branch admin reads roles and writes none. */
    public function test_writing_a_branch_role_needs_its_own_capability(): void
    {
        [$organization] = $this->network();

        $this->setStaffCapabilities($organization, ['people.view', 'people.edit']);
        $this->signInAsStaff($organization);

        $this->getJson('/api/v1/tenant/roles')->assertOk();

        $this->postJson('/api/v1/tenant/roles', [
            'name' => 'Front desk',
            'capabilities' => [],
        ])->assertStatus(403);
    }

    /*
    |--------------------------------------------------------------------------
    | Roles as records
    |--------------------------------------------------------------------------
    */

    public function test_a_role_in_use_cannot_be_deleted(): void
    {
        [$organization] = $this->network();

        $roleId = $this->staffRoleId($organization);

        $this->signInAsOwner($organization);

        // The system role is refused for being a system role…
        $this->deleteJson("/api/v1/tenant/roles/{$roleId}")->assertStatus(422);

        $created = $this->postJson('/api/v1/tenant/roles', [
            'name' => 'Receptionist',
            'capabilities' => ['customers.view'],
        ])->assertCreated()->json('data');

        // …an ordinary one with nobody on it goes.
        $this->deleteJson("/api/v1/tenant/roles/{$created['id']}")->assertOk();

        $again = $this->postJson('/api/v1/tenant/roles', [
            'name' => 'Receptionist',
            'capabilities' => ['customers.view'],
        ])->assertCreated()->json('data');

        $this->onTenant($organization, fn () => User::on('organization')
            ->where('email', self::STAFF_EMAIL)
            ->update(['role_id' => $again['id']]));

        // …and one somebody holds is refused, with a count rather than a
        // foreign key error nobody can act on.
        $this->deleteJson("/api/v1/tenant/roles/{$again['id']}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'One person holds this role. Move them to another role first.');
    }

    /**
     * The icon reaches a `class` attribute in the client, so it has to be one
     * the software chose rather than one somebody sent.
     */
    public function test_a_roles_icon_is_kept_to_the_closed_list(): void
    {
        [$organization] = $this->network();

        $this->signInAsOwner($organization);

        $this->postJson('/api/v1/tenant/roles', [
            'name' => 'Receptionist',
            'icon' => 'ti ti-headset',
            'capabilities' => [],
        ])
            ->assertCreated()
            ->assertJsonPath('data.icon', 'ti ti-headset');

        $this->postJson('/api/v1/tenant/roles', [
            'name' => 'Trouble',
            'icon' => '" onload="alert(1)',
            'capabilities' => [],
        ])->assertStatus(422)->assertJsonValidationErrors('icon');

        // Left out entirely, a role still has something to render.
        $this->postJson('/api/v1/tenant/roles', [
            'name' => 'Plain',
            'capabilities' => [],
        ])
            ->assertCreated()
            ->assertJsonPath('data.icon', Role::DEFAULT_ICON);
    }

    public function test_the_members_endpoint_names_who_holds_a_role(): void
    {
        [$organization, $here] = $this->network();

        $roleId = $this->staffRoleId($organization);

        $this->signInAsOwner($organization);

        $members = $this->getJson("/api/v1/tenant/roles/{$roleId}/members")
            ->assertOk()
            ->json('data');

        // Held on a membership now, so the branch it is held at comes with it.
        $this->assertCount(1, $members);
        $this->assertSame(self::STAFF_EMAIL, $members[0]['email']);
        $this->assertSame('Gurgaon', $members[0]['location']);

        // The owner holds no role, so they are nobody's member.
        $this->assertNotContains($organization->email, array_column($members, 'email'));
    }

    public function test_editing_a_role_replaces_its_capabilities_rather_than_adding(): void
    {
        [$organization] = $this->network();

        $this->signInAsOwner($organization);

        $role = $this->postJson('/api/v1/tenant/roles', [
            'name' => 'Receptionist',
            'capabilities' => ['customers.view', 'customers.edit'],
        ])->assertCreated()->json('data');

        $updated = $this->putJson("/api/v1/tenant/roles/{$role['id']}", [
            'name' => 'Front desk',
            'capabilities' => ['customers.view'],
        ])->assertOk()->json('data');

        $this->assertSame(['customers.view'], $updated['capabilities']);

        // Renaming does not move the slug — it is what the code refers to a
        // system role by, and a rename should change what people read.
        $this->assertSame($role['slug'], $updated['slug']);
    }

    /*
    |--------------------------------------------------------------------------
    | The ways an owner could give away the keys by accident
    |--------------------------------------------------------------------------
    */

    public function test_delegated_people_management_cannot_create_an_owner(): void
    {
        [$organization] = $this->network();

        $this->setStaffCapabilities($organization, ['people.view', 'people.create']);
        $this->signInAsStaff($organization);

        // The capability is real — they can add somebody.
        $this->postJson('/api/v1/tenant/users', [
            'name' => 'Priya Sharma',
            'email' => 'priya-'.uniqid().'@example.com',
            'password' => 'Str0ng!Pass',
            'password_confirmation' => 'Str0ng!Pass',
            'role' => User::STAFF,
            'is_active' => true,
        ])->assertCreated();

        // But not somebody who bypasses every level, which would be a way of
        // granting themselves the permissions they were not given.
        $this->postJson('/api/v1/tenant/users', [
            'name' => 'Second Owner',
            'email' => 'owner-'.uniqid().'@example.com',
            'password' => 'Str0ng!Pass',
            'password_confirmation' => 'Str0ng!Pass',
            'role' => User::OWNER,
            'is_active' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('role');
    }

    /**
     * `users.role_id` is the head-office slot, and only that.
     *
     * Staff need nothing there — branch staff hold their role on a membership.
     * A BRANCH role is refused here too: assigned to the person rather than to
     * a membership it would apply across the network, which is a limit that
     * reads as branch-specific and is not.
     */
    public function test_the_organization_role_slot_takes_only_organization_roles(): void
    {
        [$organization] = $this->network();

        $this->signInAsOwner($organization);

        $base = [
            'name' => 'Somebody',
            'password' => 'Str0ng!Pass',
            'password_confirmation' => 'Str0ng!Pass',
            'is_active' => true,
        ];

        // Staff with no organization role: fine. Theirs comes from a branch.
        $this->postJson('/api/v1/tenant/users', $base + [
            'email' => 'a-'.uniqid().'@example.com',
            'role' => User::STAFF,
        ])->assertCreated();

        // The seeded `staff` role is branch-scoped, so it cannot go here.
        $this->postJson('/api/v1/tenant/users', $base + [
            'email' => 'b-'.uniqid().'@example.com',
            'role' => User::STAFF,
            'role_id' => $this->staffRoleId($organization),
        ])->assertStatus(422)->assertJsonValidationErrors('role_id');

        // An owner is refused one whatever its scope.
        $orgRoleId = $this->onTenant($organization, fn () => Role::on('organization')->create([
            'name' => 'Head office', 'slug' => 'head-office-slot',
            'scope' => Role::SCOPE_ORGANIZATION,
        ])->id);

        $this->postJson('/api/v1/tenant/users', $base + [
            'email' => 'c-'.uniqid().'@example.com',
            'role' => User::OWNER,
            'role_id' => $orgRoleId,
        ])->assertStatus(422)->assertJsonValidationErrors('role_id');
    }

    /*
    |--------------------------------------------------------------------------
    | What the client is told
    |--------------------------------------------------------------------------
    */

    public function test_me_answers_with_the_persons_own_capabilities_not_the_pool(): void
    {
        [$organization] = $this->network();

        $this->grantModule($organization, 'appointments');
        $this->setStaffCapabilities($organization, ['customers.view']);

        $this->signInAsStaff($organization);
        $mine = $this->getJson('/api/v1/tenant/auth/me')->assertOk()->json('data');

        $this->assertSame(['customers.view'], $mine['capabilities']);

        // The organization holds far more than that, which is exactly the
        // mistake this is guarding: answering with the pool would have every
        // client believing a member of staff could do all of it.
        $this->signInAsOwner($organization);
        $theirs = $this->getJson('/api/v1/tenant/auth/me')->assertOk()->json('data');

        $this->assertContains('customers.delete', $theirs['capabilities']);
        $this->assertContains('appointments.doctors', $theirs['capabilities']);
        $this->assertNotContains('customers.delete', $mine['capabilities']);
    }

    /**
     * Signing in answers with the same session `me` does.
     *
     * They described one session in two places and drifted: login returned the
     * user and the organization and nothing else, so a capability-driven
     * sidebar was built from an empty list and showed only what needs no
     * capability — until a hard refresh ran `me` and filled it in.
     */
    public function test_login_answers_with_the_same_session_as_me(): void
    {
        [$organization] = $this->network();

        $this->grantModule($organization, 'appointments');
        $this->setStaffCapabilities($organization, ['customers.view', 'appointments.view']);

        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $login = $this->postJson('/api/v1/tenant/auth/login', [
            'subdomain' => $organization->subdomain,
            'email' => self::STAFF_EMAIL,
            'password' => self::PASSWORD,
        ])->assertOk()->json('data');

        $me = $this->getJson('/api/v1/tenant/auth/me')->assertOk()->json('data');

        // Not merely "present" — identical, which is the only version of this
        // assertion that would have caught the original bug.
        $this->assertSame($me['capabilities'], $login['capabilities']);
        $this->assertSame($me['modules'], $login['modules']);

        $this->assertContains('customers.view', $login['capabilities']);
        $this->assertNotContains('customers.delete', $login['capabilities']);
    }

    public function test_me_reports_the_modules_running_at_the_persons_branch(): void
    {
        [$organization, $here] = $this->network();

        $this->grantModule($organization, 'appointments');

        $this->signInAsStaff($organization);
        $this->assertContains(
            'appointments',
            $this->getJson('/api/v1/tenant/auth/me')->assertOk()->json('data.modules'),
        );

        $this->disableModuleAtBranch($organization, $here, 'appointments');

        // The sidebar reads this, so a section it hides is one the API would
        // have refused anyway — they answer from the same service.
        $this->signInAsStaff($organization);
        $this->assertNotContains(
            'appointments',
            $this->getJson('/api/v1/tenant/auth/me')->assertOk()->json('data.modules'),
        );
    }

    public function test_the_grantable_pool_offers_only_what_was_sold(): void
    {
        [$organization] = $this->network();

        $this->signInAsOwner($organization);

        $keys = fn () => collect(
            $this->getJson('/api/v1/tenant/roles/grantable')->assertOk()->json('data.modules')
        )->pluck('key')->all();

        $this->assertSame(['branches', 'people', 'customers', 'settings'], $keys());

        $this->grantModule($organization, 'appointments');

        $this->assertSame(
            ['branches', 'people', 'customers', 'settings', 'appointments'],
            $keys(),
        );
    }

    /** Retired modules must not be assignable, and their keys must not validate. */
    public function test_a_retired_module_is_gone_from_the_catalogue(): void
    {
        [$organization] = $this->network();

        foreach (['inventory', 'sales', 'wholesale'] as $key) {
            $this->assertDatabaseMissing('modules', ['key' => $key]);
        }

        $this->signInAsOwner($organization);

        $this->postJson('/api/v1/tenant/roles', [
            'name' => 'Storekeeper',
            'capabilities' => ['inventory.manage'],
        ])->assertStatus(422)->assertJsonValidationErrors('capabilities.0');
    }
}
