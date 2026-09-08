<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Module;
use App\Models\Platform\Organization;
use App\Models\Tenant\ActivityLog;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorSchedule;
use App\Models\Tenant\DoctorScheduleException;
use App\Models\Tenant\Location;
use App\Models\Tenant\User;
use App\Support\Opd\Weekday;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

/**
 * OPD Phase 2b — appointments, tokens and the queue.
 *
 * The rules that matter are not "a row is created" but: a token is issued on
 * arrival and never before, a number is never handed to two people, a slot
 * cannot be sold twice, a doctor on leave cannot be booked, and an
 * appointment cannot move to a state that does not follow from the one it is
 * in.
 */
class AppointmentTest extends TenantTestCase
{
    use RefreshDatabase;

    /** A Monday, so the weekly pattern has something to match. */
    private const MONDAY = '2026-09-07';

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');
    }

    /** @return array{0: Organization, 1: int, 2: int, 3: int} org, doctor, branch, patient */
    private function clinic(): array
    {
        $organization = $this->provisionOrganization();

        $organization->moduleEntitlements()->create([
            'module_id' => Module::where('key', 'appointments')->value('id'),
            'is_enabled' => true,
        ]);

        [$doctorId, $branchId, $patientId] = $this->onTenant($organization, function () {
            $branch = Location::on('organization')->create([
                'name' => 'Gurgaon',
                'code' => 'GGN',
                'type' => Location::CLINIC,
                'is_active' => true,
            ]);

            $doctor = Doctor::on('organization')->create([
                'name' => 'Dr. Anjali Sharma',
                'is_active' => true,
            ]);

            DoctorSchedule::on('organization')->create([
                'doctor_id' => $doctor->id,
                'location_id' => $branch->id,
                'name' => 'Morning OPD',
                'weekday' => Weekday::MONDAY,
                'starts_at' => '10:00',
                'ends_at' => '13:00',
                'slot_minutes' => 15,
                'is_active' => true,
            ]);

            $patient = Customer::on('organization')->create([
                'name' => 'Asha Rane',
                'phone' => '9876500011',
                'is_active' => true,
            ]);

            return [$doctor->id, $branch->id, $patient->id];
        });

        $this->signInAsOwner($organization);

        return [$organization, $doctorId, $branchId, $patientId];
    }

    private function patient(Organization $organization, string $name, string $phone): int
    {
        return $this->onTenant($organization, fn () => Customer::on('organization')->create([
            'name' => $name,
            'phone' => $phone,
            'is_active' => true,
        ])->id);
    }

    private function book(int $doctorId, int $branchId, int $patientId, string $slot): array
    {
        return $this->postJson('/api/v1/tenant/appointments', [
            'customer_id' => $patientId,
            'doctor_id' => $doctorId,
            'location_id' => $branchId,
            'appointment_date' => self::MONDAY,
            'type' => Appointment::BOOKED,
            'slot_at' => $slot,
        ])->assertCreated()->json('data');
    }

    /*
    |--------------------------------------------------------------------------
    | Booking
    |--------------------------------------------------------------------------
    */

    /**
     * A booking takes a time and remembers which sitting produced it.
     *
     * The sitting is provenance — the appointment carries its own date, time
     * and branch, so editing the weekly pattern can never move a booking.
     */
    public function test_booking_a_slot_records_the_sitting_it_came_from(): void
    {
        [$organization, $doctorId, $branchId, $patientId] = $this->clinic();

        $appointment = $this->book($doctorId, $branchId, $patientId, '10:30');

        $this->assertSame('10:30', $appointment['slot_at']);
        $this->assertSame(Appointment::STATUS_BOOKED, $appointment['status']);
        $this->assertNotNull($appointment['doctor_schedule_id']);

        // Not yet arrived, so no number has been taken.
        $this->assertNull($appointment['token_no']);
    }

    /** A slot the doctor does not offer is refused. */
    public function test_a_time_outside_the_sitting_is_refused(): void
    {
        [, $doctorId, $branchId, $patientId] = $this->clinic();

        $this->postJson('/api/v1/tenant/appointments', [
            'customer_id' => $patientId,
            'doctor_id' => $doctorId,
            'location_id' => $branchId,
            'appointment_date' => self::MONDAY,
            'type' => Appointment::BOOKED,
            // The sitting ends at 13:00.
            'slot_at' => '16:00',
        ])->assertStatus(422);
    }

    /** One slot, one booking. */
    public function test_a_slot_cannot_be_sold_twice(): void
    {
        [$organization, $doctorId, $branchId, $patientId] = $this->clinic();

        $this->book($doctorId, $branchId, $patientId, '10:30');

        $second = $this->patient($organization, 'Vikram Shah', '9876500012');

        $this->postJson('/api/v1/tenant/appointments', [
            'customer_id' => $second,
            'doctor_id' => $doctorId,
            'location_id' => $branchId,
            'appointment_date' => self::MONDAY,
            'type' => Appointment::BOOKED,
            'slot_at' => '10:30',
        ])->assertStatus(422);
    }

    /** Cancelling frees the slot; the next patient can have it. */
    public function test_cancelling_frees_the_slot(): void
    {
        [$organization, $doctorId, $branchId, $patientId] = $this->clinic();

        $first = $this->book($doctorId, $branchId, $patientId, '10:30');

        $this->postJson("/api/v1/tenant/appointments/{$first['id']}/cancel", [
            'reason' => 'Patient rang to cancel',
        ])->assertOk();

        $second = $this->patient($organization, 'Vikram Shah', '9876500013');

        $this->book($doctorId, $branchId, $second, '10:30');
    }

    /**
     * A doctor on leave has no slots, so nobody can be booked with them.
     *
     * This is the failure the exception table exists to prevent, checked
     * from the booking side rather than only in availability.
     */
    public function test_a_doctor_on_leave_cannot_be_booked(): void
    {
        [$organization, $doctorId, $branchId, $patientId] = $this->clinic();

        $this->onTenant($organization, fn () => DoctorScheduleException::on('organization')->create([
            'doctor_id' => $doctorId,
            'date' => self::MONDAY,
            'type' => DoctorScheduleException::UNAVAILABLE,
            'reason' => 'On leave',
        ]));

        $this->postJson('/api/v1/tenant/appointments', [
            'customer_id' => $patientId,
            'doctor_id' => $doctorId,
            'location_id' => $branchId,
            'appointment_date' => self::MONDAY,
            'type' => Appointment::BOOKED,
            'slot_at' => '10:30',
        ])->assertStatus(422);
    }

    /** A booked slot stops being offered. */
    public function test_a_booked_slot_disappears_from_what_is_free(): void
    {
        [, $doctorId, $branchId, $patientId] = $this->clinic();

        $before = $this->getJson(
            "/api/v1/tenant/appointments/slots/{$doctorId}?date=".self::MONDAY."&location_id={$branchId}"
        )->assertOk()->json('data.sessions.0');

        $this->assertContains('10:30', $before['slots']);
        $this->assertCount(12, $before['slots']);

        $this->book($doctorId, $branchId, $patientId, '10:30');

        $after = $this->getJson(
            "/api/v1/tenant/appointments/slots/{$doctorId}?date=".self::MONDAY."&location_id={$branchId}"
        )->assertOk()->json('data.sessions.0');

        $this->assertNotContains('10:30', $after['slots']);
        $this->assertContains('10:30', $after['taken']);
        $this->assertCount(11, $after['slots']);
    }

    /*
    |--------------------------------------------------------------------------
    | Tokens
    |--------------------------------------------------------------------------
    */

    /**
     * The token is issued on arrival, not on booking.
     *
     * A patient who never turns up must not consume a number, or the day's
     * tokens have holes nobody can explain.
     */
    public function test_a_token_is_issued_at_check_in(): void
    {
        [, $doctorId, $branchId, $patientId] = $this->clinic();

        $appointment = $this->book($doctorId, $branchId, $patientId, '10:30');
        $this->assertNull($appointment['token_no']);

        $arrived = $this->postJson("/api/v1/tenant/appointments/{$appointment['id']}/check-in")
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $arrived['token_no']);
        $this->assertSame(Appointment::STATUS_CHECKED_IN, $arrived['status']);
        $this->assertNotNull($arrived['checked_in_at']);
    }

    /** A walk-in is here already, so it arrives checked in with a number. */
    public function test_a_walk_in_is_checked_in_immediately(): void
    {
        [, $doctorId, $branchId, $patientId] = $this->clinic();

        $walkIn = $this->postJson('/api/v1/tenant/appointments', [
            'customer_id' => $patientId,
            'doctor_id' => $doctorId,
            'location_id' => $branchId,
            'appointment_date' => self::MONDAY,
            'type' => Appointment::WALK_IN,
        ])->assertCreated()->json('data');

        $this->assertSame(Appointment::STATUS_CHECKED_IN, $walkIn['status']);
        $this->assertSame(1, $walkIn['token_no']);
        $this->assertNull($walkIn['slot_at']);
    }

    /** A walk-in never carries a booked time — that is what makes it one. */
    public function test_a_walk_in_may_not_name_a_time(): void
    {
        [, $doctorId, $branchId, $patientId] = $this->clinic();

        $this->postJson('/api/v1/tenant/appointments', [
            'customer_id' => $patientId,
            'doctor_id' => $doctorId,
            'location_id' => $branchId,
            'appointment_date' => self::MONDAY,
            'type' => Appointment::WALK_IN,
            'slot_at' => '10:30',
        ])->assertStatus(422)->assertJsonValidationErrors('slot_at');
    }

    /**
     * Booked patients and walk-ins share one numbering.
     *
     * One queue, in arrival order — which is the whole reason both live in
     * one table.
     */
    public function test_bookings_and_walk_ins_share_one_run_of_tokens(): void
    {
        [$organization, $doctorId, $branchId, $patientId] = $this->clinic();

        $booked = $this->book($doctorId, $branchId, $patientId, '10:30');

        $walkInPatient = $this->patient($organization, 'Vikram Shah', '9876500014');

        $walkIn = $this->postJson('/api/v1/tenant/appointments', [
            'customer_id' => $walkInPatient,
            'doctor_id' => $doctorId,
            'location_id' => $branchId,
            'appointment_date' => self::MONDAY,
            'type' => Appointment::WALK_IN,
        ])->assertCreated()->json('data');

        // The walk-in arrived first, so they hold token 1.
        $this->assertSame(1, $walkIn['token_no']);

        $arrived = $this->postJson("/api/v1/tenant/appointments/{$booked['id']}/check-in")
            ->assertOk()->json('data');

        $this->assertSame(2, $arrived['token_no']);
    }

    /**
     * A cancelled patient's number is not handed to anybody else.
     *
     * Reusing it would put two people on token 3 in the day's log, and the
     * log is what anybody goes back to.
     */
    public function test_a_cancelled_token_is_not_reused(): void
    {
        [$organization, $doctorId, $branchId, $patientId] = $this->clinic();

        $first = $this->book($doctorId, $branchId, $patientId, '10:30');
        $this->postJson("/api/v1/tenant/appointments/{$first['id']}/check-in")->assertOk();

        $this->postJson("/api/v1/tenant/appointments/{$first['id']}/cancel")->assertOk();

        $second = $this->patient($organization, 'Vikram Shah', '9876500015');

        $next = $this->postJson('/api/v1/tenant/appointments', [
            'customer_id' => $second,
            'doctor_id' => $doctorId,
            'location_id' => $branchId,
            'appointment_date' => self::MONDAY,
            'type' => Appointment::WALK_IN,
        ])->assertCreated()->json('data');

        $this->assertSame(2, $next['token_no']);
    }

    /** Two doctors at one branch keep separate queues. */
    public function test_tokens_are_per_doctor_not_per_branch(): void
    {
        [$organization, $doctorId, $branchId, $patientId] = $this->clinic();

        $otherDoctor = $this->onTenant($organization, function () use ($branchId) {
            $doctor = Doctor::on('organization')->create([
                'name' => 'Dr. Ravi Menon',
                'is_active' => true,
            ]);

            DoctorSchedule::on('organization')->create([
                'doctor_id' => $doctor->id,
                'location_id' => $branchId,
                'weekday' => Weekday::MONDAY,
                'starts_at' => '10:00',
                'ends_at' => '13:00',
                'slot_minutes' => 15,
                'is_active' => true,
            ]);

            return $doctor->id;
        });

        $mine = $this->postJson('/api/v1/tenant/appointments', [
            'customer_id' => $patientId,
            'doctor_id' => $doctorId,
            'location_id' => $branchId,
            'appointment_date' => self::MONDAY,
            'type' => Appointment::WALK_IN,
        ])->assertCreated()->json('data');

        $theirs = $this->postJson('/api/v1/tenant/appointments', [
            'customer_id' => $patientId,
            'doctor_id' => $otherDoctor,
            'location_id' => $branchId,
            'appointment_date' => self::MONDAY,
            'type' => Appointment::WALK_IN,
        ])->assertCreated()->json('data');

        // Each doctor's queue starts at one.
        $this->assertSame(1, $mine['token_no']);
        $this->assertSame(1, $theirs['token_no']);
    }

    /*
    |--------------------------------------------------------------------------
    | The queue
    |--------------------------------------------------------------------------
    */

    /** Arrive, be called, be seen — and not in any other order. */
    public function test_an_appointment_moves_through_the_states_in_order(): void
    {
        [, $doctorId, $branchId, $patientId] = $this->clinic();

        $appointment = $this->book($doctorId, $branchId, $patientId, '10:30');
        $id = $appointment['id'];

        // Cannot start a consultation with somebody who has not arrived.
        $this->postJson("/api/v1/tenant/appointments/{$id}/start")->assertStatus(422);

        $this->postJson("/api/v1/tenant/appointments/{$id}/check-in")->assertOk();
        $this->postJson("/api/v1/tenant/appointments/{$id}/start")->assertOk();

        $done = $this->postJson("/api/v1/tenant/appointments/{$id}/complete")
            ->assertOk()->json('data');

        $this->assertSame(Appointment::STATUS_COMPLETED, $done['status']);
        $this->assertNotNull($done['completed_at']);

        /*
         * A finished consultation cannot be cancelled — it happened.
         *
         * The one thing still open to it is being reopened, and only on the
         * day it happened. This visit is dated to a fixed Monday in the past,
         * so the door is shut on it here; the test below opens one from today.
         */
        $this->postJson("/api/v1/tenant/appointments/{$id}/cancel")->assertStatus(422);
        $this->assertSame([Appointment::STATUS_IN_CONSULTATION], $done['next_states']);

        $this->postJson("/api/v1/tenant/appointments/{$id}/reopen")->assertStatus(422);
    }

    /**
     * A visit finished by mistake goes back in the room, today only.
     *
     * "Complete" sits one click from "Call", and a doctor who hits it on the
     * wrong row loses both the patient and the write-up — which is only
     * reachable while somebody is in consultation. Reopening an older one is
     * refused: it would move a visit into a day it did not happen in and put
     * it back on a queue nobody is working.
     */
    public function test_todays_finished_visit_can_be_reopened(): void
    {
        /*
         * Today IS the fixture's Monday.
         *
         * The clinic in these tests sits on a Monday, and "reopen" only opens
         * today's visit — so the two have to be the same day or the test is
         * arranging a walk-in with a doctor who is not in.
         */
        $this->travelTo(self::MONDAY.' 11:00:00');

        [, $doctorId, $branchId, $patientId] = $this->clinic();

        $today = now()->toDateString();

        $id = $this->postJson('/api/v1/tenant/appointments', [
            'customer_id' => $patientId,
            'doctor_id' => $doctorId,
            'location_id' => $branchId,
            'appointment_date' => $today,
            'type' => 'walk_in',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/tenant/appointments/{$id}/start")->assertOk();
        $this->postJson("/api/v1/tenant/appointments/{$id}/complete")->assertOk();

        $back = $this->postJson("/api/v1/tenant/appointments/{$id}/reopen")
            ->assertOk()
            ->json('data');

        $this->assertSame(Appointment::STATUS_IN_CONSULTATION, $back['status']);

        // No longer finished, so the record must not still say it was.
        $this->assertNull($back['completed_at']);
    }

    /** Somebody who checked in was here, whatever happened next. */
    public function test_a_patient_who_arrived_cannot_be_marked_a_no_show(): void
    {
        [, $doctorId, $branchId, $patientId] = $this->clinic();

        $appointment = $this->book($doctorId, $branchId, $patientId, '10:30');

        $this->postJson("/api/v1/tenant/appointments/{$appointment['id']}/no-show")->assertOk();

        $second = $this->book($doctorId, $branchId, $patientId, '10:45');
        $this->postJson("/api/v1/tenant/appointments/{$second['id']}/check-in")->assertOk();

        $this->postJson("/api/v1/tenant/appointments/{$second['id']}/no-show")->assertStatus(422);
    }

    /** The queue is arrival order, with the booked time shown alongside. */
    public function test_the_queue_is_in_arrival_order(): void
    {
        [$organization, $doctorId, $branchId, $patientId] = $this->clinic();

        // Booked for later in the morning, but arrives first.
        $late = $this->book($doctorId, $branchId, $patientId, '12:30');
        $this->postJson("/api/v1/tenant/appointments/{$late['id']}/check-in")->assertOk();

        $earlyPatient = $this->patient($organization, 'Vikram Shah', '9876500016');
        $early = $this->book($doctorId, $branchId, $earlyPatient, '10:00');
        $this->postJson("/api/v1/tenant/appointments/{$early['id']}/check-in")->assertOk();

        $body = $this->getJson(
            "/api/v1/tenant/appointments?doctor_id={$doctorId}&location_id={$branchId}&date=".self::MONDAY
        )->assertOk()->json('data');

        $tokens = array_column($body['queue'], 'token_no');

        // Whoever walked in first is first, whatever their slot said.
        $this->assertSame([1, 2], $tokens);
        $this->assertSame('12:30', $body['queue'][0]['slot_at']);
        $this->assertSame(2, $body['waiting']);
    }

    /*
    |--------------------------------------------------------------------------
    | Permission and entitlement
    |--------------------------------------------------------------------------
    */

    /** A branch id from the client is just a number until somebody checks it. */
    public function test_staff_cannot_book_at_another_branch(): void
    {
        [$organization, $doctorId, $branchId, $patientId] = $this->clinic();

        $other = $this->onTenant($organization, fn () => Location::on('organization')->create([
            'name' => 'Delhi',
            'code' => 'DEL',
            'type' => Location::CLINIC,
            'is_active' => true,
        ])->id);

        $this->placeStaffAt($organization, $branchId);

        $this->signInAsStaff($organization);

        // Their own branch: fine.
        $this->postJson('/api/v1/tenant/appointments', [
            'customer_id' => $patientId,
            'doctor_id' => $doctorId,
            'location_id' => $branchId,
            'appointment_date' => self::MONDAY,
            'type' => Appointment::BOOKED,
            'slot_at' => '10:30',
        ])->assertCreated();

        $this->postJson('/api/v1/tenant/appointments', [
            'customer_id' => $patientId,
            'doctor_id' => $doctorId,
            'location_id' => $other,
            'appointment_date' => self::MONDAY,
            'type' => Appointment::BOOKED,
            'slot_at' => '11:00',
        ])->assertStatus(422)->assertJsonValidationErrors('location_id');
    }

    public function test_appointments_are_behind_the_module(): void
    {
        $organization = $this->provisionOrganization();
        $this->signInAsOwner($organization);

        $this->getJson('/api/v1/tenant/appointments?doctor_id=1&location_id=1&date='.self::MONDAY)
            ->assertForbidden();
    }

    /** One trait on the model, and the queue has a timeline. */
    public function test_booking_is_recorded_in_the_history(): void
    {
        [$organization, $doctorId, $branchId, $patientId] = $this->clinic();

        $this->book($doctorId, $branchId, $patientId, '10:30');

        $this->onTenant($organization, function () {
            $log = ActivityLog::on('organization')
                ->where('entity_type', 'Appointment')
                ->where('event', 'created')
                ->firstOrFail();

            $this->assertStringContainsString('Asha Rane', (string) $log->entity_label);
        });
    }
}
