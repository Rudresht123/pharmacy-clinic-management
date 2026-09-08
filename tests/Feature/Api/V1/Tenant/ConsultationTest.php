<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Module;
use App\Models\Platform\Organization;
use App\Models\Tenant\ActivityLog;
use App\Models\Tenant\Consultation;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Location;
use App\Support\Opd\Weekday;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

/**
 * Writing up a visit.
 *
 * The appointment says somebody was due and turned up; the consultation says
 * what the doctor found. The rules worth asserting are about who may write one
 * and about the record surviving correctly — the words themselves are the
 * doctor's business.
 */
class ConsultationTest extends TenantTestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-09-07';

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');
    }

    /**
     * A clinic with two doctors, each with a login, and a patient booked in
     * with the first.
     *
     * @return array{0: Organization, 1: array<string, int>, 2: int, 3: int}
     */
    private function clinic(): array
    {
        $organization = $this->provisionOrganization();

        $organization->moduleEntitlements()->create([
            'module_id' => Module::where('key', 'appointments')->value('id'),
            'is_enabled' => true,
        ]);

        $this->signInAsOwner($organization);

        $branchId = $this->onTenant($organization, fn () => Location::on('organization')->create([
            'name' => 'Gurgaon',
            'code' => 'GGN',
            'type' => Location::CLINIC,
            'is_active' => true,
        ])->id);

        $ids = [];

        foreach (['anjali' => 'Dr. Anjali Sharma', 'vikram' => 'Dr. Vikram Rao'] as $key => $name) {
            $doctor = $this->postJson('/api/v1/tenant/doctors', [
                'name' => $name,
                'is_active' => true,
                'account' => ['email' => "{$key}@clinic.test", 'password' => 'doctor-secret-1'],
            ])->assertCreated()->json('data');

            $ids[$key] = $doctor['id'];

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
        }

        $customerId = $this->onTenant($organization, fn () => Customer::on('organization')->create([
            'name' => 'Asha Rane',
            'phone' => '9810000001',
        ])->id);

        $appointmentId = $this->postJson('/api/v1/tenant/appointments', [
            'customer_id' => $customerId,
            'doctor_id' => $ids['anjali'],
            'location_id' => $branchId,
            'appointment_date' => self::MONDAY,
            'type' => 'booked',
            'slot_at' => '10:00',
        ])->assertCreated()->json('data.id');

        return [$organization, $ids, $branchId, $appointmentId];
    }

    private function signInAsDoctor(Organization $organization, string $email): void
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/tenant/auth/login', [
            'subdomain' => $organization->subdomain,
            'email' => $email,
            'password' => 'doctor-secret-1',
        ])->assertOk();
    }

    /** The doctor who saw the patient writes it up, and it comes back. */
    public function test_a_doctor_writes_up_their_own_visit(): void
    {
        [$organization, , , $appointmentId] = $this->clinic();

        $this->signInAsDoctor($organization, 'anjali@clinic.test');

        $this->putJson("/api/v1/tenant/appointments/{$appointmentId}/consultation", [
            'chief_complaint' => 'Chest pain since 2 days',
            'diagnoses' => ['Hypertension', 'Anxiety'],
            'vitals' => ['bp_systolic' => 148, 'bp_diastolic' => 92, 'pulse' => 88],
            'prescription' => [
                ['drug' => 'Amlodipine 5mg', 'dose' => '1 tablet', 'frequency' => 'Once a day', 'duration' => '30 days'],
            ],
            'investigations' => [['test' => 'ECG', 'notes' => 'Today']],
            'advice' => 'Reduce salt, walk daily',
            'follow_up_days' => 7,
        ])->assertOk();

        $body = $this->getJson("/api/v1/tenant/appointments/{$appointmentId}/consultation")
            ->assertOk()
            ->json('data');

        $this->assertSame('Chest pain since 2 days', $body['chief_complaint']);
        $this->assertSame(['Hypertension', 'Anxiety'], $body['diagnoses']);
        $this->assertSame(148, $body['vitals']['bp_systolic']);
        $this->assertSame('Amlodipine 5mg', $body['prescription'][0]['drug']);
        $this->assertSame('ECG', $body['investigations'][0]['test']);
        $this->assertSame(7, $body['follow_up_days']);
    }

    /**
     * One consultation per visit, however many times it is saved.
     *
     * A doctor adding a diagnosis after the prescription is editing what they
     * wrote — a second row would be two answers to "what was the diagnosis".
     */
    public function test_saving_again_edits_the_same_record(): void
    {
        [$organization, , , $appointmentId] = $this->clinic();

        $this->signInAsDoctor($organization, 'anjali@clinic.test');

        $this->putJson("/api/v1/tenant/appointments/{$appointmentId}/consultation", [
            'chief_complaint' => 'Chest pain',
        ])->assertOk();

        $this->putJson("/api/v1/tenant/appointments/{$appointmentId}/consultation", [
            'chief_complaint' => 'Chest pain',
            'diagnoses' => ['Hypertension'],
        ])->assertOk();

        $this->onTenant($organization, function () use ($appointmentId) {
            $rows = Consultation::on('organization')
                ->where('appointment_id', $appointmentId)
                ->get();

            $this->assertCount(1, $rows, 'A second consultation was written.');
            $this->assertSame(['Hypertension'], $rows->first()->diagnoses);
        });
    }

    /**
     * A doctor writes up their own visits and no one else's.
     *
     * The capability on the route says somebody may work a queue; it does not
     * say whose. Without the check every doctor login could write a diagnosis
     * into another doctor's consultation.
     */
    public function test_a_doctor_cannot_write_up_another_doctors_visit(): void
    {
        [$organization, , , $appointmentId] = $this->clinic();

        // Vikram was not the one who saw this patient.
        $this->signInAsDoctor($organization, 'vikram@clinic.test');

        $this->getJson("/api/v1/tenant/appointments/{$appointmentId}/consultation")
            ->assertStatus(403);

        $this->putJson("/api/v1/tenant/appointments/{$appointmentId}/consultation", [
            'chief_complaint' => 'Not mine to write',
        ])->assertStatus(403);
    }

    /** The desk may move somebody through a queue; it may not diagnose them. */
    public function test_somebody_who_is_not_a_doctor_cannot_write_one(): void
    {
        [$organization, , , $appointmentId] = $this->clinic();

        $this->signInAsOwner($organization);

        $this->putJson("/api/v1/tenant/appointments/{$appointmentId}/consultation", [
            'chief_complaint' => 'Written by the desk',
        ])->assertStatus(403);
    }

    /**
     * Clinical free text never reaches the activity log.
     *
     * `activity_logs` is append-only and un-deletable by design, so a
     * complaint or a diagnosis written into it could never afterwards be
     * corrected or redacted — which is exactly what a patient record must
     * allow. That a consultation was written is logged; the words are not.
     */
    public function test_the_words_stay_out_of_the_audit_log(): void
    {
        [$organization, , , $appointmentId] = $this->clinic();

        $this->signInAsDoctor($organization, 'anjali@clinic.test');

        $this->putJson("/api/v1/tenant/appointments/{$appointmentId}/consultation", [
            'chief_complaint' => 'A private and identifying complaint',
            'diagnoses' => ['A sensitive diagnosis'],
            'notes' => 'Something that must be correctable later',
        ])->assertOk();

        $this->onTenant($organization, function () {
            $logged = ActivityLog::on('organization')
                ->where('entity_type', 'Consultation')
                ->get();

            $this->assertNotEmpty($logged, 'Writing a consultation was not recorded at all.');

            $dump = $logged->toJson();

            $this->assertStringNotContainsString('A private and identifying complaint', $dump);
            $this->assertStringNotContainsString('A sensitive diagnosis', $dump);
            $this->assertStringNotContainsString('Something that must be correctable later', $dump);
        });
    }

    /** A prescription line without a medicine is not a prescription line. */
    public function test_a_prescription_line_needs_a_medicine(): void
    {
        [$organization, , , $appointmentId] = $this->clinic();

        $this->signInAsDoctor($organization, 'anjali@clinic.test');

        $this->putJson("/api/v1/tenant/appointments/{$appointmentId}/consultation", [
            'prescription' => [['dose' => '1 tablet', 'frequency' => 'Twice a day']],
        ])->assertStatus(422)->assertJsonValidationErrors('prescription.0.drug');
    }

    /**
     * A slipped decimal in a vital is a clinical error, not a formatting one.
     */
    public function test_vitals_are_held_to_believable_ranges(): void
    {
        [$organization, , , $appointmentId] = $this->clinic();

        $this->signInAsDoctor($organization, 'anjali@clinic.test');

        $this->putJson("/api/v1/tenant/appointments/{$appointmentId}/consultation", [
            'vitals' => ['temperature' => 370],
        ])->assertStatus(422)->assertJsonValidationErrors('vitals.temperature');

        $this->putJson("/api/v1/tenant/appointments/{$appointmentId}/consultation", [
            'vitals' => ['temperature' => 37.8],
        ])->assertOk();
    }

    /**
     * The day carries what has been written, so the panel opens filled in.
     *
     * A doctor mid-sentence should not wait on a second request to find out
     * whether their own notes exist.
     */
    public function test_the_day_carries_the_write_up_for_the_patient_in_the_room(): void
    {
        [$organization, , , $appointmentId] = $this->clinic();

        $this->postJson("/api/v1/tenant/appointments/{$appointmentId}/check-in")->assertOk();
        $this->postJson("/api/v1/tenant/appointments/{$appointmentId}/start")->assertOk();

        $this->signInAsDoctor($organization, 'anjali@clinic.test');

        $this->putJson("/api/v1/tenant/appointments/{$appointmentId}/consultation", [
            'chief_complaint' => 'Chest pain since 2 days',
        ])->assertOk();

        $current = $this->getJson('/api/v1/tenant/opd/my-day?date='.self::MONDAY)
            ->assertOk()
            ->json('data.current');

        $this->assertSame('Asha Rane', $current['customer_name']);
        $this->assertSame('Chest pain since 2 days', $current['consultation']['chief_complaint']);
    }
}
