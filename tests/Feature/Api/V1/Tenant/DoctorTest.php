<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Module;
use App\Models\Platform\Organization;
use App\Models\Tenant\ActivityLog;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\EntityFieldSetting;
use App\Models\Tenant\Location;
use App\Models\Tenant\User as TenantUser;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

/**
 * OPD Phase 1 — doctors and their availability.
 *
 * What is asserted is the domain, not the plumbing: a doctor is an identity
 * that need not have a login and must not have two, their branches come only
 * from their schedules, and a doctor cannot be in two places at once.
 */
class DoctorTest extends TenantTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The catalogue the entitlement points at.
        $this->artisan('modules:sync');
    }

    /**
     * Every doctor route is behind the module, so most tests need it on.
     */
    private function enableOpd(Organization $organization): void
    {
        $organization->moduleEntitlements()->create([
            'module_id' => Module::where('key', 'appointments')->value('id'),
            'is_enabled' => true,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Dr. Anjali Sharma',
            'specialisation' => 'General Physician',
            'is_active' => true,
        ], $overrides);
    }

    private function branch(Organization $organization, string $name, string $code): int
    {
        return $this->onTenant($organization, fn () => Location::on('organization')->create([
            'name' => $name,
            'code' => $code,
            'type' => Location::CLINIC,
            'is_active' => true,
        ])->id);
    }

    /*
    |--------------------------------------------------------------------------
    | Entitlement
    |--------------------------------------------------------------------------
    */

    /**
     * The module gates the whole domain, not just the menu.
     *
     * Hiding the sidebar entry is not a control: an organization that has
     * not been sold OPD must be refused by the API too.
     */
    public function test_an_organization_without_the_module_cannot_reach_doctors(): void
    {
        $organization = $this->provisionOrganization();
        $this->signInAsOwner($organization);

        $this->getJson('/api/v1/tenant/doctors')->assertForbidden();
        $this->postJson('/api/v1/tenant/doctors', $this->payload())->assertForbidden();
    }

    public function test_assigning_the_module_opens_the_domain(): void
    {
        $organization = $this->provisionOrganization();
        $this->enableOpd($organization);
        $this->signInAsOwner($organization);

        $this->getJson('/api/v1/tenant/doctors')->assertOk();
        $this->postJson('/api/v1/tenant/doctors', $this->payload())->assertCreated();
    }

    /** The menu and the API answer from the same source. */
    public function test_me_reports_the_organizations_modules(): void
    {
        $organization = $this->provisionOrganization();
        $this->signInAsOwner($organization);

        $before = $this->getJson('/api/v1/tenant/auth/me')->assertOk()->json('data');
        $this->assertNotContains('appointments', $before['modules']);

        $this->enableOpd($organization);

        $after = $this->getJson('/api/v1/tenant/auth/me')->assertOk()->json('data');
        $this->assertContains('appointments', $after['modules']);
        $this->assertContains('appointments.book', $after['capabilities']);
    }

    /*
    |--------------------------------------------------------------------------
    | Identity
    |--------------------------------------------------------------------------
    */

    /** A removed doctor frees their code; two live ones cannot share it. */
    public function test_a_code_is_unique_among_live_doctors_only(): void
    {
        $organization = $this->provisionOrganization();
        $this->enableOpd($organization);
        $this->signInAsOwner($organization);

        $first = $this->postJson('/api/v1/tenant/doctors', $this->payload(['code' => 'DR-01']))
            ->assertCreated()
            ->json('data');

        $this->postJson('/api/v1/tenant/doctors', $this->payload([
            'name' => 'Dr. Ravi Menon',
            'code' => 'DR-01',
        ]))->assertStatus(422)->assertJsonValidationErrors('code');

        $this->deleteJson("/api/v1/tenant/doctors/{$first['id']}")->assertOk();

        $this->postJson('/api/v1/tenant/doctors', $this->payload([
            'name' => 'Dr. Ravi Menon',
            'code' => 'DR-01',
        ]))->assertCreated();
    }

    /**
     * A council registration number identifies a person.
     *
     * Two live doctors sharing one is a duplicate record, which would later
     * split one doctor's prescriptions across two identities.
     */
    public function test_two_live_doctors_cannot_share_a_registration_number(): void
    {
        $organization = $this->provisionOrganization();
        $this->enableOpd($organization);
        $this->signInAsOwner($organization);

        $this->postJson('/api/v1/tenant/doctors', $this->payload(['registration_no' => 'MCI-4471']))
            ->assertCreated();

        $this->postJson('/api/v1/tenant/doctors', $this->payload([
            'name' => 'Dr. Ravi Menon',
            'registration_no' => 'MCI-4471',
        ]))->assertStatus(422)->assertJsonValidationErrors('registration_no');
    }

    /** Editing a doctor without touching their code must not clash with themselves. */
    public function test_a_doctor_can_be_saved_without_changing_their_own_code(): void
    {
        $organization = $this->provisionOrganization();
        $this->enableOpd($organization);
        $this->signInAsOwner($organization);

        $doctor = $this->postJson('/api/v1/tenant/doctors', $this->payload(['code' => 'DR-09']))
            ->assertCreated()
            ->json('data');

        $this->putJson("/api/v1/tenant/doctors/{$doctor['id']}", $this->payload([
            'code' => 'DR-09',
            'specialisation' => 'Cardiology',
        ]))
            ->assertOk()
            ->assertJsonPath('data.specialisation', 'Cardiology');
    }

    /*
    |--------------------------------------------------------------------------
    | Doctor ↔ login
    |--------------------------------------------------------------------------
    */

    /**
     * A doctor need not sign in — that is why doctors are their own table.
     *
     * And may never have two accounts: Phase 4 asks who wrote a prescription,
     * and two identities for one person leave that unanswerable.
     */
    public function test_a_doctor_may_have_no_login_but_never_two(): void
    {
        $organization = $this->provisionOrganization();
        $this->enableOpd($organization);
        $this->signInAsOwner($organization);

        $created = $this->postJson('/api/v1/tenant/doctors', $this->payload())
            ->assertCreated()
            ->json('data');

        $this->onTenant($organization, function () use ($created) {
            $doctor = Doctor::on('organization')->findOrFail($created['id']);

            // Perfectly valid: a visiting consultant who never signs in.
            $this->assertNull($doctor->user);

            TenantUser::on('organization')->create([
                'name' => $doctor->name,
                'email' => 'anjali@example.com',
                'password' => bcrypt('secret-value'),
                'role' => TenantUser::STAFF,
                'is_active' => true,
                'userable_type' => Doctor::class,
                'userable_id' => $doctor->id,
            ]);

            $this->assertNotNull($doctor->fresh()->user);

            $this->expectException(QueryException::class);

            TenantUser::on('organization')->create([
                'name' => $doctor->name,
                'email' => 'anjali.second@example.com',
                'password' => bcrypt('secret-value'),
                'role' => TenantUser::STAFF,
                'is_active' => true,
                'userable_type' => Doctor::class,
                'userable_id' => $doctor->id,
            ]);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Availability
    |--------------------------------------------------------------------------
    */

    /**
     * Two sittings on one day at two branches are the normal case.
     *
     * Morning at one clinic, evening at another. Nothing about this is a
     * duplicate, which is why there is no unique constraint on
     * (doctor, location, weekday).
     */
    public function test_a_doctor_may_sit_twice_on_one_day_at_different_branches(): void
    {
        $organization = $this->provisionOrganization();
        $this->enableOpd($organization);
        $this->signInAsOwner($organization);

        $gurgaon = $this->branch($organization, 'Gurgaon', 'GGN');
        $delhi = $this->branch($organization, 'Delhi', 'DEL');

        $doctor = $this->postJson('/api/v1/tenant/doctors', $this->payload())
            ->assertCreated()
            ->json('data');

        $this->putJson("/api/v1/tenant/doctors/{$doctor['id']}/schedules", [
            'schedules' => [
                [
                    'location_id' => $gurgaon,
                    'name' => 'Morning OPD',
                    'weekday' => 1,
                    'starts_at' => '10:00',
                    'ends_at' => '13:00',
                    'slot_minutes' => 15,
                ],
                [
                    'location_id' => $delhi,
                    'name' => 'Evening OPD',
                    'weekday' => 1,
                    'starts_at' => '17:00',
                    'ends_at' => '20:00',
                    'slot_minutes' => 20,
                ],
            ],
        ])->assertOk()->assertJsonCount(2, 'data');
    }

    /**
     * A doctor cannot be in two places at once.
     *
     * Overlap is checked across the week regardless of branch — a sitting in
     * Gurgaon from 10:00 and one in Delhi from 10:30 is not two valid rows,
     * it is a person who cannot exist. Unchecked, slot generation would
     * produce two overlapping sets and double-book a real doctor.
     */
    public function test_overlapping_sittings_are_rejected_even_at_different_branches(): void
    {
        $organization = $this->provisionOrganization();
        $this->enableOpd($organization);
        $this->signInAsOwner($organization);

        $gurgaon = $this->branch($organization, 'Gurgaon', 'GGN');
        $delhi = $this->branch($organization, 'Delhi', 'DEL');

        $doctor = $this->postJson('/api/v1/tenant/doctors', $this->payload())
            ->assertCreated()
            ->json('data');

        $this->putJson("/api/v1/tenant/doctors/{$doctor['id']}/schedules", [
            'schedules' => [
                [
                    'location_id' => $gurgaon,
                    'weekday' => 1,
                    'starts_at' => '10:00',
                    'ends_at' => '13:00',
                    'slot_minutes' => 15,
                ],
                [
                    'location_id' => $delhi,
                    'weekday' => 1,
                    'starts_at' => '12:30',
                    'ends_at' => '15:00',
                    'slot_minutes' => 15,
                ],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('schedules.1.starts_at');
    }

    /** Back to back is not overlapping: one ends exactly where the next begins. */
    public function test_back_to_back_sittings_are_accepted(): void
    {
        $organization = $this->provisionOrganization();
        $this->enableOpd($organization);
        $this->signInAsOwner($organization);

        $branch = $this->branch($organization, 'Gurgaon', 'GGN');

        $doctor = $this->postJson('/api/v1/tenant/doctors', $this->payload())
            ->assertCreated()
            ->json('data');

        $this->putJson("/api/v1/tenant/doctors/{$doctor['id']}/schedules", [
            'schedules' => [
                ['location_id' => $branch, 'weekday' => 2, 'starts_at' => '10:00', 'ends_at' => '13:00', 'slot_minutes' => 15],
                ['location_id' => $branch, 'weekday' => 2, 'starts_at' => '13:00', 'ends_at' => '16:00', 'slot_minutes' => 15],
            ],
        ])->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_a_sitting_must_end_after_it_starts(): void
    {
        $organization = $this->provisionOrganization();
        $this->enableOpd($organization);
        $this->signInAsOwner($organization);

        $branch = $this->branch($organization, 'Gurgaon', 'GGN');

        $doctor = $this->postJson('/api/v1/tenant/doctors', $this->payload())
            ->assertCreated()
            ->json('data');

        $this->putJson("/api/v1/tenant/doctors/{$doctor['id']}/schedules", [
            'schedules' => [
                ['location_id' => $branch, 'weekday' => 3, 'starts_at' => '17:00', 'ends_at' => '13:00', 'slot_minutes' => 15],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('schedules.0.ends_at');
    }

    /**
     * A doctor's branches come only from their schedules.
     *
     * There is no branch column on a doctor, and this is the only answer to
     * "does Dr. Sharma work at Delhi".
     */
    public function test_doctors_can_be_filtered_by_the_branch_they_sit_at(): void
    {
        $organization = $this->provisionOrganization();
        $this->enableOpd($organization);
        $this->signInAsOwner($organization);

        $gurgaon = $this->branch($organization, 'Gurgaon', 'GGN');
        $delhi = $this->branch($organization, 'Delhi', 'DEL');

        $sharma = $this->postJson('/api/v1/tenant/doctors', $this->payload())
            ->assertCreated()->json('data');

        $menon = $this->postJson('/api/v1/tenant/doctors', $this->payload(['name' => 'Dr. Ravi Menon']))
            ->assertCreated()->json('data');

        $this->putJson("/api/v1/tenant/doctors/{$sharma['id']}/schedules", [
            'schedules' => [
                ['location_id' => $gurgaon, 'weekday' => 0, 'starts_at' => '10:00', 'ends_at' => '13:00', 'slot_minutes' => 15],
            ],
        ])->assertOk();

        $this->putJson("/api/v1/tenant/doctors/{$menon['id']}/schedules", [
            'schedules' => [
                ['location_id' => $delhi, 'weekday' => 0, 'starts_at' => '10:00', 'ends_at' => '13:00', 'slot_minutes' => 15],
            ],
        ])->assertOk();

        $names = $this->getJson("/api/v1/tenant/doctors?location_id={$gurgaon}")
            ->assertOk()
            ->json('data.*.name');

        $this->assertSame(['Dr. Anjali Sharma'], $names);
    }

    /*
    |--------------------------------------------------------------------------
    | Permissions, configuration, history
    |--------------------------------------------------------------------------
    */

    /**
     * Staff may look; only the owner may change.
     *
     * Asserted against a real id — a made-up one would 404 through
     * SubstituteBindings before the owner check ever ran, and prove nothing.
     */
    public function test_staff_may_read_doctors_but_not_write_them(): void
    {
        $organization = $this->provisionOrganization();
        $this->enableOpd($organization);

        $this->signInAsOwner($organization);
        $doctor = $this->postJson('/api/v1/tenant/doctors', $this->payload())
            ->assertCreated()->json('data');

        $this->signInAsStaff($organization);

        $this->getJson('/api/v1/tenant/doctors')->assertOk();
        $this->getJson("/api/v1/tenant/doctors/{$doctor['id']}")->assertOk();

        $this->postJson('/api/v1/tenant/doctors', $this->payload(['name' => 'Dr. X']))
            ->assertStatus(403);
        $this->putJson("/api/v1/tenant/doctors/{$doctor['id']}", $this->payload())
            ->assertStatus(403);
        $this->deleteJson("/api/v1/tenant/doctors/{$doctor['id']}")->assertStatus(403);
        $this->putJson("/api/v1/tenant/doctors/{$doctor['id']}/schedules", ['schedules' => []])
            ->assertStatus(403);
    }

    /** The same configuration layer Locations, People and Customers use. */
    public function test_the_doctor_form_is_configurable(): void
    {
        $organization = $this->provisionOrganization();
        $this->enableOpd($organization);
        $this->signInAsOwner($organization);

        $this->onTenant($organization, fn () => EntityFieldSetting::on('organization')->create([
            'entity' => EntityFieldSetting::ENTITY_DOCTOR,
            'field_key' => 'specialisation',
            'is_required' => true,
            'show_in_form' => true,
            'show_in_table' => true,
            'sort_order' => 2,
        ]));

        // Marked mandatory, so the API refuses it too — not just the browser.
        $this->postJson('/api/v1/tenant/doctors', ['name' => 'Dr. Anjali Sharma'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('specialisation');
    }

    /** Doctors join the configurable entities without a frontend change. */
    public function test_doctors_appear_among_the_configurable_entities(): void
    {
        $organization = $this->provisionOrganization();
        $this->enableOpd($organization);
        $this->signInAsOwner($organization);

        $entities = $this->getJson('/api/v1/tenant/settings/fields')
            ->assertOk()
            ->json('data.*.entity');

        $this->assertContains('doctor', $entities);
    }

    /** One trait on the model, and the change is on the record's timeline. */
    public function test_creating_a_doctor_is_recorded_in_the_history(): void
    {
        $organization = $this->provisionOrganization();
        $this->enableOpd($organization);
        $this->signInAsOwner($organization);

        $this->postJson('/api/v1/tenant/doctors', $this->payload())->assertCreated();

        $this->onTenant($organization, function () {
            $log = ActivityLog::on('organization')
                ->where('entity_type', 'Doctor')
                ->where('event', 'created')
                ->firstOrFail();

            $this->assertSame('Dr. Anjali Sharma', $log->entity_label);
            $this->assertSame('tenant', $log->actor_type);
        });
    }
}
