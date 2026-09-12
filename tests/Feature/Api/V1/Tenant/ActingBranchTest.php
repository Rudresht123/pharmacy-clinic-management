<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Http\Middleware\ResolveActingBranch;
use App\Models\Platform\Organization;
use App\Models\Tenant\BranchMembership;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Location;
use App\Models\Tenant\Role;
use App\Models\Tenant\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

/**
 * Which branch a request is happening in.
 *
 * Somebody who works at two branches has different permissions at each, so the
 * client has to say which one — and that is exactly why saying it cannot be
 * enough. Every case here is about the gap between what the client asked for
 * and what the server allows.
 */
class ActingBranchTest extends TenantTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');
        config(['hms.dashboard_demo' => false]);
    }

    /**
     * The staff member is a receptionist at Lucknow and a manager at Delhi,
     * and a third branch exists that they have nothing to do with.
     *
     * @return array{0: Organization, 1: int, 2: int, 3: int}
     */
    private function network(): array
    {
        $organization = $this->provisionOrganization('B');

        [$lucknow, $delhi, $mumbai] = $this->onTenant($organization, function () {
            $branches = [];

            foreach ([['Lucknow', 'B-LKO'], ['Delhi', 'B-DEL'], ['Mumbai', 'B-BOM']] as [$name, $code]) {
                $branches[] = Location::on('organization')->create([
                    'name' => $name, 'code' => $code,
                    'type' => Location::CLINIC, 'is_active' => true,
                ])->id;
            }

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

            BranchMembership::on('organization')->create([
                'user_id' => $user->id, 'location_id' => $branches[0],
                'role_id' => $reception->id, 'is_primary' => true,
            ]);

            BranchMembership::on('organization')->create([
                'user_id' => $user->id, 'location_id' => $branches[1],
                'role_id' => $manager->id,
            ]);

            return $branches;
        });

        return [$organization, $lucknow, $delhi, $mumbai];
    }

    /** @return array<string, string> */
    private function at(int $branchId): array
    {
        return [ResolveActingBranch::HEADER => (string) $branchId];
    }

    /*
    |--------------------------------------------------------------------------
    | The refusal that is the whole point
    |--------------------------------------------------------------------------
    */

    /**
     * A branch they do not work at is refused, not quietly ignored.
     *
     * Falling back to their own branch would be worse than an error: the reply
     * would look correct while describing somewhere else entirely.
     */
    public function test_a_branch_they_do_not_work_at_is_refused(): void
    {
        [$organization, , , $mumbai] = $this->network();

        $this->signInAsStaff($organization);

        $this->getJson('/api/v1/tenant/customers', $this->at($mumbai))
            ->assertStatus(403)
            ->assertJsonPath('message', 'You do not work at that branch.');
    }

    /** A branch that does not exist at all is refused the same way. */
    public function test_an_unknown_branch_is_refused(): void
    {
        [$organization] = $this->network();

        $this->signInAsStaff($organization);

        $this->getJson('/api/v1/tenant/customers', $this->at(999999))->assertStatus(403);
    }

    /** Anything that is not a branch id is refused before it reaches a query. */
    public function test_rubbish_is_refused(): void
    {
        [$organization] = $this->network();

        $this->signInAsStaff($organization);

        $this->getJson('/api/v1/tenant/customers', [ResolveActingBranch::HEADER => '3 OR 1=1'])
            ->assertStatus(400);
    }

    /*
    |--------------------------------------------------------------------------
    | What the switcher is for
    |--------------------------------------------------------------------------
    */

    /**
     * The same person, the same request, a different answer at each branch.
     *
     * The reason membership replaced `users.location_id`: they may remove a
     * patient as Delhi's manager and not as Lucknow's receptionist, and the
     * header is how the client says which hat they are wearing.
     */
    public function test_the_answer_follows_the_branch_they_are_working_in(): void
    {
        [$organization, $lucknow, $delhi] = $this->network();

        $customerId = $this->onTenant($organization, fn () => Customer::on('organization')->create([
            'name' => 'Asha Rane', 'phone' => '9876500061', 'is_active' => true,
        ])->id);

        $this->signInAsStaff($organization);

        // As Lucknow's receptionist: may look, may not remove.
        $this->getJson('/api/v1/tenant/customers', $this->at($lucknow))->assertOk();
        $this->deleteJson("/api/v1/tenant/customers/{$customerId}", [], $this->at($lucknow))
            ->assertStatus(403);

        // As Delhi's manager: the very same request goes through.
        $this->deleteJson("/api/v1/tenant/customers/{$customerId}", [], $this->at($delhi))
            ->assertOk();
    }

    /** `me` describes the branch they are working in, not always their first. */
    public function test_me_answers_for_the_branch_being_worked_in(): void
    {
        [$organization, $lucknow, $delhi] = $this->network();

        $this->signInAsStaff($organization);

        $atLucknow = $this->getJson('/api/v1/tenant/auth/me', $this->at($lucknow))
            ->assertOk()->json('data.capabilities');

        $atDelhi = $this->getJson('/api/v1/tenant/auth/me', $this->at($delhi))
            ->assertOk()->json('data.capabilities');

        $this->assertNotContains('customers.delete', $atLucknow);
        $this->assertContains('customers.delete', $atDelhi);
    }

    /**
     * No header behaves exactly as before.
     *
     * A client that has never heard of branch switching keeps working, and
     * lands on the person's primary branch.
     */
    public function test_without_a_header_they_land_on_their_primary_branch(): void
    {
        [$organization] = $this->network();

        $customerId = $this->onTenant($organization, fn () => Customer::on('organization')->create([
            'name' => 'Bina Rao', 'phone' => '9876500062', 'is_active' => true,
        ])->id);

        $this->signInAsStaff($organization);

        // Lucknow is primary, and Lucknow's role cannot remove.
        $this->getJson('/api/v1/tenant/customers')->assertOk();
        $this->deleteJson("/api/v1/tenant/customers/{$customerId}")->assertStatus(403);
    }

    /** The owner may work at any branch, having no membership anywhere. */
    public function test_the_owner_may_act_at_any_branch(): void
    {
        [$organization, , , $mumbai] = $this->network();

        $this->signInAsOwner($organization);

        $this->getJson('/api/v1/tenant/customers', $this->at($mumbai))->assertOk();
    }

    /**
     * A module switched off at the branch they moved to is refused there.
     *
     * Level two follows the switcher, which is what makes the switcher a real
     * change of place rather than a label.
     */
    public function test_level_two_follows_the_branch_they_moved_to(): void
    {
        [$organization, $lucknow, $delhi] = $this->network();

        $this->grantModule($organization, 'appointments');

        $this->onTenant($organization, function () {
            foreach (Role::on('organization')->get() as $role) {
                $role->load('capabilities')->syncCapabilities(['appointments.view']);
            }
        });

        $this->disableModuleAtBranch($organization, $delhi, 'appointments');

        $this->signInAsStaff($organization);

        $this->getJson('/api/v1/tenant/doctors', $this->at($lucknow))->assertOk();
        $this->getJson('/api/v1/tenant/doctors', $this->at($delhi))->assertStatus(403);
    }
}
