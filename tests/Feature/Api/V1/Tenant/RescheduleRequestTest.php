<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Jobs\SendPushMessage;
use App\Models\Platform\Module;
use App\Models\Platform\Organization;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\AppointmentRescheduleRequest;
use App\Models\Tenant\AppointmentSetting;
use App\Models\Tenant\CommunicationChannel;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorSchedule;
use App\Models\Tenant\Location;
use App\Models\Tenant\MessageLog;
use App\Models\Tenant\PatientNotification;
use App\Models\Tenant\User as TenantUser;
use App\Services\Tenant\DoctorPostings;
use App\Services\Tenant\PatientAccountProvisioner;
use App\Support\Opd\Weekday;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TenantTestCase;

/**
 * A patient asks to move a booking; the desk answers; and who moves a patient
 * through the queue is the clinic's own choice.
 */
class RescheduleRequestTest extends TenantTestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-09-07';

    private const WEDNESDAY = '2026-09-09';

    private const PORTAL = '/api/v1/tenant/portal';

    private const STAFF = '/api/v1/tenant';

    private Organization $organization;

    private int $branchId;

    private int $doctorId;

    private string $doctorToken;

    private string $defaultGuard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->defaultGuard = (string) config('auth.defaults.guard');
        $this->artisan('modules:sync');
        $this->travelTo(Carbon::parse(self::MONDAY.' 08:00'));

        $this->organization = $this->provisionOrganization('RS');
        $this->organization->moduleEntitlements()->create([
            'module_id' => Module::where('key', 'appointments')->value('id'),
            'is_enabled' => true,
        ]);

        $this->signInAsOwner($this->organization);

        $this->branchId = $this->onTenant($this->organization, fn () => Location::on('organization')->create([
            'name' => 'Gurgaon',
            'code' => 'GGN',
            'type' => Location::CLINIC,
            'is_active' => true,
        ])->id);

        $this->doctorId = $this->postJson(self::STAFF.'/doctors', [
            'name' => 'Dr. Anjali Sharma',
            'specialisation' => 'General Physician',
            'is_active' => true,
            'account' => ['email' => 'anjali@clinic.test', 'password' => 'doctor-secret-1'],
        ])->assertCreated()->json('data.id');

        $this->onTenant($this->organization, function () {
            foreach ([Weekday::MONDAY, Weekday::MONDAY + 2] as $weekday) {
                DoctorSchedule::on('organization')->create([
                    'doctor_id' => $this->doctorId,
                    'location_id' => $this->branchId,
                    'name' => 'Morning OPD',
                    'weekday' => $weekday,
                    'starts_at' => '10:00',
                    'ends_at' => '12:00',
                    'slot_minutes' => 30,
                    'is_active' => true,
                ]);
            }

            // Posted to the branch, as writing a sitting from the admin does —
            // what lets the doctor work this branch's queue.
            app(DoctorPostings::class)->ensure(Doctor::query()->findOrFail($this->doctorId), $this->branchId);
        });

        $this->fresh();
        $this->doctorToken = $this->postJson(self::STAFF.'/auth/token', [
            'subdomain' => $this->organization->subdomain,
            'email' => 'anjali@clinic.test',
            'password' => 'doctor-secret-1',
        ])->assertOk()->json('data.token');

        Queue::fake();
    }

    /** As a new request finds the app — see VideoCallTest::fresh(). */
    private function fresh(): void
    {
        $this->flushSession();

        // Drops the last caller's token; keeps the SPA's Origin, which the
        // session-based sign-ins need (TestCase::setUp).
        $this->flushHeaders();
        $this->withHeader('Origin', config('app.url'));

        $this->app['auth']->forgetGuards();
        $this->app['auth']->setDefaultDriver($this->defaultGuard);
    }

    private function asToken(string $token): static
    {
        $this->fresh();

        return $this->withHeaders([
            'X-Organization' => $this->organization->subdomain,
            'Authorization' => "Bearer {$token}",
        ]);
    }

    private function asReceptionist(): static
    {
        $this->fresh();
        $this->signInAsReceptionist($this->organization, $this->branchId);

        return $this;
    }

    private function asOwner(): static
    {
        $this->fresh();
        $this->signInAsOwner($this->organization);

        return $this;
    }

    /** @return array{token: string, customer: int, login: int, visit: int} */
    private function patientWithBooking(string $name = 'Rahul Tiwari', string $slot = '10:00'): array
    {
        [$customerId, $token, $login] = $this->onTenant($this->organization, function () use ($name) {
            $customer = Customer::on('organization')->create([
                'name' => $name,
                'phone' => '98'.random_int(10000000, 99999999),
                'is_active' => true,
            ]);

            $user = app(PatientAccountProvisioner::class)->open($customer, ['appointments']);

            return [$customer->id, $user->createToken('Patient phone')->plainTextToken, (int) $user->id];
        });

        $visit = $this->asToken($token)->postJson(self::PORTAL.'/appointments', [
            'doctor_id' => $this->doctorId,
            'location_id' => $this->branchId,
            'appointment_date' => self::MONDAY,
            'slot_at' => $slot,
        ])->assertCreated()->json('data.id');

        return ['token' => $token, 'customer' => $customerId, 'login' => $login, 'visit' => $visit];
    }

    private function askToMove(array $patient, string $slot = '10:30', string $date = self::WEDNESDAY)
    {
        return $this->asToken($patient['token'])->postJson(self::PORTAL."/appointments/{$patient['visit']}/reschedule", [
            'appointment_date' => $date,
            'slot_at' => $slot,
            'reason' => 'Travelling on Monday',
        ]);
    }

    private function visit(int $id): Appointment
    {
        return $this->onTenant($this->organization, fn () => Appointment::query()->findOrFail($id));
    }

    private function login(string $email): int
    {
        return $this->onTenant($this->organization, fn () => (int) TenantUser::query()->where('email', $email)->value('id'));
    }

    private function setting(array $values): void
    {
        $this->onTenant($this->organization, fn () => AppointmentSetting::current()->fill($values)->save());
    }

    /** Who was pushed about a new request. @return list<int> */
    private function pushedAboutRequests(): array
    {
        return collect(Queue::pushed(SendPushMessage::class))
            ->filter(fn (SendPushMessage $job) => ($job->content['data']['type'] ?? null) === 'appointment.reschedule_requested')
            ->flatMap(fn (SendPushMessage $job) => $job->content['user_ids'])
            ->sort()->values()->all();
    }

    /** Who was emailed about a new request. @return list<string> */
    private function emailedAboutRequests(): array
    {
        return $this->onTenant($this->organization, fn () => MessageLog::query()
            ->where('channel', CommunicationChannel::EMAIL)
            ->where('subject', 'Reschedule request')
            ->pluck('recipient')
            ->sort()->values()->all());
    }

    /*
    |--------------------------------------------------------------------------
    | Asking and answering
    |--------------------------------------------------------------------------
    */

    public function test_a_patient_asks_and_the_desk_moves_the_visit(): void
    {
        $patient = $this->patientWithBooking();

        // The receptionist exists before the request, so the desk can be told.
        $this->asReceptionist();

        $this->askToMove($patient)
            ->assertOk()
            ->assertJsonPath('data.reschedule_request.status', 'pending')
            ->assertJsonPath('data.reschedule_request.requested_date', self::WEDNESDAY)
            ->assertJsonPath('data.reschedule_request.requested_slot_at', '10:30')
            // Nothing moves until the clinic says so.
            ->assertJsonPath('data.appointment_date', self::MONDAY)
            ->assertJsonPath('data.slot_at', '10:00');

        // The desk hears about it — and, by default, only the desk.
        $this->assertSame([$this->login(self::RECEPTIONIST_EMAIL)], $this->pushedAboutRequests());
        $this->assertSame([self::RECEPTIONIST_EMAIL], $this->emailedAboutRequests());

        $this->asReceptionist()->getJson(self::STAFF.'/reschedule-requests/count')
            ->assertOk()->assertJsonPath('data.pending', 1);

        $requestId = $this->asReceptionist()->getJson(self::STAFF.'/reschedule-requests')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.patient.name', 'Rahul Tiwari')
            ->assertJsonPath('data.0.from.slot_at', '10:00')
            ->assertJsonPath('data.0.requested.date', self::WEDNESDAY)
            ->assertJsonPath('data.0.reason', 'Travelling on Monday')
            ->json('data.0.id');

        $this->asReceptionist()->postJson(self::STAFF."/reschedule-requests/{$requestId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.approved.date', self::WEDNESDAY)
            ->assertJsonPath('data.decided_by', 'Reception');

        $visit = $this->visit($patient['visit']);
        $this->assertSame(self::WEDNESDAY, $visit->appointment_date->toDateString());
        $this->assertSame('10:30', substr((string) $visit->slot_at, 0, 5));
        $this->assertSame(Appointment::STATUS_BOOKED, $visit->status);

        // The patient is told, in the app and by push.
        $this->assertTrue($this->onTenant($this->organization, fn () => PatientNotification::query()
            ->where('customer_id', $patient['customer'])->where('type', 'appointment_rescheduled')->exists()));
        Queue::assertPushed(SendPushMessage::class, fn (SendPushMessage $job) => $job->content['user_ids'] === [$patient['login']]
            && $job->content['data']['type'] === 'appointment.rescheduled');

        // Monday 10:00 is free again for somebody else.
        $this->patientWithBooking('Meera Nair', '10:00');
    }

    public function test_the_desk_says_no_and_the_visit_stays(): void
    {
        $patient = $this->patientWithBooking();
        $this->askToMove($patient)->assertOk();

        $requestId = $this->asReceptionist()->getJson(self::STAFF.'/reschedule-requests')->json('data.0.id');

        $this->asReceptionist()->postJson(self::STAFF."/reschedule-requests/{$requestId}/reject", [
            'note' => 'Doctor is away on Wednesday.',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->assertSame(self::MONDAY, $this->visit($patient['visit'])->appointment_date->toDateString());

        $this->asToken($patient['token'])->getJson(self::PORTAL.'/appointments')
            ->assertJsonPath('data.0.reschedule_request.status', 'rejected')
            ->assertJsonPath('data.0.reschedule_request.decision_note', 'Doctor is away on Wednesday.');

        // Answered once; a second answer is refused.
        $this->asReceptionist()->postJson(self::STAFF."/reschedule-requests/{$requestId}/approve")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This request has already been answered.');
    }

    public function test_when_the_time_asked_for_has_gone_the_desk_approves_another(): void
    {
        $patient = $this->patientWithBooking();
        $this->askToMove($patient)->assertOk();

        // Somebody else books Wednesday 10:30 while the request waits.
        $other = $this->patientWithBooking('Meera Nair', '11:00');
        $this->asToken($other['token'])->postJson(self::PORTAL.'/appointments', [
            'doctor_id' => $this->doctorId,
            'location_id' => $this->branchId,
            'appointment_date' => self::WEDNESDAY,
            'slot_at' => '10:30',
        ])->assertCreated();

        $requestId = $this->asReceptionist()->getJson(self::STAFF.'/reschedule-requests')->json('data.0.id');

        $this->asReceptionist()->postJson(self::STAFF."/reschedule-requests/{$requestId}/approve")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'That time is not free with this doctor any more. Please choose another.');

        $this->asReceptionist()->postJson(self::STAFF."/reschedule-requests/{$requestId}/approve", [
            'appointment_date' => self::WEDNESDAY,
            'slot_at' => '11:00',
            'note' => 'Agreed 11:00 on the phone.',
        ])->assertOk()->assertJsonPath('data.approved.slot_at', '11:00');

        $this->assertSame('11:00', substr((string) $this->visit($patient['visit'])->slot_at, 0, 5));
    }

    public function test_asking_again_replaces_the_request_and_it_can_be_withdrawn(): void
    {
        $patient = $this->patientWithBooking();

        $this->askToMove($patient, '10:30')->assertOk();
        $this->askToMove($patient, '11:30')->assertOk()->assertJsonPath('data.reschedule_request.requested_slot_at', '11:30');

        $this->assertSame(1, $this->onTenant($this->organization, fn () => AppointmentRescheduleRequest::query()->pending()->count()));

        $this->asToken($patient['token'])->deleteJson(self::PORTAL."/appointments/{$patient['visit']}/reschedule")
            ->assertOk()
            ->assertJsonPath('data.reschedule_request', null);

        $this->asReceptionist()->getJson(self::STAFF.'/reschedule-requests/count')->assertJsonPath('data.pending', 0);
    }

    public function test_a_patient_can_only_ask_for_a_real_free_time_on_their_own_upcoming_visit(): void
    {
        $patient = $this->patientWithBooking();
        $other = $this->patientWithBooking('Meera Nair', '10:30');

        // Not a time the doctor sits.
        $this->askToMove($patient, '15:00')->assertUnprocessable()
            ->assertJsonPath('message', 'That time is not free with this doctor. Please pick another.');

        // Taken by somebody else.
        $this->askToMove($patient, '10:30', self::MONDAY)->assertUnprocessable();

        // The time they already have.
        $this->askToMove($patient, '10:00', self::MONDAY)->assertUnprocessable()
            ->assertJsonPath('message', 'That is the time you already have.');

        // Somebody else's appointment reads as missing.
        $this->asToken($other['token'])->postJson(self::PORTAL."/appointments/{$patient['visit']}/reschedule", [
            'appointment_date' => self::WEDNESDAY,
            'slot_at' => '10:30',
        ])->assertNotFound();

        // Once they are in the building, it is not a reschedule any more.
        $this->asReceptionist()->postJson(self::STAFF."/appointments/{$patient['visit']}/check-in")->assertOk();
        $this->askToMove($patient)->assertUnprocessable()
            ->assertJsonPath('message', 'This visit has already started, so it can no longer be moved.');
    }

    /*
    |--------------------------------------------------------------------------
    | Who hears about it
    |--------------------------------------------------------------------------
    */

    public function test_the_clinic_chooses_who_hears_about_a_request(): void
    {
        $this->asReceptionist();
        $doctorLogin = $this->login('anjali@clinic.test');
        $receptionist = $this->login(self::RECEPTIONIST_EMAIL);

        $this->setting(['reschedule_notify' => AppointmentSetting::DOCTOR]);
        $this->askToMove($this->patientWithBooking('Rahul Tiwari', '10:00'))->assertOk();
        $this->assertSame([$doctorLogin], $this->pushedAboutRequests());
        $this->assertSame(['anjali@clinic.test'], $this->emailedAboutRequests());

        $this->setting(['reschedule_notify' => AppointmentSetting::BOTH]);
        $this->askToMove($this->patientWithBooking('Meera Nair', '10:30'), '11:00')->assertOk();
        $this->assertSame(collect([$doctorLogin, $doctorLogin, $receptionist])->sort()->values()->all(), $this->pushedAboutRequests());
    }

    public function test_only_the_settings_holder_changes_them(): void
    {
        $this->asReceptionist()->putJson(self::STAFF.'/appointment-settings', ['check_in_by' => 'doctor'])->assertForbidden();

        $this->asOwner()->putJson(self::STAFF.'/appointment-settings', [
            'reschedule_notify' => 'both',
            'check_in_by' => 'doctor',
        ])->assertOk()->assertJsonPath('data.check_in_by', 'doctor')->assertJsonPath('data.call_by', 'both');

        $this->asOwner()->putJson(self::STAFF.'/appointment-settings', ['call_by' => 'nobody'])->assertUnprocessable();

        // Anybody who works appointments can read them, to draw their buttons.
        $this->asReceptionist()->getJson(self::STAFF.'/appointment-settings')->assertOk()
            ->assertJsonPath('data.reschedule_notify', 'both');
    }

    /*
    |--------------------------------------------------------------------------
    | Who moves a patient through the queue
    |--------------------------------------------------------------------------
    */

    public function test_a_clinic_without_a_receptionist_lets_the_doctor_run_the_queue(): void
    {
        $this->setting([
            'check_in_by' => AppointmentSetting::DOCTOR,
            'call_by' => AppointmentSetting::DOCTOR,
            'consult_start_by' => AppointmentSetting::DOCTOR,
        ]);

        $patient = $this->patientWithBooking();

        // The desk is told, in words, that this step is not theirs here.
        $this->asReceptionist()->postJson(self::STAFF."/appointments/{$patient['visit']}/check-in")
            ->assertForbidden()
            ->assertJsonPath('message', 'At this clinic, patients are checked in by the doctor.');

        // The doctor takes all three steps for their own patient.
        $this->asToken($this->doctorToken)->postJson(self::STAFF."/appointments/{$patient['visit']}/check-in")->assertOk();
        $this->asToken($this->doctorToken)->postJson(self::STAFF."/appointments/{$patient['visit']}/call")->assertOk();
        $this->asToken($this->doctorToken)->postJson(self::STAFF."/appointments/{$patient['visit']}/consultation/start")->assertOk();

        $this->assertSame(Appointment::STATUS_IN_CONSULTATION, $this->visit($patient['visit'])->status);

        // And the session says so, so the apps draw the buttons.
        $this->asToken($this->doctorToken)->getJson(self::STAFF.'/auth/me')
            ->assertJsonPath('data.queue_steps', ['check_in' => true, 'call' => true, 'consult_start' => true]);
    }

    public function test_a_clinic_can_let_the_desk_start_consultations(): void
    {
        $patient = $this->patientWithBooking();

        // By default starting is the doctor's.
        $this->asReceptionist()->postJson(self::STAFF."/appointments/{$patient['visit']}/check-in")->assertOk();
        $this->asReceptionist()->postJson(self::STAFF."/appointments/{$patient['visit']}/call")->assertOk();
        $this->asReceptionist()->postJson(self::STAFF."/appointments/{$patient['visit']}/consultation/start")
            ->assertForbidden()
            ->assertJsonPath('message', 'At this clinic, consultations are started by the doctor.');

        $this->setting(['consult_start_by' => AppointmentSetting::BOTH]);

        $this->asReceptionist()->postJson(self::STAFF."/appointments/{$patient['visit']}/consultation/start")->assertOk();
    }
}
