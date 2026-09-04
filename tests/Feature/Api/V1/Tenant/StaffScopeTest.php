<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\Location;
use App\Models\Tenant\Role;
use App\Models\Tenant\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

/**
 * Who a person may administer, once staff administration is delegated.
 *
 * `people.view` and `people.edit` say somebody may administer staff. They never
 * said WHICH staff, and the answer was every one of them — which made
 * delegating staff administration the same as handing over the network, and the
 * owner's account with it.
 *
 * Every case here fails without App\Services\Permissions\StaffScope.
 */
class StaffScopeTest extends TenantTestCase
{
    use RefreshDatabase;

    private const OTHER_PASSWORD = 'Str0ng!Pass';

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');
    }

    /**
     * Two branches, with the seeded staff member working at the first and a
     * second person working at the other.
     *
     * @return array{0: Organization, 1: int, 2: int, 3: int, 4: int} org, here, there, delegate id, other id
     */
    private function network(): array
    {
        $organization = $this->provisionOrganization('S');

        [$here, $there, $delegateId, $otherId] = $this->onTenant(
            $organization,
            function () {
                $here = Location::on('organization')->create([
                    'name' => 'Lucknow', 'code' => 'S-LKO',
                    'type' => Location::CLINIC, 'is_active' => true,
                ]);

                $there = Location::on('organization')->create([
                    'name' => 'Delhi', 'code' => 'S-DEL',
                    'type' => Location::CLINIC, 'is_active' => true,
                ]);

                $roleId = Role::on('organization')
                    ->where('slug', Role::SEEDED_STAFF)
                    ->value('id');

                // The delegate: the seeded staff member, put at Lucknow.
                $delegate = User::on('organization')->where('email', self::STAFF_EMAIL)->first();
                $delegate->forceFill(['location_id' => $here->id])->save();

                // Somebody at the other branch for them not to reach.
                $other = User::on('organization')->create([
                    'name' => 'Neha Delhi',
                    'email' => 'neha@example.com',
                    'password' => bcrypt(self::OTHER_PASSWORD),
                    'is_active' => true,
                    'role' => User::STAFF,
                    'role_id' => $roleId,
                    'location_id' => $there->id,
                ]);

                return [$here->id, $there->id, $delegate->id, $other->id];
            }
        );

        return [$organization, $here, $there, $delegateId, $otherId];
    }

    /*
    |--------------------------------------------------------------------------
    | The one that was account takeover
    |--------------------------------------------------------------------------
    */

    /**
     * A delegate cannot touch the owner's record — above all, its password.
     *
     * Every other guard passes on this request: the role stays `owner` so
     * nothing is being promoted, `is_active` stays true so nobody is being
     * demoted, and the caller is not editing themselves. Before StaffScope the
     * password was hashed and written, and whoever had been given staff
     * administration owned the organization.
     */
    public function test_a_delegate_cannot_edit_the_owner(): void
    {
        [$organization] = $this->network();

        $ownerId = $this->onTenant($organization, fn () => User::on('organization')
            ->where('email', $organization->email)
            ->value('id'));

        $this->setStaffCapabilities($organization, ['people.view', 'people.edit']);
        $this->signInAsStaff($organization);

        $this->putJson("/api/v1/tenant/users/{$ownerId}", [
            'name' => 'Owner',
            'email' => $organization->email,
            'role' => User::OWNER,
            'is_active' => true,
            'password' => 'Taken0ver!',
            'password_confirmation' => 'Taken0ver!',
        ])->assertStatus(404);

        // The password was not written: the owner still signs in with theirs,
        // which is the assertion that actually proves nothing happened.
        $this->postJson('/api/v1/tenant/auth/logout');
        $this->signInAsOwner($organization);
    }

    /** Nor may they remove the owner, or even confirm the id exists. */
    public function test_a_delegate_cannot_see_or_remove_the_owner(): void
    {
        [$organization] = $this->network();

        $ownerId = $this->onTenant($organization, fn () => User::on('organization')
            ->where('email', $organization->email)
            ->value('id'));

        $this->setStaffCapabilities($organization, ['people.view', 'people.delete']);
        $this->signInAsStaff($organization);

        $this->getJson("/api/v1/tenant/users/{$ownerId}")->assertStatus(404);
        $this->deleteJson("/api/v1/tenant/users/{$ownerId}")->assertStatus(404);
    }

    /*
    |--------------------------------------------------------------------------
    | The branch is the default boundary
    |--------------------------------------------------------------------------
    */

    public function test_a_delegate_administers_only_their_own_branch(): void
    {
        [$organization, , , $delegateId, $otherId] = $this->network();

        $this->setStaffCapabilities($organization, ['people.view', 'people.edit']);
        $this->signInAsStaff($organization);

        $listed = collect(
            $this->getJson('/api/v1/tenant/users')->assertOk()->json('data')
        )->pluck('id');

        // Themselves, and nobody from the other branch — and not the owner,
        // who has no branch at all.
        $this->assertTrue($listed->contains($delegateId));
        $this->assertFalse($listed->contains($otherId));

        // 404 rather than 403: a record they cannot reach must be
        // indistinguishable from one that is not there, or the id is confirmed.
        $this->getJson("/api/v1/tenant/users/{$otherId}")->assertStatus(404);

        $this->putJson("/api/v1/tenant/users/{$otherId}", [
            'name' => 'Renamed',
            'email' => 'neha@example.com',
            'role' => User::STAFF,
            'role_id' => $this->staffRoleId($organization),
            'is_active' => true,
        ])->assertStatus(404);
    }

    /** Somebody at the same branch is theirs to administer. */
    public function test_a_delegate_administers_their_own_branchs_people(): void
    {
        [$organization, $here] = $this->network();

        $sameBranchId = $this->onTenant($organization, fn () => User::on('organization')->create([
            'name' => 'Amit Lucknow',
            'email' => 'amit@example.com',
            'password' => bcrypt(self::OTHER_PASSWORD),
            'is_active' => true,
            'role' => User::STAFF,
            'role_id' => Role::on('organization')->where('slug', Role::SEEDED_STAFF)->value('id'),
            'location_id' => $here,
        ])->id);

        $this->setStaffCapabilities($organization, ['people.view', 'people.edit']);
        $this->signInAsStaff($organization);

        $this->getJson("/api/v1/tenant/users/{$sameBranchId}")->assertOk();

        $this->putJson("/api/v1/tenant/users/{$sameBranchId}", [
            'name' => 'Amit Renamed',
            'email' => 'amit@example.com',
            'role' => User::STAFF,
            'role_id' => $this->staffRoleId($organization),
            'is_active' => true,
            'location_id' => $here,
        ])->assertOk()->assertJsonPath('data.name', 'Amit Renamed');
    }

    /**
     * The capability that lifts the branch boundary.
     *
     * Held rather than inferred from having no branch: administering the whole
     * network is something an owner grants on purpose.
     */
    public function test_across_branches_lifts_the_branch_boundary(): void
    {
        [$organization, , , , $otherId] = $this->network();

        $this->setStaffCapabilities($organization, [
            'people.view', 'people.edit', 'people.across_branches',
        ]);
        $this->signInAsStaff($organization);

        $this->getJson("/api/v1/tenant/users/{$otherId}")->assertOk();

        // Still not the owner, whatever else has been granted.
        $ownerId = $this->onTenant($organization, fn () => User::on('organization')
            ->where('email', $organization->email)
            ->value('id'));

        $this->getJson("/api/v1/tenant/users/{$ownerId}")->assertStatus(404);
    }

    /** The owner sees the whole network without holding anything. */
    public function test_the_owner_administers_everybody(): void
    {
        [$organization, , , $delegateId, $otherId] = $this->network();

        $this->signInAsOwner($organization);

        $listed = collect(
            $this->getJson('/api/v1/tenant/users')->assertOk()->json('data')
        )->pluck('id');

        $this->assertTrue($listed->contains($delegateId));
        $this->assertTrue($listed->contains($otherId));

        $this->getJson("/api/v1/tenant/users/{$otherId}")->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | Escalation
    |--------------------------------------------------------------------------
    */

    /**
     * Nobody may grant a role holding more than they hold.
     *
     * Without this, delegating `people.edit` delegates everything: put somebody
     * on the most powerful role, sign in as them, and the limit is gone. It
     * does not even need a second account — editing your own record does it.
     */
    public function test_a_delegate_cannot_grant_a_role_beyond_their_own(): void
    {
        [$organization, $here] = $this->network();

        $this->signInAsOwner($organization);

        $powerful = $this->postJson('/api/v1/tenant/roles', [
            'name' => 'Everything',
            'capabilities' => [
                'people.view', 'people.create', 'people.edit', 'people.delete',
                'customers.view', 'customers.delete', 'settings.manage',
            ],
        ])->assertCreated()->json('data');

        $modest = $this->postJson('/api/v1/tenant/roles', [
            'name' => 'Modest',
            'capabilities' => ['people.view', 'people.edit'],
        ])->assertCreated()->json('data');

        $this->setStaffCapabilities($organization, ['people.view', 'people.edit', 'people.create']);
        $this->signInAsStaff($organization);

        $payload = [
            'name' => 'Priya Sharma',
            'email' => 'priya-'.uniqid().'@example.com',
            'password' => self::OTHER_PASSWORD,
            'password_confirmation' => self::OTHER_PASSWORD,
            'role' => User::STAFF,
            'is_active' => true,
            'location_id' => $here,
        ];

        $this->postJson('/api/v1/tenant/users', $payload + ['role_id' => $powerful['id']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role_id');

        // A role within their own reach goes through, so the refusal above was
        // the escalation rule and not something else about the payload.
        $this->postJson('/api/v1/tenant/users', $payload + ['role_id' => $modest['id']])
            ->assertCreated();
    }

    /** The same rule on edit, which is the shorter path to the same place. */
    public function test_a_delegate_cannot_promote_themselves_by_editing(): void
    {
        [$organization, $here, , $delegateId] = $this->network();

        $this->signInAsOwner($organization);

        $powerful = $this->postJson('/api/v1/tenant/roles', [
            'name' => 'Everything',
            'capabilities' => ['people.view', 'people.edit', 'settings.manage'],
        ])->assertCreated()->json('data');

        $this->setStaffCapabilities($organization, ['people.view', 'people.edit']);
        $this->signInAsStaff($organization);

        $this->putJson("/api/v1/tenant/users/{$delegateId}", [
            'name' => 'Staff',
            'email' => self::STAFF_EMAIL,
            'role' => User::STAFF,
            'role_id' => $powerful['id'],
            'is_active' => true,
            'location_id' => $here,
        ])->assertStatus(422)->assertJsonValidationErrors('role_id');
    }

    /** The owner is exempt — they already hold the whole pool. */
    public function test_the_owner_may_grant_any_role(): void
    {
        [$organization, $here] = $this->network();

        $this->signInAsOwner($organization);

        $powerful = $this->postJson('/api/v1/tenant/roles', [
            'name' => 'Everything',
            'capabilities' => ['people.view', 'people.delete', 'settings.manage'],
        ])->assertCreated()->json('data');

        $this->postJson('/api/v1/tenant/users', [
            'name' => 'Priya Sharma',
            'email' => 'priya-'.uniqid().'@example.com',
            'password' => self::OTHER_PASSWORD,
            'password_confirmation' => self::OTHER_PASSWORD,
            'role' => User::STAFF,
            'role_id' => $powerful['id'],
            'is_active' => true,
            'location_id' => $here,
        ])->assertCreated();
    }
}
