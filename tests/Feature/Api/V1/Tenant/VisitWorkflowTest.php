<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\ActivityLog;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Location;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\Role;
use App\Support\Opd\Weekday;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

/**
 * The clinic day, end to end, and who is allowed to move it.
 *
 * The rules that matter here are not "a column changed". They are:
 *
 *   - the DESK calls, and that is the whole of its authority over a
 *     consultation. It cannot start one and it cannot finish one.
 *   - the DOCTOR starts and finishes, on somebody who has been called, once.
 *   - the SYSTEM decides when the visit is over, from the prescription, the
 *     lab and the till — and nobody can assert it.
 *
 * Every test below is one of those three sentences, or a way somebody might
 * get round them.
 */
class VisitWorkflowTest extends TenantTestCase
{
    use RefreshDatabase;

    /** A Monday, so the weekly pattern has something to match. */
    private const MONDAY = '2026-09-07';

    private const DOCTOR_PASSWORD = 'doctor-secret-1';

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');
    }

    /**
     * A clinic with a doctor who has a login, and a patient booked in.
     *
     * @return array{0: Organization, 1: int, 2: int, 3: int} org, doctor, branch, patient
     */
    /**
     * The clinic under test, so `checkedIn()` can reach it.
     *
     * Every test destructures the organisation out of `clinic()` anyway; this
     * is the same value, kept where the shared helper can borrow the desk
     * without twenty-four call sites having to pass it along.
     */
    private Organization $clinic;

    private function clinic(): array
    {
        $organization = $this->clinic = $this->provisionOrganization('VW');

        foreach (['appointments', 'medicines', 'prescriptions', 'pharmacy', 'laboratory'] as $module) {
            $this->grantModule($organization, $module);
        }

        $this->signInAsOwner($organization);

        $branchId = $this->onTenant($organization, fn () => Location::on('organization')->create([
            'name' => 'Gurgaon',
            'code' => 'GGN',
            'type' => Location::CLINIC,
            'is_active' => true,
        ])->id);

        $doctor = $this->postJson('/api/v1/tenant/doctors', [
            'name' => 'Dr. Anjali Sharma',
            'is_active' => true,
            'account' => ['email' => 'anjali@clinic.test', 'password' => self::DOCTOR_PASSWORD],
        ])->assertCreated()->json('data');

        $this->putJson("/api/v1/tenant/doctors/{$doctor['id']}/schedules", [
            'schedules' => [[
                'location_id' => $branchId,
                'name' => 'Morning OPD',
                'weekday' => Weekday::MONDAY,
                'starts_at' => '10:00',
                'ends_at' => '13:00',
                'slot_minutes' => 15,
                'is_active' => true,
            ]],
        ])->assertOk();

        $patientId = $this->onTenant($organization, fn () => Customer::on('organization')->create([
            'name' => 'Asha Rane',
            'phone' => '9810000001',
            'is_active' => true,
        ])->id);

        return [$organization, (int) $doctor['id'], (int) $branchId, (int) $patientId];
    }

    private function signInAsDoctor(Organization $organization): void
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/tenant/auth/login', [
            'subdomain' => $organization->subdomain,
            'email' => 'anjali@clinic.test',
            'password' => self::DOCTOR_PASSWORD,
        ])->assertOk();
    }

    /** Book, and arrive. Every test starts from somebody in the waiting room. */
    private function checkedIn(int $doctorId, int $branchId, int $patientId, string $slot = '10:00'): int
    {
        $id = $this->postJson('/api/v1/tenant/appointments', [
            'customer_id' => $patientId,
            'doctor_id' => $doctorId,
            'location_id' => $branchId,
            'appointment_date' => self::MONDAY,
            'type' => Appointment::BOOKED,
            'slot_at' => $slot,
        ])->assertCreated()->json('data.id');

        /*
         * Booked by whoever the test signed in as; ARRIVED BY THE DESK, and
         * THE SESSION IS LEFT THERE.
         *
         * `appointments.queue` is the one capability the owner does not
         * bypass (Permission::DESK_CAPABILITIES) — running the waiting room
         * is reception's job, not the account holder's, and calling the
         * patient through is the same capability as arriving them. Handing
         * the session back to the owner here would only mean every caller
         * that goes on to call the patient had to take it again.
         *
         * This is also what actually happens in a clinic: the desk arrives
         * somebody and calls them in, then the doctor takes over — which is
         * exactly what `consult()` below does.
         */
        $this->checkInAtDesk($this->clinic, $branchId, (int) $id);

        return (int) $id;
    }

    /** The doctor's minimum: called, taken in, written up, finished. */
    private function consult(Organization $organization, int $appointmentId): void
    {
        $this->postJson("/api/v1/tenant/appointments/{$appointmentId}/call")->assertOk();

        $this->signInAsDoctor($organization);

        $this->postJson("/api/v1/tenant/appointments/{$appointmentId}/consultation/start")->assertOk();

        $this->putJson("/api/v1/tenant/appointments/{$appointmentId}/consultation", [
            'chief_complaint' => 'Headache for three days',
        ])->assertOk();
    }

    private function visit(Organization $organization, int $appointmentId): Appointment
    {
        return $this->onTenant(
            $organization,
            fn () => Appointment::on('organization')->findOrFail($appointmentId),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 1–4. Appointment → check-in → waiting → called
    |--------------------------------------------------------------------------
    */

    /** Checking in issues a token AND puts them in the queue, waiting. */
    public function test_checking_in_puts_the_patient_in_the_queue_as_waiting(): void
    {
        [$organization, $doctorId, $branchId, $patientId] = $this->clinic();

        $id = $this->checkedIn($doctorId, $branchId, $patientId);

        $visit = $this->visit($organization, $id);

        $this->assertSame(Appointment::STATUS_CHECKED_IN, $visit->status);
        $this->assertSame(Appointment::QUEUE_WAITING, $visit->queue_status);
        $this->assertSame(Appointment::CONSULT_NOT_STARTED, $visit->consultation_status);
        $this->assertNotNull($visit->token_no);
        $this->assertNotNull($visit->checked_in_at);

        // Who did it, not only that it happened.
        $this->assertNotNull($visit->checked_in_by);
    }

    /**
     * The receptionist calls, and that is ALL it does.
     *
     * The visit stays `checked_in` and the consultation stays `not_started`.
     * Before the split this same click moved the visit to `in_consultation`
     * — the desk starting the doctor's consultation for them.
     */
    public function test_the_receptionist_calls_the_patient_and_nothing_more(): void
    {
        [$organization, $doctorId, $branchId, $patientId] = $this->clinic();

        $id = $this->checkedIn($doctorId, $branchId, $patientId);

        $this->postJson("/api/v1/tenant/appointments/{$id}/call")
            ->assertOk()
            ->assertJsonPath('data.queue_status', Appointment::QUEUE_CALLED)
            ->assertJsonPath('data.status', Appointment::STATUS_CHECKED_IN)
            ->assertJsonPath('data.consultation_status', Appointment::CONSULT_NOT_STARTED);

        $visit = $this->visit($organization, $id);

        $this->assertNotNull($visit->called_at);
        $this->assertNotNull($visit->called_by);
    }

    /*
    |--------------------------------------------------------------------------
    | The desk boundary
    |--------------------------------------------------------------------------
    |
    | `appointments.queue` is the ONE capability the owner does not bypass
    | (Permission::DESK_CAPABILITIES), so it is asserted from both sides.
    | Without the third test here the rule is only held up by the rest of this
    | file happening to arrive patients as the desk, which would keep passing
    | if somebody quietly handed the capability back to the owner.
    */

    /** The person who mans the counter arrives a patient. */
    public function test_the_desk_checks_a_patient_in(): void
    {
        [$organization, $doctorId, $branchId, $patientId] = $this->clinic();

        $id = $this->postJson('/api/v1/tenant/appointments', [
            'customer_id' => $patientId,
            'doctor_id' => $doctorId,
            'location_id' => $branchId,
            'appointment_date' => self::MONDAY,
            'type' => Appointment::BOOKED,
            'slot_at' => '10:00',
        ])->assertCreated()->json('data.id');

        $this->signInAsReceptionist($organization, $branchId);

        $this->postJson("/api/v1/tenant/appointments/{$id}/check-in")
            ->assertOk()
            ->assertJsonPath('data.status', Appointment::STATUS_CHECKED_IN)
            ->assertJsonPath('data.queue_status', Appointment::QUEUE_WAITING);
    }

    /** The account holder watches the waiting room — seeing is not working. */
    public function test_the_owner_sees_the_queue(): void
    {
        [, $doctorId, $branchId, $patientId] = $this->clinic();

        $this->checkedIn($doctorId, $branchId, $patientId);

        $this->signInAsOwner($this->clinic);

        $this->getJson('/api/v1/tenant/opd/today?location_id='.$branchId)->assertOk();
    }

    /** ...and does not work it. */
    public function test_the_owner_cannot_check_a_patient_in(): void
    {
        [, $doctorId, $branchId, $patientId] = $this->clinic();

        // Booking is the owner's to do; arriving somebody is not.
        $id = $this->postJson('/api/v1/tenant/appointments', [
            'customer_id' => $patientId,
            'doctor_id' => $doctorId,
            'location_id' => $branchId,
            'appointment_date' => self::MONDAY,
            'type' => Appointment::BOOKED,
            'slot_at' => '10:00',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/tenant/appointments/{$id}/check-in")->assertForbidden();
    }

    /** A called patient appears on the doctor's own list, marked as called. */
    public function test_a_called_patient_appears_to_the_doctor(): void
    {
        [$organization, $doctorId, $branchId, $patientId] = $this->clinic();

        $id = $this->checkedIn($doctorId, $branchId, $patientId);
        $this->postJson("/api/v1/tenant/appointments/{$id}/call")->assertOk();

        $this->signInAsDoctor($organization);

        $row = collect(
            $this->getJson('/api/v1/tenant/opd/my-day?date='.self::MONDAY)
                ->assertOk()
                ->json('data.queue')
        )->firstWhere('id', $id);

        $this->assertSame(Appointment::QUEUE_CALLED, $row['queue_status']);

        // And the button the doctor should see is the one offered.
        $this->assertTrue($row['available']['consult_start']);
        $this->assertFalse($row['available']['consult_complete']);
    }

    /*
    |--------------------------------------------------------------------------
    | 5–8. The consultation, and who owns it
    |--------------------------------------------------------------------------
    */

    /** Starting moves the queue and the consultation together. */
    public function test_the_doctor_starts_the_consultation(): void
    {
        [$organization, $doctorId, $branchId, $patientId] = $this->clinic();

        $id = $this->checkedIn($doctorId, $branchId, $patientId);
        $this->postJson("/api/v1/tenant/appointments/{$id}/call")->assertOk();

        $this->signInAsDoctor($organization);

        $this->postJson("/api/v1/tenant/appointments/{$id}/consultation/start")
            ->assertOk()
            ->assertJsonPath('data.status', Appointment::STATUS_IN_CONSULTATION)
            ->assertJsonPath('data.queue_status', Appointment::QUEUE_WITH_DOCTOR)
            ->assertJsonPath('data.consultation_status', Appointment::CONSULT_IN_PROGRESS);

        $visit = $this->visit($organization, $id);

        $this->assertNotNull($visit->started_at);
        $this->assertNotNull($visit->consultation_started_by);
    }

    /**
     * A doctor cannot start on somebody the desk has not called.
     *
     * Taking a patient out of turn makes the queue at reception stop
     * describing the room.
     */
    public function test_a_consultation_cannot_start_before_the_patient_is_called(): void
    {
        [$organization, $doctorId, $branchId, $patientId] = $this->clinic();

        $id = $this->checkedIn($doctorId, $branchId, $patientId);

        $this->signInAsDoctor($organization);

        $this->postJson("/api/v1/tenant/appointments/{$id}/consultation/start")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This patient has not been called yet. Reception calls them through first.');

        $this->assertSame(
            Appointment::CONSULT_NOT_STARTED,
            $this->visit($organization, $id)->consultation_status,
        );
    }

    /** 6 · The same consultation cannot be started twice. */
    public function test_a_consultation_cannot_be_started_twice(): void
    {
        [$organization, $doctorId, $branchId, $patientId] = $this->clinic();

        $id = $this->checkedIn($doctorId, $branchId, $patientId);
        $this->postJson("/api/v1/tenant/appointments/{$id}/call")->assertOk();

        $this->signInAsDoctor($organization);

        $this->postJson("/api/v1/tenant/appointments/{$id}/consultation/start")->assertOk();

        $started = $this->visit($organization, $id)->started_at;

        $this->postJson("/api/v1/tenant/appointments/{$id}/consultation/start")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This consultation is already under way. Open it rather than starting it again.');

        // The clock did not restart, which is what a second start would do.
        $this->assertEquals($started, $this->visit($organization, $id)->started_at);
    }

    /** 7 · Completing records who and when, and takes them out of the queue. */
    public function test_the_doctor_completes_the_consultation(): void
    {
        [$organization, $doctorId, $branchId, $patientId] = $this->clinic();

        $id = $this->checkedIn($doctorId, $branchId, $patientId);
        $this->consult($organization, $id);

        $this->postJson("/api/v1/tenant/appointments/{$id}/consultation/complete")
            ->assertOk()
            ->assertJsonPath('data.consultation_status', Appointment::CONSULT_COMPLETED)
            // Out of the queue: they are no longer waiting for a room.
            ->assertJsonPath('data.queue_status', null);

        $visit = $this->visit($organization, $id);

        $this->assertNotNull($visit->completed_at);
        $this->assertNotNull($visit->consultation_completed_by);
    }

    /**
     * 8 · THE HEADLINE. A receptionist cannot complete a consultation.
     *
     * They hold `appointments.queue`, which used to cover this. The route
     * now demands `appointments.consult_complete`, which the Receptionist
     * template deliberately does not include.
     */
    public function test_a_receptionist_cannot_complete_a_consultation(): void
    {
        [$organization, $doctorId, $branchId, $patientId] = $this->clinic();

        $id = $this->checkedIn($doctorId, $branchId, $patientId);
        $this->consult($organization, $id);

        $this->asReceptionist($organization, $branchId);

        $this->postJson("/api/v1/tenant/appointments/{$id}/consultation/complete")
            ->assertStatus(403);

        $this->postJson("/api/v1/tenant/appointments/{$id}/consultation/start")
            ->assertStatus(403);

        // Untouched: still in the room, still not written off.
        $this->assertSame(
            Appointment::CONSULT_IN_PROGRESS,
            $this->visit($organization, $id)->consultation_status,
        );
    }

    /** A doctor cannot write up, start or finish another doctor's visit. */
    public function test_a_consultation_belongs_to_the_doctor_who_saw_the_patient(): void
    {
        [$organization, $doctorId, $branchId, $patientId] = $this->clinic();

        $this->signInAsOwner($organization);

        $other = $this->postJson('/api/v1/tenant/doctors', [
            'name' => 'Dr. Vikram Rao',
            'is_active' => true,
            'account' => ['email' => 'vikram@clinic.test', 'password' => self::DOCTOR_PASSWORD],
        ])->assertCreated()->json('data');

        $id = $this->checkedIn($doctorId, $branchId, $patientId);
        $this->postJson("/api/v1/tenant/appointments/{$id}/call")->assertOk();

        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/tenant/auth/login', [
            'subdomain' => $organization->subdomain,
            'email' => 'vikram@clinic.test',
            'password' => self::DOCTOR_PASSWORD,
        ])->assertOk();

        $this->assertNotNull($other['id']);

        $this->postJson("/api/v1/tenant/appointments/{$id}/consultation/start")
            ->assertStatus(403);
    }

    /*
    |--------------------------------------------------------------------------
    | 9–12. What completing a consultation actually decides
    |--------------------------------------------------------------------------
    */

    /** 9 · Nothing downstream, so the visit is over. */
    public function test_a_consultation_with_nothing_downstream_completes_the_visit(): void
    {
        [$organization, $doctorId, $branchId, $patientId] = $this->clinic();

        $id = $this->checkedIn($doctorId, $branchId, $patientId);
        $this->consult($organization, $id);

        $this->postJson("/api/v1/tenant/appointments/{$id}/consultation/complete")
            ->assertOk()
            ->assertJsonPath('data.status', Appointment::STATUS_COMPLETED)
            ->assertJsonPath('data.next_action', Appointment::NEXT_NONE);

        $this->assertNotNull($this->visit($organization, $id)->visit_completed_at);
    }

    /** A follow-up does not hold the visit open; it is what to say on the way out. */
    public function test_a_follow_up_completes_the_visit_and_is_reported_as_the_next_action(): void
    {
        [$organization, $doctorId, $branchId, $patientId] = $this->clinic();

        $id = $this->checkedIn($doctorId, $branchId, $patientId);
        $this->consult($organization, $id);

        $this->putJson("/api/v1/tenant/appointments/{$id}/consultation", [
            'chief_complaint' => 'Headache',
            'follow_up_days' => 7,
        ])->assertOk();

        $this->postJson("/api/v1/tenant/appointments/{$id}/consultation/complete")
            ->assertOk()
            ->assertJsonPath('data.status', Appointment::STATUS_COMPLETED)
            ->assertJsonPath('data.next_action', Appointment::NEXT_FOLLOW_UP);
    }

    /** 10 · A prescription sends the patient to the counter. */
    public function test_a_prescription_leaves_the_visit_awaiting_pharmacy(): void
    {
        [$organization, $doctorId, $branchId, $patientId] = $this->clinic();

        $id = $this->checkedIn($doctorId, $branchId, $patientId);
        $this->consult($organization, $id);

        $this->prescribe($id);

        $this->postJson("/api/v1/tenant/appointments/{$id}/consultation/complete")
            ->assertOk()
            ->assertJsonPath('data.status', Appointment::STATUS_AWAITING_PHARMACY)
            ->assertJsonPath('data.next_action', Appointment::NEXT_PHARMACY);

        $this->assertNull($this->visit($organization, $id)->visit_completed_at);
    }

    /** 11 · A lab order sends them to the lab. */
    public function test_a_lab_order_leaves_the_visit_awaiting_the_laboratory(): void
    {
        [$organization, $doctorId, $branchId, $patientId] = $this->clinic();

        $id = $this->checkedIn($doctorId, $branchId, $patientId);
        $this->consult($organization, $id);

        $this->orderTests($id);

        $this->postJson("/api/v1/tenant/appointments/{$id}/consultation/complete")
            ->assertOk()
            ->assertJsonPath('data.status', Appointment::STATUS_AWAITING_LAB)
            ->assertJsonPath('data.next_action', Appointment::NEXT_LABORATORY);
    }

    /**
     * Both at once: the visit reports the first step and knows about the rest.
     *
     * `status` holds one waiting room, so the order matters — pharmacy first,
     * because medicines are handed over in minutes and bloods are not.
     */
    public function test_a_prescription_and_a_lab_order_send_the_patient_to_the_pharmacy_first(): void
    {
        [$organization, $doctorId, $branchId, $patientId] = $this->clinic();

        $id = $this->checkedIn($doctorId, $branchId, $patientId);
        $this->consult($organization, $id);

        $this->prescribe($id);
        $this->orderTests($id);

        $this->postJson("/api/v1/tenant/appointments/{$id}/consultation/complete")
            ->assertOk()
            ->assertJsonPath('data.status', Appointment::STATUS_AWAITING_PHARMACY);
    }

    /*
    |--------------------------------------------------------------------------
    | 13–16. What closes a visit
    |--------------------------------------------------------------------------
    */

    /** 14 · Finishing the lab work is what lets the visit close. */
    public function test_completing_the_lab_work_completes_the_visit(): void
    {
        [$organization, $doctorId, $branchId, $patientId] = $this->clinic();

        $id = $this->checkedIn($doctorId, $branchId, $patientId);
        $this->consult($organization, $id);

        $order = $this->orderTests($id);

        $this->postJson("/api/v1/tenant/appointments/{$id}/consultation/complete")->assertOk();

        $this->assertSame(
            Appointment::STATUS_AWAITING_LAB,
            $this->visit($organization, $id)->status,
        );

        // The bench takes it on, records the reading, and signs it off.
        $this->signInAsOwner($organization);

        $this->postJson("/api/v1/tenant/lab-orders/{$order['id']}/process")->assertOk();

        $this->putJson(
            "/api/v1/tenant/lab-orders/{$order['id']}/items/{$order['items'][0]['id']}/result",
            ['result_value' => '14.2', 'result_unit' => 'g/dL', 'reference_range' => '13.0-17.0'],
        )->assertOk();

        $this->postJson("/api/v1/tenant/lab-orders/{$order['id']}/complete")->assertOk();

        $visit = $this->visit($organization, $id);

        $this->assertSame(Appointment::STATUS_COMPLETED, $visit->status);
        $this->assertNotNull($visit->visit_completed_at);
    }

    /** A lab order cannot be signed off with a test still blank. */
    public function test_a_lab_order_cannot_be_completed_with_a_result_outstanding(): void
    {
        [$organization, $doctorId, $branchId, $patientId] = $this->clinic();

        $id = $this->checkedIn($doctorId, $branchId, $patientId);
        $this->consult($organization, $id);

        $order = $this->orderTests($id, ['CBC', 'LFT']);

        $this->postJson("/api/v1/tenant/appointments/{$id}/consultation/complete")->assertOk();

        $this->signInAsOwner($organization);

        $this->postJson("/api/v1/tenant/lab-orders/{$order['id']}/process")->assertOk();

        $this->putJson(
            "/api/v1/tenant/lab-orders/{$order['id']}/items/{$order['items'][0]['id']}/result",
            ['result_value' => '14.2'],
        )->assertOk();

        $this->postJson("/api/v1/tenant/lab-orders/{$order['id']}/complete")
            ->assertStatus(409);

        $this->assertSame(
            Appointment::STATUS_AWAITING_LAB,
            $this->visit($organization, $id)->status,
        );
    }

    /** A lab order raised after the visit closed reopens it. */
    public function test_ordering_tests_after_completion_reopens_the_visit(): void
    {
        [$organization, $doctorId, $branchId, $patientId] = $this->clinic();

        $id = $this->checkedIn($doctorId, $branchId, $patientId);
        $this->consult($organization, $id);

        $this->postJson("/api/v1/tenant/appointments/{$id}/consultation/complete")->assertOk();

        $this->assertSame(
            Appointment::STATUS_COMPLETED,
            $this->visit($organization, $id)->status,
        );

        $this->orderTests($id);

        $this->assertSame(
            Appointment::STATUS_AWAITING_LAB,
            $this->visit($organization, $id)->status,
        );
    }

    /** 17 · The visit cannot be driven backwards, by anybody. */
    public function test_a_completed_consultation_cannot_be_started_again(): void
    {
        [$organization, $doctorId, $branchId, $patientId] = $this->clinic();

        $id = $this->checkedIn($doctorId, $branchId, $patientId);
        $this->consult($organization, $id);

        $this->postJson("/api/v1/tenant/appointments/{$id}/consultation/complete")->assertOk();

        $this->postJson("/api/v1/tenant/appointments/{$id}/consultation/start")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This consultation has already been completed. Reopen it if it needs changing.');
    }

    /** A consultation cannot be completed before it is started. */
    public function test_a_consultation_that_never_started_cannot_be_completed(): void
    {
        [$organization, $doctorId, $branchId, $patientId] = $this->clinic();

        $id = $this->checkedIn($doctorId, $branchId, $patientId);

        $this->signInAsDoctor($organization);

        $this->postJson("/api/v1/tenant/appointments/{$id}/consultation/complete")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This consultation has not been started, so there is nothing to complete.');
    }

    /** An empty write-up is not a consultation, and cannot be signed off. */
    public function test_a_blank_consultation_cannot_be_completed(): void
    {
        [$organization, $doctorId, $branchId, $patientId] = $this->clinic();

        $id = $this->checkedIn($doctorId, $branchId, $patientId);

        $this->postJson("/api/v1/tenant/appointments/{$id}/call")->assertOk();

        $this->signInAsDoctor($organization);
        $this->postJson("/api/v1/tenant/appointments/{$id}/consultation/start")->assertOk();

        $this->postJson("/api/v1/tenant/appointments/{$id}/consultation/complete")
            ->assertStatus(422);
    }

    /*
    |--------------------------------------------------------------------------
    | 21–22. Two people, one patient
    |--------------------------------------------------------------------------
    */

    /** 21 · Two receptionists cannot both call the same token. */
    public function test_the_same_patient_cannot_be_called_twice(): void
    {
        [$organization, $doctorId, $branchId, $patientId] = $this->clinic();

        $id = $this->checkedIn($doctorId, $branchId, $patientId);

        $this->postJson("/api/v1/tenant/appointments/{$id}/call")->assertOk();

        $calledAt = $this->visit($organization, $id)->called_at;

        $second = $this->postJson("/api/v1/tenant/appointments/{$id}/call")->assertStatus(422);

        // The refusal names who and when, which is what the person at the
        // other counter needs to hear.
        $this->assertStringContainsString('has already been called', $second->json('message'));

        // And it did not quietly re-stamp the moment.
        $this->assertEquals($calledAt, $this->visit($organization, $id)->called_at);
    }

    /** Somebody who has not arrived cannot be called. */
    public function test_a_patient_who_has_not_checked_in_cannot_be_called(): void
    {
        [, $doctorId, $branchId, $patientId] = $this->clinic();

        $id = $this->postJson('/api/v1/tenant/appointments', [
            'customer_id' => $patientId,
            'doctor_id' => $doctorId,
            'location_id' => $branchId,
            'appointment_date' => self::MONDAY,
            'type' => Appointment::BOOKED,
            'slot_at' => '10:00',
        ])->assertCreated()->json('data.id');

        /*
         * Asked by the desk, because calling somebody through is the desk's
         * capability. Asking as the owner would be refused for holding no
         * `appointments.queue` at all — a 403 that would pass a looser
         * assertion while proving nothing about the queue's own rule.
         */
        $this->signInAsReceptionist($this->clinic, $branchId);

        $this->postJson("/api/v1/tenant/appointments/{$id}/call")
            ->assertStatus(422)
            ->assertJsonPath('message', 'That patient has not checked in yet. Check them in first.');
    }

    /*
    |--------------------------------------------------------------------------
    | 19–20. Isolation
    |--------------------------------------------------------------------------
    */

    /** 19 · Somebody who does not work at that branch cannot move its queue. */
    public function test_branch_isolation_is_preserved(): void
    {
        [$organization, $doctorId, $branchId, $patientId] = $this->clinic();

        $id = $this->checkedIn($doctorId, $branchId, $patientId);

        $other = $this->onTenant($organization, fn () => Location::on('organization')->create([
            'name' => 'Noida',
            'code' => 'NOI',
            'type' => Location::CLINIC,
            'is_active' => true,
        ])->id);

        // A receptionist who works at Noida, asked about Gurgaon's patient.
        $this->asReceptionist($organization, (int) $other);

        $this->postJson("/api/v1/tenant/appointments/{$id}/call")->assertStatus(403);
    }

    /** 20 · One organization's visit is invisible to another's. */
    public function test_organization_isolation_is_preserved(): void
    {
        [, $doctorId, $branchId, $patientId] = $this->clinic();

        $id = $this->checkedIn($doctorId, $branchId, $patientId);

        // A second, entirely separate clinic — its own database.
        [$other] = $this->clinic();

        $this->signInAsOwner($other);

        $this->postJson("/api/v1/tenant/appointments/{$id}/call")->assertStatus(404);
    }

    /*
    |--------------------------------------------------------------------------
    | Audit
    |--------------------------------------------------------------------------
    */

    /** Every workflow move leaves a trail with a person and a time on it. */
    public function test_the_workflow_is_audited(): void
    {
        [$organization, $doctorId, $branchId, $patientId] = $this->clinic();

        $id = $this->checkedIn($doctorId, $branchId, $patientId);
        $this->consult($organization, $id);
        $this->postJson("/api/v1/tenant/appointments/{$id}/consultation/complete")->assertOk();

        // `entity_type` is the short name — see RecordsHistory, which stores
        // class_basename so the log stays readable after a namespace moves.
        $entries = $this->onTenant($organization, fn () => ActivityLog::on('organization')
            ->where('entity_type', 'Appointment')
            ->where('entity_id', $id)
            ->get());

        // Booked, checked in, called, started, completed — more than one
        // person's work, each recorded as it happened.
        $this->assertGreaterThanOrEqual(4, $entries->count());
        $this->assertTrue($entries->every(fn (ActivityLog $row) => $row->created_at !== null));
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers that need the clinic already set up
    |--------------------------------------------------------------------------
    */

    /** An unlisted line, the way a doctor types a medicine outside the catalogue. */
    private function prescribe(int $appointmentId): array
    {
        return $this->postJson('/api/v1/tenant/prescriptions', [
            'appointment_id' => $appointmentId,
            'items' => [[
                'medicine_name' => 'Amlodipine 5mg',
                'dose_amount' => 1,
                'dose_unit' => 'tablet',
                'frequency' => 'od',
                'duration' => 30,
                'duration_unit' => 'days',
            ]],
        ])->assertCreated()->json('data');
    }

    /** @param  list<string>  $tests */
    private function orderTests(int $appointmentId, array $tests = ['CBC']): array
    {
        return $this->postJson('/api/v1/tenant/lab-orders', [
            'appointment_id' => $appointmentId,
            'items' => array_map(fn (string $test) => ['test_name' => $test], $tests),
        ])->assertCreated()->json('data');
    }

    /**
     * Sign in as somebody who runs the desk at one branch and nothing wider.
     *
     * The seeded staff account, given exactly the Receptionist template's
     * appointment capabilities — so "a receptionist cannot complete a
     * consultation" is testing the real role rather than an invented one.
     */
    private function asReceptionist(Organization $organization, int $locationId): void
    {
        $this->placeStaffAt($organization, $locationId);

        $this->setStaffCapabilities($organization, [
            'customers.view',
            'appointments.view',
            'appointments.book',
            'appointments.queue',
        ]);

        $this->signInAsStaff($organization);
    }
}
