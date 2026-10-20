<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\BranchMembership;
use App\Models\Tenant\Location;
use App\Models\Tenant\Role;
use App\Models\Tenant\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

/**
 * Organization-wide access and branch-specific access, kept apart.
 *
 * What is asserted: only an organization administrator gives or takes away
 * organization-wide access, while an edit that leaves it alone goes through;
 * the patient app's role is never a staff role; a role one branch wrote is
 * given at that branch alone; a branch role cannot be slipped an
 * organization-only permission by claiming another scope; nobody widens a role
 * they hold themselves; and the role list tells the screen who each role is
 * for and whether the person asking may hand it out.
 */
class RoleScopeTest extends TenantTestCase
{
    use RefreshDatabase;

    private const STRONG_PASSWORD = 'Str0ng!Pass';

    private Organization $organization;

    private int $lucknow;

    private int $delhi;

    private int $delegate;

    private int $colleague;

    private int $clerkRole;

    private int $patientRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');

        $this->organization = $this->provisionOrganization('RS');
        $this->grantModule($this->organization, 'appointments');

        $this->onTenant($this->organization, function () {
            $branch = fn (string $name, string $code) => Location::on('organization')->create([
                'name' => $name, 'code' => $code, 'type' => Location::CLINIC, 'is_active' => true,
            ])->id;

            $this->lucknow = $branch('Lucknow', 'RS-LKO');
            $this->delhi = $branch('Delhi', 'RS-DEL');

            $staffRole = Role::on('organization')->where('slug', Role::SEEDED_STAFF)->value('id');

            // The delegate works at Lucknow only, on a branch role.
            $delegate = User::on('organization')->where('email', self::STAFF_EMAIL)->firstOrFail();
            $delegate->forceFill(['role_id' => null])->save();
            BranchMembership::on('organization')->create([
                'user_id' => $delegate->id, 'location_id' => $this->lucknow, 'role_id' => $staffRole, 'is_primary' => true,
            ]);
            $this->delegate = $delegate->id;

            $clerk = Role::on('organization')->create([
                'name' => 'Network clerk', 'slug' => 'network-clerk', 'scope' => Role::SCOPE_ORGANIZATION,
            ]);
            $clerk->syncCapabilities(['customers.view']);
            $this->clerkRole = $clerk->id;

            // A colleague at Lucknow, already on the organization-wide role.
            $colleague = User::on('organization')->create([
                'name' => 'Asha Verma', 'email' => 'asha@example.com', 'password' => bcrypt(self::STRONG_PASSWORD),
                'is_active' => true, 'role' => User::STAFF, 'role_id' => $clerk->id,
            ]);
            BranchMembership::on('organization')->create([
                'user_id' => $colleague->id, 'location_id' => $this->lucknow, 'role_id' => $staffRole, 'is_primary' => true,
            ]);
            $this->colleague = $colleague->id;

            // The patient app's role, as PatientAccountProvisioner makes it.
            $patient = Role::on('organization')->create([
                'name' => 'Patient', 'slug' => 'patient', 'scope' => Role::SCOPE_ORGANIZATION,
            ]);
            $patient->syncCapabilities(['portal.appointments.view', 'portal.appointments.book']);
            $this->patientRole = $patient->id;
        });
    }

    /** @return array<string, mixed> */
    private function newPerson(array $extra = []): array
    {
        return [
            'name' => 'Priya Sharma',
            'email' => 'priya-'.uniqid().'@example.com',
            'password' => self::STRONG_PASSWORD,
            'password_confirmation' => self::STRONG_PASSWORD,
            'role' => User::STAFF,
            'is_active' => true,
            'location_id' => $this->lucknow,
            ...$extra,
        ];
    }

    public function test_a_branch_delegate_cannot_give_organization_wide_access(): void
    {
        // Holds everything on the role — only the reach is missing.
        $this->setStaffCapabilities($this->organization, ['people.view', 'people.create', 'people.edit', 'customers.view']);
        $this->signInAsStaff($this->organization);

        $this->postJson('/api/v1/tenant/users', $this->newPerson(['role_id' => $this->clerkRole]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('role_id');

        // Branch staff with no organization role: still theirs to add.
        $this->postJson('/api/v1/tenant/users', $this->newPerson())->assertCreated();

        // Organization HR may.
        $this->setStaffCapabilities($this->organization, [
            'people.view', 'people.create', 'people.edit', 'customers.view', 'people.across_branches',
        ]);
        $this->signInAsStaff($this->organization);

        $this->postJson('/api/v1/tenant/users', $this->newPerson(['role_id' => $this->clerkRole]))->assertCreated();
    }

    public function test_an_edit_that_leaves_organization_access_alone_goes_through(): void
    {
        $this->setStaffCapabilities($this->organization, ['people.view', 'people.edit', 'customers.view']);
        $this->signInAsStaff($this->organization);

        $edit = fn (?int $roleId) => $this->putJson("/api/v1/tenant/users/{$this->colleague}", [
            'name' => 'Asha Verma (Front desk)',
            'email' => 'asha@example.com',
            'role' => User::STAFF,
            'role_id' => $roleId,
            'is_active' => true,
            'location_id' => $this->lucknow,
        ]);

        $edit($this->clerkRole)->assertOk();

        // Taking it away is as much an organization decision as giving it.
        $edit(null)->assertStatus(422)->assertJsonValidationErrors('role_id');
    }

    public function test_the_patient_role_is_never_a_staff_role(): void
    {
        $this->signInAsOwner($this->organization);

        $this->postJson('/api/v1/tenant/users', $this->newPerson(['role_id' => $this->patientRole]))
            ->assertStatus(422)
            ->assertJsonPath('errors.role_id.0', '"Patient" is for patients using the app, and cannot be given to staff.');
    }

    public function test_the_role_list_says_who_each_role_is_for_and_who_may_give_it(): void
    {
        $this->signInAsOwner($this->organization);

        $roles = collect($this->getJson('/api/v1/tenant/roles')->assertOk()->json('data'))->keyBy('id');

        $this->assertSame('patient', $roles[$this->patientRole]['audience']);
        $this->assertFalse($roles[$this->patientRole]['assignable']);
        $this->assertSame('staff', $roles[$this->clerkRole]['audience']);
        $this->assertTrue($roles[$this->clerkRole]['assignable']);

        // A delegate is told which roles are beyond what they hold.
        $this->setStaffCapabilities($this->organization, ['people.view']);
        $this->signInAsStaff($this->organization);

        $mine = collect($this->getJson('/api/v1/tenant/roles')->assertOk()->json('data'))->keyBy('id');

        $this->assertFalse($mine[$this->clerkRole]['assignable']);
    }

    public function test_a_role_one_branch_wrote_is_given_at_that_branch_alone(): void
    {
        $delhiRole = $this->onTenant($this->organization, fn () => Role::on('organization')->create([
            'name' => 'Delhi desk', 'slug' => 'delhi-desk', 'scope' => Role::SCOPE_BRANCH, 'location_id' => $this->delhi,
        ])->id);

        $this->signInAsOwner($this->organization);

        $this->putJson("/api/v1/tenant/users/{$this->colleague}/branches", [
            'branches' => [['location_id' => $this->lucknow, 'role_id' => $delhiRole, 'is_primary' => true]],
        ])->assertStatus(422)->assertJsonValidationErrors('branches.0.role_id');

        $this->putJson("/api/v1/tenant/users/{$this->colleague}/branches", [
            'branches' => [['location_id' => $this->delhi, 'role_id' => $delhiRole, 'is_primary' => true]],
        ])->assertOk();
    }

    public function test_a_branch_role_cannot_be_slipped_an_organization_permission(): void
    {
        $this->setStaffCapabilities($this->organization, ['people.view', 'people.roles']);
        $this->signInAsStaff($this->organization);

        // Claiming the role is organization-wide does not make it so.
        $this->postJson('/api/v1/tenant/roles', [
            'name' => 'Sneaky',
            'scope' => Role::SCOPE_ORGANIZATION,
            'capabilities' => ['settings.manage'],
        ])->assertStatus(422)->assertJsonValidationErrors('capabilities.0');

        $desk = $this->postJson('/api/v1/tenant/roles', [
            'name' => 'Lucknow desk',
            'capabilities' => ['customers.view'],
        ])->assertCreated()->json('data');

        $this->assertSame(Role::SCOPE_BRANCH, $desk['scope']);

        // Nor on the way back in.
        $this->putJson("/api/v1/tenant/roles/{$desk['id']}", [
            'name' => 'Lucknow desk',
            'scope' => Role::SCOPE_ORGANIZATION,
            'capabilities' => ['customers.view', 'settings.manage'],
        ])->assertStatus(422)->assertJsonValidationErrors('capabilities.1');
    }

    public function test_nobody_widens_a_role_they_hold_themselves(): void
    {
        // A role Lucknow wrote, which the delegate is on.
        $mine = $this->onTenant($this->organization, function () {
            $role = Role::on('organization')->create([
                'name' => 'Lucknow lead', 'slug' => 'lucknow-lead', 'scope' => Role::SCOPE_BRANCH, 'location_id' => $this->lucknow,
            ]);
            $role->syncCapabilities(['people.view', 'people.roles']);

            BranchMembership::on('organization')
                ->where('user_id', $this->delegate)
                ->update(['role_id' => $role->id]);

            return $role->id;
        });

        $this->signInAsStaff($this->organization);

        $this->putJson("/api/v1/tenant/roles/{$mine}", [
            'name' => 'Lucknow lead',
            'capabilities' => ['people.view', 'people.roles', 'customers.delete'],
        ])->assertStatus(422)->assertJsonValidationErrors('capabilities');

        // Renaming it, or narrowing it, is still theirs to do.
        $this->putJson("/api/v1/tenant/roles/{$mine}", [
            'name' => 'Lucknow team lead',
            'capabilities' => ['people.view', 'people.roles'],
        ])->assertOk();

        // And writing a role for other people is unchanged: bounded by what
        // the branch runs, and checked again whenever it is handed out.
        $this->postJson('/api/v1/tenant/roles', [
            'name' => 'Records clerk',
            'capabilities' => ['customers.view', 'customers.delete'],
        ])->assertCreated();
    }

    public function test_templates_for_an_organization_role_keep_organization_permissions(): void
    {
        $this->signInAsOwner($this->organization);

        $capabilities = fn (?string $scope) => collect(
            $this->getJson('/api/v1/tenant/roles/templates'.($scope ? "?scope={$scope}" : ''))->assertOk()->json('data')
        )->firstWhere('key', 'head_office')['capabilities'] ?? [];

        $this->assertContains('people.across_branches', $capabilities(Role::SCOPE_ORGANIZATION));
        $this->assertNotContains('people.across_branches', $capabilities(null));
    }
}
