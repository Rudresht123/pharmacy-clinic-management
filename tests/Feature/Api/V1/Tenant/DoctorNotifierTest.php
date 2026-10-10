<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Module;
use App\Models\Platform\Organization;
use App\Models\Tenant\CommunicationChannel;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorSchedule;
use App\Models\Tenant\Location;
use App\Models\Tenant\MessageLog;
use App\Services\Opd\BookingService;
use App\Services\Tenant\DoctorAccountProvisioner;
use App\Support\Opd\Weekday;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TenantTestCase;

/**
 * A doctor hears about their own patients, not just the ones who see them.
 *
 * Every clinic event that names a doctor reaches them, on whatever they
 * actually have — an app login, an email on file — regardless of the
 * clinic's own communication rules, which are about the PATIENT's wording
 * and are free to be switched off entirely.
 */
class DoctorNotifierTest extends TenantTestCase
{
    use RefreshDatabase;

    /** A Monday morning, so the Monday sitting is today and still ahead. */
    private const MONDAY = '2026-09-07';

    private const DOCTOR_EMAIL = 'priya@example-clinic.test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');
        $this->travelTo(Carbon::parse(self::MONDAY.' 08:00'));
    }

    /** @return array{0: Organization, 1: int, 2: int} org, doctor, branch */
    private function clinicWithDoctorLogin(): array
    {
        $organization = $this->provisionOrganization('DN');

        $organization->moduleEntitlements()->create([
            'module_id' => Module::where('key', 'appointments')->value('id'),
            'is_enabled' => true,
        ]);

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
                'email' => self::DOCTOR_EMAIL,
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

            // The doctor's own login — what their push notification reaches.
            app(DoctorAccountProvisioner::class)->open($doctor, [
                'email' => 'priya.login@example-clinic.test',
                'password' => 'Password123!',
            ], ['appointments']);

            return [$doctor->id, $branch->id];
        });

        return [$organization, $doctorId, $branchId];
    }

    public function test_a_doctor_is_notified_by_push_and_email_when_their_patient_books(): void
    {
        [$organization, $doctorId, $branchId] = $this->clinicWithDoctorLogin();

        /*
         * Faked rather than left on `sync`: the real jobs connect to the
         * tenant database themselves and disconnect the shared connection
         * the moment each one finishes — fine with a real queue worker in
         * its own process, but inline under `sync` it would pull this very
         * test's connection out from under the next assertion. The row each
         * one would send is already written before the job is even
         * dispatched, so faking the queue costs nothing worth checking here.
         */
        Queue::fake();

        $this->onTenant($organization, function () use ($doctorId, $branchId) {
            $customer = Customer::on('organization')->create([
                'name' => 'Rahul Tiwari',
                'phone' => '9876500031',
                'is_active' => true,
            ]);

            app(BookingService::class)->book([
                'customer_id' => $customer->id,
                'doctor_id' => $doctorId,
                'location_id' => $branchId,
                'appointment_date' => self::MONDAY,
                'slot_at' => '10:30',
            ]);

            $push = MessageLog::query()
                ->where('channel', CommunicationChannel::PUSH)
                ->where('recipient_name', 'Dr. Priya Sharma')
                ->first();

            $this->assertNotNull($push, 'Expected a push notification queued for the doctor.');

            $email = MessageLog::query()
                ->where('channel', CommunicationChannel::EMAIL)
                ->where('recipient', self::DOCTOR_EMAIL)
                ->first();

            $this->assertNotNull($email, 'Expected an email queued for the doctor.');
            $this->assertSame('Appointment booked', $email->subject);
            $this->assertSame('Dr. Priya Sharma', $email->recipient_name);
        });
    }

    public function test_a_doctor_is_notified_when_an_appointment_is_cancelled(): void
    {
        [$organization, $doctorId, $branchId] = $this->clinicWithDoctorLogin();

        Queue::fake();

        $this->onTenant($organization, function () use ($doctorId, $branchId) {
            $customer = Customer::on('organization')->create([
                'name' => 'Asha Rane',
                'phone' => '9876500032',
                'is_active' => true,
            ]);

            $appointment = app(BookingService::class)->book([
                'customer_id' => $customer->id,
                'doctor_id' => $doctorId,
                'location_id' => $branchId,
                'appointment_date' => self::MONDAY,
                'slot_at' => '11:00',
            ]);

            MessageLog::query()->delete();

            app(BookingService::class)->cancel($appointment, 'Patient asked to reschedule');

            $email = MessageLog::query()
                ->where('channel', CommunicationChannel::EMAIL)
                ->where('recipient', self::DOCTOR_EMAIL)
                ->first();

            $this->assertNotNull($email, 'Expected an email queued for the doctor on cancellation.');
            $this->assertSame('Appointment cancelled', $email->subject);
        });
    }

    /** A doctor with no login and no email on file costs nothing — no error, no row. */
    public function test_a_doctor_with_no_login_and_no_email_is_simply_skipped(): void
    {
        $organization = $this->provisionOrganization('DN');

        $organization->moduleEntitlements()->create([
            'module_id' => Module::where('key', 'appointments')->value('id'),
            'is_enabled' => true,
        ]);

        $this->onTenant($organization, function () {
            $branch = Location::on('organization')->create([
                'name' => 'Gurgaon',
                'code' => 'GGN',
                'type' => Location::CLINIC,
                'is_active' => true,
            ]);

            $doctor = Doctor::on('organization')->create([
                'name' => 'Dr. No Login',
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

            $customer = Customer::on('organization')->create([
                'name' => 'Rahul Tiwari',
                'phone' => '9876500033',
                'is_active' => true,
            ]);

            app(BookingService::class)->book([
                'customer_id' => $customer->id,
                'doctor_id' => $doctor->id,
                'location_id' => $branch->id,
                'appointment_date' => self::MONDAY,
                'slot_at' => '10:30',
            ]);

            $this->assertSame(0, MessageLog::query()->count());
        });
    }
}
