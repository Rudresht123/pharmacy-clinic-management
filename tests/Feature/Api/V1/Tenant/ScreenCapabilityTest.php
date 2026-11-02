<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Module;
use App\Models\Platform\Organization;
use App\Models\Tenant\BranchMembership;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Location;
use App\Models\Tenant\Role;
use App\Services\Tenant\DefaultRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

/**
 * One tick per screen: what a role is given is exactly what it can open.
 *
 * The walls between screens that used to share one capability — the
 * dashboard, a patient's record against the patient list — and the owner's
 * ticks on the doctor role surviving a doctor being saved.
 */
class ScreenCapabilityTest extends TenantTestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');

        $this->organization = $this->provisionOrganization('SCR');
    }

    private function customer(): int
    {
        return $this->onTenant($this->organization, fn () => Customer::on('organization')->create([
            'name' => 'Rahul Tiwari',
            'phone' => '9876500001',
            'is_active' => true,
        ])->id);
    }

    public function test_the_dashboard_is_its_own_tick(): void
    {
        $this->setStaffCapabilities($this->organization, ['customers.view']);
        $this->signInAsStaff($this->organization);

        $this->getJson('/api/v1/tenant/dashboard')->assertForbidden();

        $this->setStaffCapabilities($this->organization, ['customers.view', 'dashboard.view']);
        $this->signInAsStaff($this->organization);

        $this->getJson('/api/v1/tenant/dashboard')->assertOk();
    }

    public function test_finding_a_patient_is_not_reading_their_record(): void
    {
        $customerId = $this->customer();

        $this->setStaffCapabilities($this->organization, ['customers.view']);
        $this->signInAsStaff($this->organization);

        $this->getJson('/api/v1/tenant/customers')->assertOk();
        $this->getJson("/api/v1/tenant/customers/{$customerId}")->assertForbidden();
        $this->getJson("/api/v1/tenant/patient-records/{$customerId}")->assertForbidden();

        $this->setStaffCapabilities($this->organization, ['customers.view', 'customers.record']);
        $this->signInAsStaff($this->organization);

        $this->getJson("/api/v1/tenant/customers/{$customerId}")->assertOk();
        $this->getJson("/api/v1/tenant/patient-records/{$customerId}")->assertOk();
    }

    /** The session hands the apps exactly the ticks, so the menu shows exactly those. */
    public function test_a_role_given_only_chat_is_handed_only_chat(): void
    {
        $this->grantModule($this->organization, 'appointments');
        $this->setStaffCapabilities($this->organization, ['appointments.messages_desk']);
        $this->signInAsStaff($this->organization);

        $capabilities = $this->getJson('/api/v1/tenant/auth/me')->assertOk()->json('data.capabilities');

        $this->assertSame(['appointments.messages_desk'], $capabilities);
    }

    /*
    |--------------------------------------------------------------------------
    | Each branch sets its own roles
    |--------------------------------------------------------------------------
    */

    private function branch(string $name = 'MG Road'): int
    {
        return $this->onTenant($this->organization, fn () => Location::on('organization')->create([
            'name' => $name,
            'code' => strtoupper(substr($name, 0, 3)).random_int(10, 99),
            'type' => Location::CLINIC,
            'is_active' => true,
        ])->id);
    }

    private function sellAppointments(): void
    {
        $this->organization->moduleEntitlements()->create([
            'module_id' => Module::where('key', 'appointments')->value('id'),
            'is_enabled' => true,
        ]);
    }

    public function test_a_doctor_posted_to_a_branch_holds_that_branchs_own_doctor_role(): void
    {
        $this->sellAppointments();
        $branch = $this->branch();
        $this->signInAsOwner($this->organization);

        $doctor = [
            'name' => 'Dr. Anjali Sharma',
            'specialisation' => 'General Physician',
            'is_active' => true,
            'account' => ['email' => 'anjali@clinic.test', 'password' => 'doctor-secret-1'],
        ];

        $doctorId = $this->postJson('/api/v1/tenant/doctors', $doctor)->assertCreated()->json('data.id');
        $this->postDoctor($this->organization, $doctorId, $branch);

        $seat = fn () => $this->onTenant($this->organization, fn () => BranchMembership::on('organization')
            ->with('role.capabilities')
            ->whereHas('user', fn ($user) => $user->where('email', 'anjali@clinic.test'))
            ->where('location_id', $branch)
            ->firstOrFail());

        $role = $seat()->role;
        $this->assertSame($branch, (int) $role->location_id);
        $this->assertSame(Role::slugAt('doctor', $branch), $role->slug);
        $this->assertContains('appointments.my_day', $role->capabilityKeys());

        // The branch unticks a screen on its own Doctor role…
        $this->onTenant($this->organization, function () use ($role) {
            $role->syncCapabilities(array_values(array_diff($role->capabilityKeys(), ['appointments.my_schedule'])));
        });

        // …and saving the doctor does not put it back.
        $this->putJson("/api/v1/tenant/doctors/{$doctorId}", array_merge($doctor, [
            'account' => ['email' => 'anjali@clinic.test'],
        ]))->assertOk();

        $this->assertNotContains('appointments.my_schedule', $seat()->role->capabilityKeys());
    }

    public function test_the_owner_sets_only_the_patient_app_role(): void
    {
        $this->sellAppointments();
        $branch = $this->branch();
        $this->signInAsOwner($this->organization);

        // Starting roles exist at the branch, and the owner may read them…
        $this->onTenant($this->organization, fn () => app(DefaultRoleSeeder::class)->seed($this->organization));

        $roles = collect($this->getJson('/api/v1/tenant/roles')->assertOk()->json('data'));
        $receptionist = $roles->firstWhere('slug', Role::slugAt('receptionist', $branch));
        $patient = $roles->firstWhere('slug', 'patient');

        $this->assertNotNull($receptionist);
        $this->assertNotNull($patient);

        // …but not write a staff role…
        $this->postJson('/api/v1/tenant/roles', ['name' => 'Front desk', 'capabilities' => ['customers.view']])
            ->assertForbidden();
        $this->putJson("/api/v1/tenant/roles/{$receptionist['id']}", [
            'name' => 'Receptionist',
            'capabilities' => ['customers.view'],
        ])->assertForbidden();

        // …only the patient app's, which cannot be deleted.
        $this->putJson("/api/v1/tenant/roles/{$patient['id']}", [
            'name' => $patient['name'],
            'capabilities' => ['portal.appointments.view'],
        ])->assertOk();
        $this->deleteJson("/api/v1/tenant/roles/{$patient['id']}")->assertStatus(422);
    }

    public function test_a_branch_sees_and_gives_only_its_own_roles(): void
    {
        $this->sellAppointments();
        $here = $this->branch('MG Road');
        $there = $this->branch('Noida');

        $this->onTenant($this->organization, fn () => app(DefaultRoleSeeder::class)->seed($this->organization));

        $this->placeStaffAt($this->organization, $here);
        $this->setStaffCapabilities($this->organization, ['people.view', 'people.edit', 'people.assign_branch', 'people.roles', 'customers.view']);
        $this->signInAsStaff($this->organization);

        $slugs = collect($this->getJson('/api/v1/tenant/roles')->assertOk()->json('data'))->pluck('slug');

        $this->assertContains(Role::slugAt('receptionist', $here), $slugs);
        $this->assertNotContains(Role::slugAt('receptionist', $there), $slugs);
        $this->assertNotContains('patient', $slugs);

        // A role it writes is its own.
        $this->postJson('/api/v1/tenant/roles', ['name' => 'Front desk', 'capabilities' => ['customers.view']])
            ->assertCreated()
            ->assertJsonPath('data.location_id', $here);
    }
}
