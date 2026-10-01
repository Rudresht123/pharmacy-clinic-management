<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Module;
use App\Models\Platform\Organization;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorSchedule;
use App\Models\Tenant\Location;
use App\Models\Tenant\Role;
use App\Models\Tenant\User as TenantUser;
use App\Services\Portal\Otp\OtpSender;
use App\Services\Tenant\PatientAccountProvisioner;
use App\Support\Opd\Weekday;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TenantTestCase;

/**
 * The patient app: signing in with a code, the home screen, and booking.
 *
 * What matters is not that rows are written but where the walls are: a patient
 * sees and books only for themselves, a patient's token opens no staff screen,
 * a staff token opens no patient screen, and what a patient may do follows
 * the editable Patient role rather than anything hard-coded.
 */
class PatientPortalTest extends TenantTestCase
{
    use RefreshDatabase;

    /** A Monday morning, so the Monday sitting is today and still ahead. */
    private const MONDAY = '2026-09-07';

    private const PHONE = '9876500011';

    private CapturingOtpSender $sms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');

        $this->sms = new CapturingOtpSender;
        $this->app->instance(OtpSender::class, $this->sms);

        $this->travelTo(Carbon::parse(self::MONDAY.' 08:00'));
    }

    /** @return array{0: Organization, 1: int, 2: int} org, doctor, branch */
    private function clinic(bool $withAppointments = true): array
    {
        $organization = $this->provisionOrganization('PP');

        if ($withAppointments) {
            $organization->moduleEntitlements()->create([
                'module_id' => Module::where('key', 'appointments')->value('id'),
                'is_enabled' => true,
            ]);
        }

        [$doctorId, $branchId] = $this->onTenant($organization, function () {
            $branch = Location::on('organization')->create([
                'name' => 'Gurgaon',
                'code' => 'GGN',
                'type' => Location::CLINIC,
                'is_active' => true,
            ]);

            $doctor = Doctor::on('organization')->create([
                'name' => 'Dr. Priya Sharma',
                'specialisation' => 'General Physician',
                'default_consultation_fee' => 500,
                'is_active' => true,
            ]);

            DoctorSchedule::on('organization')->create([
                'doctor_id' => $doctor->id,
                'location_id' => $branch->id,
                'name' => 'Morning OPD',
                'weekday' => Weekday::MONDAY,
                'starts_at' => '10:00',
                'ends_at' => '12:00',
                'slot_minutes' => 30,
                'is_active' => true,
            ]);

            return [$doctor->id, $branch->id];
        });

        return [$organization, $doctorId, $branchId];
    }

    private function fresh(): void
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();
    }

    private function sendCode(Organization $organization, string $phone = self::PHONE)
    {
        $this->fresh();

        return $this->withHeaders(['X-Organization' => $organization->subdomain])
            ->postJson('/api/v1/tenant/portal/auth/code', ['phone' => $phone]);
    }

    private function verify(Organization $organization, array $body)
    {
        $this->fresh();

        return $this->withHeaders(['X-Organization' => $organization->subdomain])
            ->postJson('/api/v1/tenant/portal/auth/verify', $body + ['phone' => self::PHONE]);
    }

    /** Signs a patient in end to end and returns their token. */
    private function signInPatient(Organization $organization, string $name = 'Rahul Tiwari'): string
    {
        $this->sendCode($organization)->assertOk();

        return $this->verify($organization, ['code' => $this->sms->last, 'name' => $name])
            ->assertOk()
            ->json('data.token');
    }

    private function asPatient(Organization $organization, string $token): static
    {
        $this->fresh();

        return $this->withHeaders([
            'X-Organization' => $organization->subdomain,
            'Authorization' => "Bearer {$token}",
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Signing in
    |--------------------------------------------------------------------------
    */

    public function test_a_new_patient_signs_up_with_a_code_and_their_name(): void
    {
        [$organization] = $this->clinic();

        $this->sendCode($organization)
            ->assertOk()
            ->assertJsonPath('data.phone', '98XXX XX011');

        $session = $this->verify($organization, ['code' => $this->sms->last, 'name' => 'Rahul Tiwari'])
            ->assertOk();

        $this->assertNotEmpty($session->json('data.token'));
        $this->assertNotNull($session->json('data.customer_id'));
        $this->assertNull($session->json('data.doctor_id'));

        // What they may do comes from the Patient role, through the same
        // capability list staff sessions carry.
        $this->assertEqualsCanonicalizing(
            ['portal.appointments.view', 'portal.appointments.book'],
            $session->json('data.capabilities'),
        );

        $this->onTenant($organization, function () use ($session) {
            $patient = Customer::query()->findOrFail($session->json('data.customer_id'));

            $this->assertSame('Rahul Tiwari', $patient->name);
            $this->assertSame(TenantUser::PATIENT, $patient->user->role);
        });
    }

    /** The desk typed the number one way; the patient types it another. */
    public function test_an_existing_patient_is_matched_however_the_number_was_written(): void
    {
        [$organization] = $this->clinic();

        $existing = $this->onTenant($organization, fn () => Customer::on('organization')->create([
            'name' => 'Asha Rane',
            'phone' => '+91 98765 00011',
            'is_active' => true,
        ])->id);

        $this->sendCode($organization, '098765-00011')->assertOk();

        $this->verify($organization, ['code' => $this->sms->last])
            ->assertOk()
            ->assertJsonPath('data.customer_id', $existing)
            ->assertJsonPath('data.user.name', 'Asha Rane');
    }

    /** The code survives being asked for a name, so nobody waits for a second one. */
    public function test_an_unknown_number_is_asked_for_a_name_without_spending_the_code(): void
    {
        [$organization] = $this->clinic();

        $this->sendCode($organization)->assertOk();
        $code = $this->sms->last;

        $this->verify($organization, ['code' => $code])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->verify($organization, ['code' => $code, 'name' => 'Rahul Tiwari'])->assertOk();

        // …and is then spent.
        $this->verify($organization, ['code' => $code, 'name' => 'Rahul Tiwari'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_a_code_is_spent_after_five_wrong_guesses(): void
    {
        [$organization] = $this->clinic();

        $this->sendCode($organization)->assertOk();
        $real = $this->sms->last;
        $wrong = $real === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->verify($organization, ['code' => $wrong, 'name' => 'X Y'])->assertStatus(422);
        }

        // The right code no longer works either.
        $this->verify($organization, ['code' => $real, 'name' => 'X Y'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_a_new_code_cannot_be_asked_for_straight_away(): void
    {
        [$organization] = $this->clinic();

        $this->sendCode($organization)->assertOk();
        $this->sendCode($organization)->assertStatus(422)->assertJsonValidationErrors('phone');

        $this->travel(31)->seconds();

        $this->sendCode($organization)->assertOk();
    }

    public function test_a_clinic_that_does_not_run_opd_does_not_take_app_sign_ins(): void
    {
        [$organization] = $this->clinic(withAppointments: false);

        $this->sendCode($organization)->assertStatus(422)->assertJsonValidationErrors('clinic');
        $this->assertNull($this->sms->last);
    }

    /*
    |--------------------------------------------------------------------------
    | Home, doctors and booking
    |--------------------------------------------------------------------------
    */

    public function test_a_patient_finds_a_doctor_sees_free_times_and_books_one(): void
    {
        [$organization, $doctorId, $branchId] = $this->clinic();
        $token = $this->signInPatient($organization);

        $this->asPatient($organization, $token)
            ->getJson('/api/v1/tenant/portal/doctors?q=priya')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Dr. Priya Sharma')
            ->assertJsonPath('data.0.days', ['Mon'])
            ->assertJsonPath('data.0.next_available.date', self::MONDAY)
            ->assertJsonPath('data.0.next_available.first_slot', '10:00')
            // Nothing a clinic would not print on a noticeboard.
            ->assertJsonMissingPath('data.0.phone')
            ->assertJsonMissingPath('data.0.registration_no');

        $this->asPatient($organization, $token)
            ->getJson("/api/v1/tenant/portal/doctors/{$doctorId}/slots?date=".self::MONDAY)
            ->assertOk()
            ->assertJsonPath('data.sessions.0.location_id', $branchId)
            ->assertJsonPath('data.sessions.0.slots', ['10:00', '10:30', '11:00', '11:30']);

        $booked = $this->asPatient($organization, $token)
            ->postJson('/api/v1/tenant/portal/appointments', [
                'doctor_id' => $doctorId,
                'location_id' => $branchId,
                'appointment_date' => self::MONDAY,
                'slot_at' => '10:30',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', Appointment::STATUS_BOOKED)
            ->assertJsonPath('data.doctor.name', 'Dr. Priya Sharma');

        // The slot is gone for everybody, exactly as a desk booking would be.
        $this->asPatient($organization, $token)
            ->getJson("/api/v1/tenant/portal/doctors/{$doctorId}/slots?date=".self::MONDAY)
            ->assertJsonPath('data.sessions.0.slots', ['10:00', '11:00', '11:30']);

        $this->asPatient($organization, $token)
            ->getJson('/api/v1/tenant/portal/home')
            ->assertOk()
            ->assertJsonPath('data.patient.name', 'Rahul Tiwari')
            ->assertJsonPath('data.next_appointment.id', $booked->json('data.id'))
            ->assertJsonPath('data.upcoming_count', 1);
    }

    public function test_a_time_that_has_already_passed_today_is_not_offered_or_bookable(): void
    {
        [$organization, $doctorId, $branchId] = $this->clinic();
        $token = $this->signInPatient($organization);

        $this->travelTo(Carbon::parse(self::MONDAY.' 10:45'));

        $this->asPatient($organization, $token)
            ->getJson("/api/v1/tenant/portal/doctors/{$doctorId}/slots?date=".self::MONDAY)
            ->assertJsonPath('data.sessions.0.slots', ['11:00', '11:30']);

        $this->asPatient($organization, $token)
            ->postJson('/api/v1/tenant/portal/appointments', [
                'doctor_id' => $doctorId,
                'location_id' => $branchId,
                'appointment_date' => self::MONDAY,
                'slot_at' => '10:00',
            ])
            ->assertStatus(422);
    }

    public function test_a_patient_cannot_hold_two_slots_with_one_doctor_on_one_day(): void
    {
        [$organization, $doctorId, $branchId] = $this->clinic();
        $token = $this->signInPatient($organization);

        $book = fn (string $slot) => $this->asPatient($organization, $token)
            ->postJson('/api/v1/tenant/portal/appointments', [
                'doctor_id' => $doctorId,
                'location_id' => $branchId,
                'appointment_date' => self::MONDAY,
                'slot_at' => $slot,
            ]);

        $book('10:00')->assertCreated();
        $book('11:00')->assertStatus(422);
    }

    public function test_a_patient_cancels_their_own_booking_and_the_time_is_free_again(): void
    {
        [$organization, $doctorId, $branchId] = $this->clinic();
        $token = $this->signInPatient($organization);

        $id = $this->asPatient($organization, $token)
            ->postJson('/api/v1/tenant/portal/appointments', [
                'doctor_id' => $doctorId,
                'location_id' => $branchId,
                'appointment_date' => self::MONDAY,
                'slot_at' => '10:30',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->asPatient($organization, $token)
            ->postJson("/api/v1/tenant/portal/appointments/{$id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', Appointment::STATUS_CANCELLED);

        // Offered to everybody again, and gone from the patient's upcoming list.
        $this->asPatient($organization, $token)
            ->getJson("/api/v1/tenant/portal/doctors/{$doctorId}/slots?date=".self::MONDAY)
            ->assertJsonPath('data.sessions.0.slots', ['10:00', '10:30', '11:00', '11:30']);

        $this->asPatient($organization, $token)
            ->getJson('/api/v1/tenant/portal/appointments')
            ->assertJsonCount(0, 'data');

        // Twice is a sentence, not an error page.
        $this->asPatient($organization, $token)
            ->postJson("/api/v1/tenant/portal/appointments/{$id}/cancel")
            ->assertStatus(422);
    }

    public function test_a_patient_cannot_cancel_once_checked_in_or_somebody_elses(): void
    {
        [$organization, $doctorId, $branchId] = $this->clinic();
        $token = $this->signInPatient($organization);

        [$mine, $theirs] = $this->onTenant($organization, function () use ($doctorId, $branchId) {
            $patient = Customer::on('organization')->where('name', 'Rahul Tiwari')->firstOrFail();
            $other = Customer::on('organization')->create(['name' => 'Someone Else', 'phone' => '9876599999', 'is_active' => true]);

            $make = fn (Customer $who, string $status, string $slot) => Appointment::on('organization')->create([
                'customer_id' => $who->id,
                'doctor_id' => $doctorId,
                'location_id' => $branchId,
                'appointment_date' => self::MONDAY,
                'type' => Appointment::BOOKED,
                'status' => $status,
                'slot_at' => $slot,
            ])->id;

            return [
                $make($patient, Appointment::STATUS_CHECKED_IN, '10:00'),
                $make($other, Appointment::STATUS_BOOKED, '11:30'),
            ];
        });

        $this->asPatient($organization, $token)
            ->postJson("/api/v1/tenant/portal/appointments/{$mine}/cancel")
            ->assertStatus(422)
            ->assertJsonPath('message', 'You have already checked in. Please speak to the front desk.');

        $this->asPatient($organization, $token)
            ->postJson("/api/v1/tenant/portal/appointments/{$theirs}/cancel")
            ->assertNotFound();

        $this->onTenant($organization, fn () => $this->assertSame(
            Appointment::STATUS_BOOKED,
            Appointment::on('organization')->findOrFail($theirs)->status,
        ));
    }

    /** Somebody else's booking never appears in a patient's own list. */
    public function test_a_patient_sees_only_their_own_appointments(): void
    {
        [$organization, $doctorId, $branchId] = $this->clinic();

        $this->onTenant($organization, function () use ($doctorId, $branchId) {
            $other = Customer::on('organization')->create(['name' => 'Someone Else', 'phone' => '9876599999', 'is_active' => true]);

            Appointment::on('organization')->create([
                'customer_id' => $other->id,
                'doctor_id' => $doctorId,
                'location_id' => $branchId,
                'appointment_date' => self::MONDAY,
                'type' => Appointment::BOOKED,
                'status' => Appointment::STATUS_BOOKED,
                'slot_at' => '11:30',
            ]);
        });

        $token = $this->signInPatient($organization);

        $this->asPatient($organization, $token)
            ->getJson('/api/v1/tenant/portal/appointments')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /*
    |--------------------------------------------------------------------------
    | Walls
    |--------------------------------------------------------------------------
    */

    public function test_a_patient_token_opens_no_staff_screen(): void
    {
        [$organization] = $this->clinic();
        $token = $this->signInPatient($organization);

        $this->asPatient($organization, $token)->getJson('/api/v1/tenant/appointments')->assertForbidden();
        $this->asPatient($organization, $token)->getJson('/api/v1/tenant/customers')->assertForbidden();
        $this->asPatient($organization, $token)->getJson('/api/v1/tenant/doctors')->assertForbidden();
        // Asks for no capability, so it refuses patients itself.
        $this->asPatient($organization, $token)->getJson('/api/v1/tenant/guides')->assertForbidden();
    }

    public function test_a_staff_token_opens_no_patient_screen(): void
    {
        [$organization] = $this->clinic();

        $this->fresh();
        $token = $this->postJson('/api/v1/tenant/auth/token', [
            'subdomain' => $organization->subdomain,
            'email' => $organization->email,
            'password' => self::PASSWORD,
        ])->assertOk()->json('data.token');

        // Even the owner, who holds every capability: the portal is about a
        // patient record, and the owner's login is not one.
        $this->asPatient($organization, $token)->getJson('/api/v1/tenant/portal/home')->assertForbidden();
    }

    /** No role name decides this: taking the capability off the role does. */
    public function test_what_a_patient_may_do_follows_the_editable_patient_role(): void
    {
        [$organization] = $this->clinic();
        $token = $this->signInPatient($organization);

        $this->onTenant($organization, fn () => Role::query()
            ->where('slug', PatientAccountProvisioner::ROLE)
            ->firstOrFail()
            ->syncCapabilities(['portal.appointments.view']));

        $this->asPatient($organization, $token)->getJson('/api/v1/tenant/portal/doctors')->assertForbidden();
        $this->asPatient($organization, $token)->getJson('/api/v1/tenant/portal/appointments')->assertOk();
        $this->asPatient($organization, $token)->getJson('/api/v1/tenant/portal/home')->assertOk();
    }

    public function test_patients_are_not_listed_among_the_organizations_people(): void
    {
        [$organization] = $this->clinic();
        $this->signInPatient($organization);

        $this->signInAsOwner($organization);

        $names = collect($this->getJson('/api/v1/tenant/users')->assertOk()->json('data'))->pluck('name');

        $this->assertNotContains('Rahul Tiwari', $names);
    }
}

/** Keeps the last code instead of texting it. */
class CapturingOtpSender implements OtpSender
{
    public ?string $last = null;

    public function send(string $phone, string $code, string $clinicName): void
    {
        $this->last = $code;
    }
}
