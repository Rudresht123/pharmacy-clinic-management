<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Module;
use App\Models\Platform\Organization;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

/**
 * A doctor belongs to the organization and covers as many of its branches as
 * somebody says.
 *
 * The posting used to be implied by the timetable — a doctor "belonged to"
 * Gurgaon because they had a Monday sitting there — which cannot express the
 * ordinary case of taking somebody on before agreeing their hours, and left a
 * newly added doctor belonging nowhere at all.
 */
class DoctorPostingTest extends TenantTestCase
{
    use RefreshDatabase;

    /** An organization with three branches, and nobody posted anywhere yet. */
    private function network(): array
    {
        $organization = $this->provisionOrganization();

        $organization->moduleEntitlements()->create([
            'module_id' => Module::where('key', 'appointments')->value('id'),
            'is_enabled' => true,
        ]);

        $branches = $this->onTenant($organization, fn () => collect([
            ['name' => 'Delhi', 'code' => 'DEL'],
            ['name' => 'Gurgaon', 'code' => 'GGN'],
            ['name' => 'Noida', 'code' => 'NOI'],
        ])->mapWithKeys(fn (array $row) => [
            $row['code'] => Location::on('organization')->create([
                ...$row,
                'type' => Location::CLINIC,
                'is_active' => true,
            ])->id,
        ])->all());

        $this->signInAsOwner($organization);

        return [$organization, $branches];
    }

    /**
     * Creating a doctor needs no branch at all.
     *
     * Ownership is the organization's, and the organization is the database —
     * so there is no branch to name and nothing to choose before the record
     * can exist.
     */
    public function test_a_doctor_is_created_without_naming_a_branch(): void
    {
        $this->network();

        $doctor = $this->postJson('/api/v1/tenant/doctors', [
            'name' => 'Dr. Rahul Sharma',
            'specialisation' => 'Cardiology',
            'is_active' => true,
        ])->assertCreated()->json('data');

        $this->assertSame([], $doctor['location_ids']);
    }

    /** One doctor, several branches, one record. */
    public function test_a_doctor_covers_several_branches_as_one_record(): void
    {
        [$organization, $branches] = $this->network();

        $doctor = $this->postJson('/api/v1/tenant/doctors', [
            'name' => 'Dr. Rahul Sharma',
            'is_active' => true,
            'locations' => [$branches['DEL'], $branches['GGN']],
        ])->assertCreated()->json('data');

        $this->assertEqualsCanonicalizing(
            [$branches['DEL'], $branches['GGN']],
            $doctor['location_ids'],
        );

        // One row, not one per branch — which is the duplicate this whole
        // shape exists to prevent.
        $this->onTenant($organization, function () {
            $this->assertSame(1, Doctor::on('organization')->where('name', 'Dr. Rahul Sharma')->count());
        });

        // Both branches see the same doctor…
        foreach (['DEL', 'GGN'] as $code) {
            $this->getJson("/api/v1/tenant/doctors?location_id={$branches[$code]}")
                ->assertOk()
                ->assertJsonPath('data.0.id', $doctor['id']);
        }

        // …and the third does not.
        $this->getJson("/api/v1/tenant/doctors?location_id={$branches['NOI']}")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * A doctor with no posting yet is still visible where they were added.
     *
     * The branch-filtered list used to read the timetable, so somebody added a
     * minute ago — before anybody agreed their hours — was missing from the
     * list of the very branch that added them. The filter answered "not here"
     * when the truth was "not scheduled anywhere yet".
     */
    public function test_an_unposted_doctor_is_not_hidden_from_every_branch(): void
    {
        [, $branches] = $this->network();

        $doctor = $this->postJson('/api/v1/tenant/doctors', [
            'name' => 'Dr. Not Yet Placed',
            'is_active' => true,
        ])->assertCreated()->json('data');

        $this->getJson("/api/v1/tenant/doctors?location_id={$branches['GGN']}")
            ->assertOk()
            ->assertJsonPath('data.0.id', $doctor['id']);
    }

    /** Setting a sitting posts them there, so the two cannot disagree. */
    public function test_a_sitting_posts_the_doctor_to_that_branch(): void
    {
        [, $branches] = $this->network();

        $doctor = $this->postJson('/api/v1/tenant/doctors', [
            'name' => 'Dr. Meera Iyer',
            'is_active' => true,
            'locations' => [$branches['DEL']],
        ])->assertCreated()->json('data');

        $this->putJson("/api/v1/tenant/doctors/{$doctor['id']}/schedules", [
            'schedules' => [[
                'location_id' => $branches['NOI'],
                'weekday' => 0,
                'starts_at' => '10:00',
                'ends_at' => '13:00',
                'slot_minutes' => 15,
                'is_active' => true,
            ]],
        ])->assertOk();

        $this->assertContains(
            $branches['NOI'],
            $this->getJson("/api/v1/tenant/doctors/{$doctor['id']}")
                ->assertOk()
                ->json('data.location_ids'),
        );
    }

    /**
     * Unposting removes the mapping and that branch's hours — not the doctor.
     *
     * Leaving the sittings behind would have the timetable offering slots at a
     * branch the posting says the doctor does not cover, and availability
     * reads the timetable.
     */
    public function test_removing_a_branch_keeps_the_doctor_and_their_other_branches(): void
    {
        [$organization, $branches] = $this->network();

        $doctor = $this->postJson('/api/v1/tenant/doctors', [
            'name' => 'Dr. Vikram Rao',
            'is_active' => true,
            'locations' => [$branches['DEL'], $branches['GGN']],
        ])->assertCreated()->json('data');

        $this->putJson("/api/v1/tenant/doctors/{$doctor['id']}/schedules", [
            'schedules' => [
                [
                    'location_id' => $branches['DEL'],
                    'weekday' => 0,
                    'starts_at' => '09:00',
                    'ends_at' => '12:00',
                    'slot_minutes' => 15,
                    'is_active' => true,
                ],
                [
                    'location_id' => $branches['GGN'],
                    'weekday' => 2,
                    'starts_at' => '14:00',
                    'ends_at' => '18:00',
                    'slot_minutes' => 20,
                    'is_active' => true,
                ],
            ],
        ])->assertOk();

        // Gurgaon is dropped.
        $this->putJson("/api/v1/tenant/doctors/{$doctor['id']}", [
            'name' => 'Dr. Vikram Rao',
            'is_active' => true,
            'locations' => [$branches['DEL']],
        ])->assertOk();

        $after = $this->getJson("/api/v1/tenant/doctors/{$doctor['id']}")->assertOk()->json('data');

        $this->assertSame([$branches['DEL']], $after['location_ids']);

        $this->onTenant($organization, function () use ($doctor, $branches) {
            // The doctor survives.
            $this->assertNotNull(Doctor::on('organization')->find($doctor['id']));

            // Delhi keeps its hours; Gurgaon's are gone with the posting.
            $sittings = Doctor::on('organization')->find($doctor['id'])->schedules;

            $this->assertCount(1, $sittings);
            $this->assertSame($branches['DEL'], $sittings->first()->location_id);
        });
    }

    /**
     * History survives a doctor being unposted from a branch.
     *
     * An appointment carries its own date, time and branch; the schedule it
     * came from is provenance that nulls on delete. Somebody looking at last
     * month's queue must still find the doctor who saw them.
     */
    public function test_existing_appointments_survive_a_branch_being_dropped(): void
    {
        [$organization, $branches] = $this->network();

        $doctor = $this->postJson('/api/v1/tenant/doctors', [
            'name' => 'Dr. Anjali Sharma',
            'is_active' => true,
            'locations' => [$branches['DEL'], $branches['GGN']],
        ])->assertCreated()->json('data');

        $appointmentId = $this->onTenant($organization, function () use ($doctor, $branches) {
            $patient = Customer::on('organization')->create([
                'name' => 'Rahul Sharma',
                'phone' => '9876500099',
                'is_active' => true,
            ]);

            return Appointment::on('organization')->create([
                'customer_id' => $patient->id,
                'doctor_id' => $doctor['id'],
                'location_id' => $branches['GGN'],
                'appointment_date' => now()->subWeek()->toDateString(),
                'type' => Appointment::WALK_IN,
                'status' => Appointment::STATUS_COMPLETED,
                'token_no' => 1,
                'checked_in_at' => now()->subWeek(),
                'started_at' => now()->subWeek()->addMinutes(10),
                'completed_at' => now()->subWeek()->addMinutes(25),
            ])->id;
        });

        $this->putJson("/api/v1/tenant/doctors/{$doctor['id']}", [
            'name' => 'Dr. Anjali Sharma',
            'is_active' => true,
            'locations' => [$branches['DEL']],
        ])->assertOk();

        $this->onTenant($organization, function () use ($appointmentId, $doctor, $branches) {
            $appointment = Appointment::on('organization')->find($appointmentId);

            $this->assertNotNull($appointment, 'The appointment went with the posting.');
            $this->assertSame($doctor['id'], $appointment->doctor_id);
            $this->assertSame($branches['GGN'], $appointment->location_id);
        });
    }

    /**
     * A branch from another organization cannot be posted to.
     *
     * Not by validation — by construction. The branch table being read is this
     * organization's own database, so an id belonging to another tenant is not
     * found at all, and the posting is silently dropped rather than written.
     */
    public function test_a_branch_from_another_organization_is_not_reachable(): void
    {
        [, $branches] = $this->network();

        // A second organization with its own branch, whose ids live in a
        // different database entirely.
        $other = $this->provisionOrganization('B');

        $strangerId = $this->onTenant($other, fn () => Location::on('organization')->create([
            'name' => 'Somewhere else',
            'code' => 'ELSE',
            'type' => Location::CLINIC,
            'is_active' => true,
        ])->id);

        $this->signInAsOwner(Organization::find(Organization::min('id')));

        $doctor = $this->postJson('/api/v1/tenant/doctors', [
            'name' => 'Dr. Contained',
            'is_active' => true,
            'locations' => [$branches['DEL'], $strangerId + 9000],
        ])->assertCreated()->json('data');

        $this->assertSame([$branches['DEL']], $doctor['location_ids']);
    }
}
