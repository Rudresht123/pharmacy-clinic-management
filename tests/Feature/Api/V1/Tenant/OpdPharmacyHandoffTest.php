<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Events\Realtime\ConsultationCompleted;
use App\Models\Platform\Organization;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Location;
use App\Models\Tenant\Medicine;
use App\Models\Tenant\PharmacyStore;
use App\Support\Opd\Weekday;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\TenantTestCase;

/**
 * The doctor finishes; the pharmacy counter hears about it.
 *
 * Doctor completes consultation → ConsultationCompleted → Reverb → the
 * counter's "Patients from OPD" panel. Laravel decides everything: whether
 * the branch runs both halves, what the payload says, and who may listen.
 *
 * The cases that matter are the refusals — a branch with no counter, a
 * medical shop with no OPD, a pharmacist at another branch, and another
 * organization's user whose ids happen to collide with ours.
 */
class OpdPharmacyHandoffTest extends TenantTestCase
{
    use RefreshDatabase;

    private const DOCTOR_EMAIL = 'amit@clinic.test';

    private const DOCTOR_PASSWORD = 'doctor-secret-1';

    private Organization $organization;

    private int $gurgaon;

    private int $delhi;

    private int $gurgaonStore;

    private int $delhiStore;

    private int $medicine;

    private int $patient;

    private int $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');

        $this->organization = $this->provisionOrganization('OH');

        foreach (['medicines', 'pharmacy', 'appointments', 'prescriptions'] as $module) {
            $this->grantModule($this->organization, $module);
        }

        $this->onTenant($this->organization, function () {
            $this->gurgaon = Location::on('organization')->create([
                'name' => 'Gurgaon', 'code' => 'OH-GGN', 'type' => Location::CLINIC, 'is_active' => true,
            ])->id;

            $this->delhi = Location::on('organization')->create([
                'name' => 'Delhi', 'code' => 'OH-DEL', 'type' => Location::CLINIC, 'is_active' => true,
            ])->id;

            $this->gurgaonStore = $this->store($this->gurgaon, 'OH-G');
            $this->delhiStore = $this->store($this->delhi, 'OH-D');

            $this->medicine = Medicine::on('organization')->create([
                'generic_name' => 'Amoxicillin',
                'brand_name' => 'Mox 500',
                'strength' => '500 mg',
                'dosage_form' => 'tablet',
                'base_unit' => 'tablet',
                'pack_size' => 10,
                'prescription_required' => true,
                'tax_rate' => 12,
                'hsn_code' => '3004',
                'is_active' => true,
            ])->id;

            $this->patient = Customer::on('organization')->create([
                'name' => 'Rohit Sharma', 'phone' => '9810000003', 'is_active' => true,
            ])->id;
        });

        // The staff member is the pharmacist at Gurgaon.
        $this->placeStaffAt($this->organization, $this->gurgaon);

        $this->signInAsOwner($this->organization);

        $this->doctor = (int) $this->postJson('/api/v1/tenant/doctors', [
            'name' => 'Dr. Amit Singh',
            'is_active' => true,
            'account' => ['email' => self::DOCTOR_EMAIL, 'password' => self::DOCTOR_PASSWORD],
        ])->assertCreated()->json('data.id');

        // Sitting all day, today, so a walk-in today is a real visit.
        $this->putJson("/api/v1/tenant/doctors/{$this->doctor}/schedules", [
            'schedules' => [[
                'location_id' => $this->gurgaon,
                'name' => 'All-day OPD',
                'weekday' => Weekday::of(today()),
                'starts_at' => '00:00',
                'ends_at' => '23:45',
                'slot_minutes' => 15,
                'is_active' => true,
            ]],
        ])->assertOk();
    }

    private function store(int $locationId, string $code): int
    {
        return PharmacyStore::on('organization')->create([
            'location_id' => $locationId,
            'name' => "Counter {$code}",
            'code' => $code,
            'store_type' => PharmacyStore::RETAIL,
            'is_active' => true,
            'is_default' => true,
        ])->id;
    }

    /**
     * A walk-in today at Gurgaon, taken through the desk and the doctor to a
     * completed consultation. Ends signed in as the owner.
     */
    private function consult(bool $prescribe = true): int
    {
        $visit = (int) $this->postJson('/api/v1/tenant/appointments', [
            'customer_id' => $this->patient,
            'doctor_id' => $this->doctor,
            'location_id' => $this->gurgaon,
            'appointment_date' => today()->toDateString(),
            'type' => Appointment::WALK_IN,
        ])->assertCreated()->json('data.id');

        // A walk-in is already checked in; the desk calls them through.
        $this->signInAsReceptionist($this->organization, $this->gurgaon);
        $this->postJson("/api/v1/tenant/appointments/{$visit}/call")->assertOk();

        $this->signInAsDoctor();

        $this->postJson("/api/v1/tenant/appointments/{$visit}/consultation/start")->assertOk();
        $this->putJson("/api/v1/tenant/appointments/{$visit}/consultation", [
            'chief_complaint' => 'Sore throat',
        ])->assertOk();

        if ($prescribe) {
            $prescription = $this->postJson('/api/v1/tenant/prescriptions', [
                'appointment_id' => $visit,
                'items' => [[
                    'medicine_id' => $this->medicine,
                    'dose_amount' => 1,
                    'dose_unit' => 'tablet',
                    'frequency' => 'bd',
                    'duration' => 5,
                    'duration_unit' => 'days',
                    'prescribed_quantity' => 10,
                ]],
            ])->assertCreated()->json('data.id');

            $this->postJson("/api/v1/tenant/prescriptions/{$prescription}/issue")->assertOk();
        }

        $this->postJson("/api/v1/tenant/appointments/{$visit}/consultation/complete")->assertOk();

        $this->signInAsOwner($this->organization);

        return $visit;
    }

    /** The doctor has their own password, so not the shared signIn(). */
    private function signInAsDoctor(): void
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/tenant/auth/login', [
            'subdomain' => $this->organization->subdomain,
            'email' => self::DOCTOR_EMAIL,
            'password' => self::DOCTOR_PASSWORD,
        ])->assertOk();
    }

    private function channel(Organization $organization, int $locationId): string
    {
        return "private-org.{$organization->uuid}.branch.{$locationId}.pharmacy";
    }

    /**
     * Broadcast through Reverb for one test, with the channels re-registered
     * on it. The suite runs on the null driver, and routes/channels.php
     * registers its channels on whichever driver was current at boot.
     */
    private function useReverb(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
            'broadcasting.connections.reverb.options.host' => 'localhost',
            'broadcasting.connections.reverb.options.port' => 8080,
            'broadcasting.connections.reverb.options.scheme' => 'http',
            'broadcasting.connections.reverb.client' => ['host' => 'localhost', 'port' => 8080, 'scheme' => 'http'],
        ]);

        app(BroadcastManager::class)->forgetDrivers();

        require base_path('routes/channels.php');
    }

    private function subscribe(string $channel): TestResponse
    {
        return $this->postJson('/api/v1/broadcasting/auth', [
            'channel_name' => $channel,
            'socket_id' => '1234.5678',
        ]);
    }

    private function asPharmacist(): void
    {
        $this->setStaffCapabilities($this->organization, ['pharmacy.view']);
        $this->signInAsStaff($this->organization);
    }

    /*
    |--------------------------------------------------------------------------
    | The event
    |--------------------------------------------------------------------------
    */

    public function test_completing_a_consultation_tells_that_branchs_pharmacy_counter(): void
    {
        Event::fake([ConsultationCompleted::class]);

        $visit = $this->consult();

        Event::assertDispatchedTimes(ConsultationCompleted::class, 1);
        Event::assertDispatched(ConsultationCompleted::class, function (ConsultationCompleted $event) use ($visit) {
            return $event->organizationUuid === $this->organization->uuid
                && $event->locationId === $this->gurgaon
                && $event->broadcastOn()->name === $this->channel($this->organization, $this->gurgaon)
                && $event->broadcastAs() === 'consultation.completed'
                && $event->patient['appointment_id'] === $visit
                && $event->patient['patient']['name'] === 'Rohit Sharma'
                && $event->patient['doctor']['name'] === 'Dr. Amit Singh'
                && $event->patient['has_prescription'] === true
                && $event->patient['pharmacy_pending'] === true;
        });
    }

    public function test_nothing_is_sent_when_that_branch_does_not_run_the_pharmacy(): void
    {
        $this->disableModuleAtBranch($this->organization, $this->gurgaon, 'pharmacy');

        Event::fake([ConsultationCompleted::class]);

        $this->consult(prescribe: false);

        Event::assertNotDispatched(ConsultationCompleted::class);
    }

    public function test_nothing_is_sent_when_the_organization_switched_the_pharmacy_off(): void
    {
        $this->putJson('/api/v1/tenant/module-settings', ['modules' => ['pharmacy' => false]])->assertOk();

        Event::fake([ConsultationCompleted::class]);

        $this->consult(prescribe: false);

        Event::assertNotDispatched(ConsultationCompleted::class);
    }

    /*
    |--------------------------------------------------------------------------
    | The counter's list
    |--------------------------------------------------------------------------
    */

    public function test_the_counter_lists_todays_patients_from_opd(): void
    {
        $visit = $this->consult();

        $this->getJson("/api/v1/tenant/pharmacy-stores/{$this->gurgaonStore}/opd-patients")
            ->assertOk()
            ->assertJsonPath('data.location_id', $this->gurgaon)
            ->assertJsonPath('data.channel', $this->channel($this->organization, $this->gurgaon))
            ->assertJsonPath('data.patients.0.appointment_id', $visit)
            ->assertJsonPath('data.patients.0.patient.name', 'Rohit Sharma')
            ->assertJsonPath('data.patients.0.doctor.name', 'Dr. Amit Singh')
            ->assertJsonPath('data.patients.0.has_prescription', true)
            ->assertJsonPath('data.patients.0.pharmacy_pending', true);

        // And the pharmacist working the counter, not only the owner.
        $this->asPharmacist();

        $this->getJson("/api/v1/tenant/pharmacy-stores/{$this->gurgaonStore}/opd-patients")
            ->assertOk()
            ->assertJsonCount(1, 'data.patients');
    }

    public function test_another_branchs_counter_does_not_see_these_patients(): void
    {
        $this->consult();

        // The owner reaches Delhi's counter, which has had nobody from OPD.
        $this->getJson("/api/v1/tenant/pharmacy-stores/{$this->delhiStore}/opd-patients")
            ->assertOk()
            ->assertJsonCount(0, 'data.patients');

        // Gurgaon's pharmacist does not work at Delhi at all.
        $this->asPharmacist();

        $this->getJson("/api/v1/tenant/pharmacy-stores/{$this->delhiStore}/opd-patients")
            ->assertStatus(403);
    }

    public function test_a_branch_without_the_pharmacy_has_no_opd_panel(): void
    {
        $this->disableModuleAtBranch($this->organization, $this->gurgaon, 'pharmacy');

        $this->getJson("/api/v1/tenant/pharmacy-stores/{$this->gurgaonStore}/opd-patients")
            ->assertStatus(403);
    }

    public function test_a_pharmacy_only_business_has_no_opd_panel(): void
    {
        $shop = $this->provisionOrganization('MS');
        $this->grantModule($shop, 'medicines');
        $this->grantModule($shop, 'pharmacy');

        $store = $this->onTenant($shop, fn () => $this->store(
            Location::on('organization')->create([
                'name' => 'Main', 'code' => 'MS-1', 'type' => Location::CLINIC, 'is_active' => true,
            ])->id,
            'MS-A',
        ));

        $this->signInAsOwner($shop);

        $this->getJson('/api/v1/tenant/auth/me')->assertJsonPath('data.features.opd_pharmacy_handoff', false);
        $this->getJson("/api/v1/tenant/pharmacy-stores/{$store}/opd-patients")->assertStatus(403);
    }

    /*
    |--------------------------------------------------------------------------
    | Who may listen
    |--------------------------------------------------------------------------
    */

    public function test_a_pharmacist_at_the_branch_may_listen(): void
    {
        $this->useReverb();
        $this->asPharmacist();

        $this->subscribe($this->channel($this->organization, $this->gurgaon))
            ->assertOk()
            ->assertJsonStructure(['auth']);
    }

    public function test_a_pharmacist_may_not_listen_at_a_branch_they_do_not_work_at(): void
    {
        $this->useReverb();
        $this->asPharmacist();

        $this->subscribe($this->channel($this->organization, $this->delhi))->assertStatus(403);
    }

    public function test_somebody_without_pharmacy_view_may_not_listen(): void
    {
        $this->useReverb();
        $this->setStaffCapabilities($this->organization, ['customers.view']);
        $this->signInAsStaff($this->organization);

        $this->subscribe($this->channel($this->organization, $this->gurgaon))->assertStatus(403);
    }

    public function test_a_branch_without_the_pharmacy_has_no_channel_to_join(): void
    {
        $this->useReverb();
        $this->disableModuleAtBranch($this->organization, $this->gurgaon, 'pharmacy');
        $this->asPharmacist();

        $this->subscribe($this->channel($this->organization, $this->gurgaon))->assertStatus(403);
    }

    /**
     * Users and branches are numbered per tenant database, so our Gurgaon id
     * and our staff member's id both exist in the other organization too. The
     * organization in the channel name is what tells them apart.
     */
    public function test_another_organizations_channel_is_refused_even_when_the_ids_collide(): void
    {
        $other = $this->provisionOrganization('OX');

        $this->useReverb();
        $this->asPharmacist();

        $this->subscribe($this->channel($other, $this->gurgaon))->assertStatus(403);
    }

    public function test_nobody_signed_in_may_listen(): void
    {
        $this->useReverb();

        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $this->subscribe($this->channel($this->organization, $this->gurgaon))->assertStatus(401);
    }

    /*
    |--------------------------------------------------------------------------
    | The session
    |--------------------------------------------------------------------------
    */

    public function test_the_session_says_how_to_reach_reverb_only_when_it_runs(): void
    {
        $this->getJson('/api/v1/tenant/auth/me')->assertJsonPath('data.realtime.enabled', false);

        $this->useReverb();

        $this->getJson('/api/v1/tenant/auth/me')
            ->assertJsonPath('data.realtime.enabled', true)
            ->assertJsonPath('data.realtime.key', 'test-key')
            ->assertJsonPath('data.realtime.host', 'localhost')
            ->assertJsonPath('data.realtime.auth_endpoint', '/api/v1/broadcasting/auth')
            // The secret never leaves the server.
            ->assertJsonMissingPath('data.realtime.secret');
    }
}
