<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Module;
use App\Models\Platform\Organization;
use App\Models\Platform\Plan;
use App\Models\Platform\Subscription;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorSchedule;
use App\Models\Tenant\Location;
use App\Services\Portal\Otp\OtpSender;
use App\Services\Subscriptions\Actor;
use App\Services\Subscriptions\SubscriptionLifecycle;
use App\Support\Opd\Weekday;
use App\Support\Subscriptions\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TenantTestCase;

/**
 * The patient app's walls: one clinic per session, and nothing past it.
 *
 * PatientPortalTest proves a patient sees only their own records inside one
 * clinic. This proves the clinic itself is the edge — a patient's token is a
 * token in ONE clinic's database, so it opens nothing anywhere else, nothing
 * without saying which clinic, and nothing once the clinic is closed — and
 * that a booking which loses a race is told so in words.
 */
class PatientPortalIsolationTest extends TenantTestCase
{
    use RefreshDatabase;

    /** A Monday morning, so the Monday sitting is today and still ahead. */
    private const MONDAY = '2026-09-07';

    private const PHONE = '9876500071';

    private const OTHER_PHONE = '9876500072';

    private IsolationOtpSender $sms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');

        $this->sms = new IsolationOtpSender;
        $this->app->instance(OtpSender::class, $this->sms);

        $this->travelTo(Carbon::parse(self::MONDAY.' 08:00'));
    }

    /** @return array{0: Organization, 1: int, 2: int} org, doctor, branch */
    private function clinic(string $prefix): array
    {
        $organization = $this->provisionOrganization($prefix);
        $this->grantModule($organization, 'appointments');

        [$doctorId, $branchId] = $this->onTenant($organization, function () use ($prefix) {
            $branch = Location::on('organization')->create([
                'name' => "{$prefix} Main",
                'code' => "{$prefix}-MAIN",
                'type' => Location::CLINIC,
                'is_active' => true,
            ]);

            $doctor = Doctor::on('organization')->create([
                'name' => "Dr. {$prefix} Sharma",
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

    /**
     * A new request from nobody in particular.
     *
     * Headers too: withHeaders() keeps them for every later request in the
     * test, so a "no clinic named" request would otherwise still carry the
     * clinic — and the token — of the request before it.
     */
    private function fresh(): void
    {
        $this->flushSession();
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
    }

    /** Signs a patient in at one clinic, end to end, and returns their token. */
    private function signInPatient(Organization $organization, string $phone = self::PHONE, string $name = 'Rahul Tiwari'): string
    {
        $this->fresh();

        $this->withHeaders(['X-Organization' => $organization->subdomain])
            ->postJson('/api/v1/tenant/portal/auth/code', ['phone' => $phone])
            ->assertOk();

        $this->fresh();

        return $this->withHeaders(['X-Organization' => $organization->subdomain])
            ->postJson('/api/v1/tenant/portal/auth/verify', ['phone' => $phone, 'code' => $this->sms->last, 'name' => $name])
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

    /** @return array<string, mixed> */
    private function booking(int $doctorId, int $branchId, string $slot = '10:00'): array
    {
        return [
            'doctor_id' => $doctorId,
            'location_id' => $branchId,
            'appointment_date' => self::MONDAY,
            'slot_at' => $slot,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | One clinic per token
    |--------------------------------------------------------------------------
    */

    public function test_a_patients_token_from_one_clinic_opens_nothing_at_another(): void
    {
        [$apollo, $doctorA, $branchA] = $this->clinic('IA');
        [$carePlus, $doctorB, $branchB] = $this->clinic('IB');

        $token = $this->signInPatient($apollo);

        // Home, their list, a booking — none of it, at the other clinic.
        $this->asPatient($carePlus, $token)->getJson('/api/v1/tenant/portal/home')->assertUnauthorized();
        $this->asPatient($carePlus, $token)->getJson('/api/v1/tenant/portal/appointments')->assertUnauthorized();
        $this->asPatient($carePlus, $token)->getJson('/api/v1/tenant/portal/doctors')->assertUnauthorized();
        $this->asPatient($carePlus, $token)
            ->postJson('/api/v1/tenant/portal/appointments', $this->booking($doctorB, $branchB))
            ->assertUnauthorized();

        $this->assertSame(0, $this->onTenant($carePlus, fn () => Appointment::on('organization')->count()));

        // …and it still works where it was issued.
        $this->asPatient($apollo, $token)->getJson('/api/v1/tenant/portal/home')->assertOk();
        $this->asPatient($apollo, $token)
            ->postJson('/api/v1/tenant/portal/appointments', $this->booking($doctorA, $branchA))
            ->assertCreated();
    }

    /** Their account page shows the number they sign in with — masked. */
    public function test_the_home_carries_the_patients_own_number_masked(): void
    {
        [$apollo] = $this->clinic('IH');

        $token = $this->signInPatient($apollo);

        $this->asPatient($apollo, $token)
            ->getJson('/api/v1/tenant/portal/home')
            ->assertOk()
            ->assertJsonPath('data.patient.name', 'Rahul Tiwari')
            ->assertJsonPath('data.patient.phone', '98XXX XX071');
    }

    public function test_a_patients_token_without_a_clinic_named_is_refused(): void
    {
        [$apollo] = $this->clinic('IN');

        $token = $this->signInPatient($apollo);

        $this->fresh();
        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/tenant/portal/home')
            ->assertUnauthorized();

        $this->fresh();
        $this->withHeaders(['X-Organization' => 'no-such-clinic', 'Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/tenant/portal/appointments')
            ->assertUnauthorized();
    }

    /**
     * The same person at two clinics is two patients: two records, two
     * logins, two lists. Neither clinic's session can see or touch the other
     * clinic's booking, even by its id.
     */
    public function test_one_phone_at_two_clinics_is_two_patients_who_never_see_each_other(): void
    {
        [$apollo, $doctorA, $branchA] = $this->clinic('IP');
        [$carePlus] = $this->clinic('IQ');

        $atApollo = $this->signInPatient($apollo);
        $atCarePlus = $this->signInPatient($carePlus);

        $booked = $this->asPatient($apollo, $atApollo)
            ->postJson('/api/v1/tenant/portal/appointments', $this->booking($doctorA, $branchA))
            ->assertCreated()
            ->json('data.id');

        $this->asPatient($carePlus, $atCarePlus)
            ->getJson('/api/v1/tenant/portal/appointments')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->asPatient($carePlus, $atCarePlus)
            ->postJson("/api/v1/tenant/portal/appointments/{$booked}/cancel")
            ->assertNotFound();

        // Written in the first clinic's database only, and still booked there.
        $this->assertSame(
            Appointment::STATUS_BOOKED,
            $this->onTenant($apollo, fn () => Appointment::on('organization')->findOrFail($booked)->status),
        );
        $this->assertSame(0, $this->onTenant($carePlus, fn () => Appointment::on('organization')->count()));
        $this->assertSame(1, $this->onTenant($carePlus, fn () => Customer::on('organization')->count()));
    }

    /*
    |--------------------------------------------------------------------------
    | When the clinic or the patient is switched off
    |--------------------------------------------------------------------------
    */

    public function test_a_suspended_clinic_closes_the_app_for_its_patients(): void
    {
        [$apollo] = $this->clinic('IS');

        $token = $this->signInPatient($apollo);

        $apollo->forceFill(['status' => Organization::SUSPENDED])->save();

        $this->asPatient($apollo, $token)->getJson('/api/v1/tenant/portal/home')->assertUnauthorized();

        // Nor can anybody start a new sign-in there.
        $this->fresh();
        $this->withHeaders(['X-Organization' => $apollo->subdomain])
            ->postJson('/api/v1/tenant/portal/auth/code', ['phone' => self::PHONE])
            ->assertStatus(422)
            ->assertJsonValidationErrors('clinic');
    }

    public function test_a_patient_the_clinic_switched_off_is_shut_out(): void
    {
        [$apollo] = $this->clinic('IO');

        $token = $this->signInPatient($apollo);

        $this->onTenant($apollo, fn () => Customer::on('organization')
            ->where('name', 'Rahul Tiwari')
            ->firstOrFail()
            ->forceFill(['is_active' => false])
            ->save());

        $this->asPatient($apollo, $token)->getJson('/api/v1/tenant/portal/home')->assertForbidden();
    }

    /**
     * A clinic whose subscription lapsed: its patients keep reading what is
     * theirs, lose booking, and are told so in a patient's terms — not asked
     * to renew a subscription that is not theirs.
     */
    public function test_a_lapsed_subscription_keeps_records_readable_and_pauses_booking(): void
    {
        [$apollo, $doctor, $branch] = $this->clinic('IL');

        $plan = Plan::query()->create([
            'slug' => 'plan-'.uniqid(),
            'name' => 'Growth',
            'status' => Plan::PUBLISHED,
            'monthly_price' => 149900,
            'annual_price' => 1499000,
            'trial_days' => 14,
            'grace_days' => 0,
            'features' => [Entitlements::MOBILE_APP],
        ]);

        foreach (Entitlements::limitKeys() as $key) {
            $plan->limits()->create(['key' => $key, 'value' => null]);
        }

        $plan->modules()->sync(Module::query()->where('key', 'appointments')->pluck('id'));

        $subscription = app(SubscriptionLifecycle::class)
            ->assign($apollo, $plan, Subscription::MONTHLY, Actor::system('Test'), 'Test plan.');

        $token = $this->signInPatient($apollo);

        $this->travelTo($subscription->expires_at->copy()->addHour());

        $this->asPatient($apollo, $token)->getJson('/api/v1/tenant/portal/appointments')->assertOk();

        $refused = $this->asPatient($apollo, $token)
            ->postJson('/api/v1/tenant/portal/appointments', $this->booking($doctor, $branch))
            ->assertStatus(423)
            ->assertJsonPath('code', 'subscription_locked');

        $this->assertStringContainsString('paused at this clinic', $refused->json('message'));
        $this->assertStringNotContainsString('Renew', $refused->json('message'));
    }

    public function test_signing_out_ends_the_patients_session(): void
    {
        [$apollo] = $this->clinic('IX');

        $token = $this->signInPatient($apollo);

        $this->asPatient($apollo, $token)->deleteJson('/api/v1/tenant/auth/token')->assertSuccessful();

        $this->asPatient($apollo, $token)->getJson('/api/v1/tenant/portal/home')->assertUnauthorized();
    }

    /*
    |--------------------------------------------------------------------------
    | Booking
    |--------------------------------------------------------------------------
    */

    public function test_booking_is_checked_against_the_clinics_own_doctor_branch_and_times(): void
    {
        [$apollo, $doctor, $branch] = $this->clinic('IV');

        $elsewhere = $this->onTenant($apollo, fn () => Location::on('organization')->create([
            'name' => 'IV Annexe',
            'code' => 'IV-ANX',
            'type' => Location::CLINIC,
            'is_active' => true,
        ])->id);

        $token = $this->signInPatient($apollo);

        // A doctor this clinic does not have.
        $this->asPatient($apollo, $token)
            ->postJson('/api/v1/tenant/portal/appointments', $this->booking(999999, $branch))
            ->assertStatus(422)
            ->assertJsonValidationErrors('doctor_id');

        // A branch they do not sit at.
        $this->asPatient($apollo, $token)
            ->postJson('/api/v1/tenant/portal/appointments', $this->booking($doctor, $elsewhere))
            ->assertStatus(422);

        // A time that is not one of their slots.
        $this->asPatient($apollo, $token)
            ->postJson('/api/v1/tenant/portal/appointments', $this->booking($doctor, $branch, '10:15'))
            ->assertStatus(422);

        // A date past the booking window.
        $this->asPatient($apollo, $token)
            ->postJson('/api/v1/tenant/portal/appointments', [
                ...$this->booking($doctor, $branch),
                'appointment_date' => Carbon::parse(self::MONDAY)->addDays(60)->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('appointment_date');

        $this->assertSame(0, $this->onTenant($apollo, fn () => Appointment::on('organization')->count()));
    }

    /**
     * Two patients tap the same time together. Both pass every check — the
     * time was free when each looked — and the database's slot index lets one
     * of them in. The other gets a sentence, never the SQL error behind it.
     *
     * Simulated by having the rival booking land between the checks and the
     * insert, which is exactly what a real race looks like from inside one of
     * the two requests.
     */
    public function test_a_booking_that_loses_a_race_for_a_time_gets_a_sentence_not_a_database_error(): void
    {
        [$apollo, $doctor, $branch] = $this->clinic('IR');

        $token = $this->signInPatient($apollo);

        $rival = $this->onTenant($apollo, fn () => Customer::on('organization')->create([
            'name' => 'Asha Rane',
            'phone' => self::OTHER_PHONE,
            'is_active' => true,
        ])->id);

        $raced = false;

        Appointment::creating(function (Appointment $appointment) use (&$raced, $rival) {
            if ($raced) {
                return;
            }

            $raced = true;

            Appointment::on('organization')->create([
                'customer_id' => $rival,
                'doctor_id' => $appointment->doctor_id,
                'location_id' => $appointment->location_id,
                'appointment_date' => $appointment->appointment_date,
                'type' => Appointment::BOOKED,
                'mode' => Appointment::MODE_IN_CLINIC,
                'status' => Appointment::STATUS_BOOKED,
                'slot_at' => $appointment->slot_at,
            ]);
        });

        $refused = $this->asPatient($apollo, $token)
            ->postJson('/api/v1/tenant/portal/appointments', $this->booking($doctor, $branch))
            ->assertStatus(422)
            ->assertJsonPath('message', 'That time was just taken or is no longer available. Please pick another.');

        $this->assertStringNotContainsString('SQLSTATE', $refused->getContent());

        // One booking holds the time: the rival's.
        $holders = $this->onTenant($apollo, fn () => Appointment::on('organization')
            ->where('doctor_id', $doctor)
            ->whereDate('appointment_date', self::MONDAY)
            ->where('slot_at', '10:00')
            ->pluck('customer_id')
            ->all());

        $this->assertSame([$rival], $holders);
    }
}

/** Keeps the last code sent, so a test can type it back. */
class IsolationOtpSender implements OtpSender
{
    public ?string $last = null;

    public function send(string $phone, string $code, string $clinicName): void
    {
        $this->last = $code;
    }
}
