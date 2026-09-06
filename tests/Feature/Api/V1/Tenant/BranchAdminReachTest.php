<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Module;
use App\Models\Platform\Organization;
use App\Models\Tenant\Location;
use App\Models\Tenant\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

/**
 * What a branch admin created from the branch form can actually reach.
 *
 * The account is provisioned in one write — a branch-owned role, a user, and a
 * primary membership — and every capability it holds sits on the MEMBERSHIP
 * rather than on `users.role_id`. That is the path least exercised by the rest
 * of the suite, and the one where a permission can be granted on paper and
 * refused in practice.
 */
class BranchAdminReachTest extends TenantTestCase
{
    use RefreshDatabase;

    /** An organization with a branch, and an admin provisioned for it. */
    private function branchAdmin(): array
    {
        $organization = $this->provisionOrganization();

        foreach (['appointments', 'prescriptions'] as $key) {
            $organization->moduleEntitlements()->create([
                'module_id' => Module::where('key', $key)->value('id'),
                'is_enabled' => true,
            ]);
        }

        $this->signInAsOwner($organization);

        $this->postJson('/api/v1/tenant/locations', [
            'name' => 'Gurgaon',
            'code' => 'GGN',
            'type' => Location::CLINIC,
            'is_active' => true,
            'admin' => [
                'name' => 'Priya Sharma',
                'email' => 'priya@branch.test',
                'password' => 'branch-secret-1',
            ],
        ])->assertCreated();

        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/tenant/auth/login', [
            'subdomain' => $organization->subdomain,
            'email' => 'priya@branch.test',
            'password' => 'branch-secret-1',
        ])->assertOk();

        return [$organization];
    }

    /**
     * The capabilities the login reports are the ones it was granted.
     *
     * Read first, because everything below depends on it: a session that comes
     * back holding nothing would make every refusal beneath look like a
     * separate bug.
     */
    public function test_a_branch_admin_session_reports_its_capabilities(): void
    {
        $this->branchAdmin();

        $held = $this->getJson('/api/v1/tenant/auth/me')->assertOk()->json('data.capabilities');

        $this->assertContains('appointments.doctors', $held);
        $this->assertContains('appointments.schedule', $held);
        $this->assertContains('people.roles', $held);
        $this->assertContains('customers.create', $held);
    }

    /** Adding a doctor — the thing a branch admin was reported unable to do. */
    public function test_a_branch_admin_can_add_a_doctor(): void
    {
        $this->branchAdmin();

        $this->postJson('/api/v1/tenant/doctors', [
            'name' => 'Dr. Anjali Sharma',
            'specialisation' => 'General Medicine',
            'is_active' => true,
        ])->assertCreated();
    }

    /** And register a patient, and take a walk-in at their own branch. */
    public function test_a_branch_admin_can_register_and_book(): void
    {
        [$organization] = $this->branchAdmin();

        $patient = $this->postJson('/api/v1/tenant/customers', [
            'name' => 'Rahul Sharma',
            'phone' => '9876500011',
            'is_active' => true,
        ])->assertCreated()->json('data');

        $doctor = $this->postJson('/api/v1/tenant/doctors', [
            'name' => 'Dr. Vikram Rao',
            'is_active' => true,
        ])->assertCreated()->json('data');

        $branchId = $this->onTenant(
            $organization,
            fn () => Location::on('organization')->where('code', 'GGN')->value('id'),
        );

        // A sitting first — a walk-in with nobody sitting is correctly refused,
        // and would otherwise read as a permission failure.
        $this->putJson("/api/v1/tenant/doctors/{$doctor['id']}/schedules", [
            'schedules' => [[
                'location_id' => $branchId,
                'weekday' => 0,
                'starts_at' => '10:00',
                'ends_at' => '13:00',
                'slot_minutes' => 15,
                'is_active' => true,
            ]],
        ])->assertOk();

        $this->postJson('/api/v1/tenant/appointments', [
            'customer_id' => $patient['id'],
            'doctor_id' => $doctor['id'],
            'location_id' => $branchId,
            'appointment_date' => '2026-09-07',
            'type' => 'walk_in',
        ])->assertCreated();
    }

    /**
     * What they may NOT do, which is the other half of the grant.
     *
     * Branches are the owner's: a branch admin who could add one could give
     * themselves a second, and switching modules on at a branch could reopen a
     * door the owner closed.
     */
    public function test_a_branch_admin_cannot_add_branches(): void
    {
        $this->branchAdmin();

        $this->postJson('/api/v1/tenant/locations', [
            'name' => 'Somewhere else',
            'code' => 'ELSE',
            'type' => Location::CLINIC,
            'is_active' => true,
        ])->assertStatus(403);
    }
}
