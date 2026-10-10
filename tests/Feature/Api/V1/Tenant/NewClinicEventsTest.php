<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\AutomationRule;
use App\Models\Tenant\CommunicationChannel;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Location;
use App\Models\Tenant\MessageLog;
use App\Models\Tenant\MessageTemplate;
use App\Repositories\Tenant\CustomerRepository;
use App\Services\Laboratory\LabOrders;
use App\Services\Communication\OptionalModuleTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TenantTestCase;

/**
 * The foundation phase: two new clinic events (a lab report ready, a patient
 * registered), and the default templates every organisation now starts with
 * — email alongside push, and a lab default for whoever has the module.
 *
 * What matters is not that a row exists, but where the walls are: an
 * organisation with no laboratory module gets no lab default, re-running the
 * seeder or `tenants:migrate` never duplicates a row, and a message only
 * reaches a patient once the clinic's own rule is switched on — exactly the
 * contract every other automated message already keeps.
 */
class NewClinicEventsTest extends TenantTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');
    }

    /*
    |--------------------------------------------------------------------------
    | What a new organisation starts with
    |--------------------------------------------------------------------------
    */

    public function test_a_new_organization_starts_with_email_defaults_switched_off_and_no_lab_default(): void
    {
        $organization = $this->provisionOrganization('NE');

        [$emailTemplates, $emailRules, $pushTemplates, $pushRules, $labRule] = $this->onTenant(
            $organization,
            fn () => [
                MessageTemplate::query()->where('channel', CommunicationChannel::EMAIL)->get(),
                AutomationRule::query()->where('channel', CommunicationChannel::EMAIL)->get(),
                MessageTemplate::query()->where('channel', CommunicationChannel::PUSH)->get(),
                AutomationRule::query()->where('channel', CommunicationChannel::PUSH)->get(),
                AutomationRule::query()->where('event_key', AutomationRule::LAB_REPORT_READY)->first(),
            ],
        );

        // Confirmed, reminder, cancelled, payment, registered.
        $this->assertCount(5, $emailTemplates);
        $this->assertTrue($emailTemplates->every(
            fn (MessageTemplate $t) => $t->status === MessageTemplate::APPROVED && $t->is_active,
        ));

        // Confirmed, reminder day, reminder hour, cancelled, payment, registered.
        $this->assertCount(6, $emailRules);
        $this->assertTrue($emailRules->every(fn (AutomationRule $r) => ! $r->is_enabled && $r->message_template_id !== null));

        // Push gained the same new "registered" entry.
        $this->assertCount(5, $pushTemplates);
        $this->assertCount(6, $pushRules);

        // No laboratory module granted — no lab default, on any channel.
        $this->assertNull($labRule);
    }

    /*
    |--------------------------------------------------------------------------
    | The module-gated default — granted later, backfilled safely
    |--------------------------------------------------------------------------
    */

    public function test_the_lab_default_is_seeded_once_the_module_is_granted_and_never_duplicated(): void
    {
        $organization = $this->provisionOrganization('NE');

        $this->onTenant($organization, function () {
            $this->assertSame(0, AutomationRule::query()->where('event_key', AutomationRule::LAB_REPORT_READY)->count());
        });

        $this->grantModule($organization, 'laboratory');

        $created = $this->onTenant($organization, fn () => app(OptionalModuleTemplateSeeder::class)->seed($organization));
        $this->assertSame([AutomationRule::LAB_REPORT_READY], $created);

        $this->onTenant($organization, function () {
            $rule = AutomationRule::query()->where('event_key', AutomationRule::LAB_REPORT_READY)->sole();

            $this->assertFalse($rule->is_enabled);
            $this->assertNotNull($rule->message_template_id);
        });

        // Run again — the organisation's own retry, or a second tenants:migrate.
        $again = $this->onTenant($organization, fn () => app(OptionalModuleTemplateSeeder::class)->seed($organization));
        $this->assertSame([], $again);

        $this->onTenant($organization, function () {
            $this->assertSame(1, AutomationRule::query()->where('event_key', AutomationRule::LAB_REPORT_READY)->count());
            $this->assertSame(1, MessageTemplate::query()->where('name', 'Lab report ready (email)')->count());
        });
    }

    public function test_tenants_migrate_backfills_the_lab_default_for_an_existing_organization(): void
    {
        $organization = $this->provisionOrganization('NE');
        $this->grantModule($organization, 'laboratory');

        $this->artisan('tenants:migrate', ['--org' => $organization->uuid])->assertSuccessful();

        $this->onTenant($organization, function () {
            $this->assertSame(1, AutomationRule::query()->where('event_key', AutomationRule::LAB_REPORT_READY)->count());
        });
    }

    /*
    |--------------------------------------------------------------------------
    | The events themselves, wired through to a real message
    |--------------------------------------------------------------------------
    */

    /** @return array{0: Organization, 1: int, 2: int, 3: int} org, doctor, branch, patient */
    private function clinicWithEmail(): array
    {
        $organization = $this->provisionOrganization('NE');
        $this->grantModule($organization, 'appointments');
        $this->grantModule($organization, 'communication');
        $this->grantModule($organization, 'laboratory');

        [$doctorId, $branchId, $patientId] = $this->onTenant($organization, function () {
            $branch = Location::on('organization')->create([
                'name' => 'Gurgaon', 'code' => 'NE-GGN', 'type' => Location::CLINIC, 'is_active' => true,
            ]);

            $doctor = Doctor::on('organization')->create([
                'name' => 'Dr. Kavita Rao', 'default_consultation_fee' => 500, 'is_active' => true,
            ]);

            $patient = Customer::on('organization')->create([
                'name' => 'Meera Joshi', 'phone' => '9810000009',
                'email' => 'meera@example.com', 'is_active' => true,
            ]);

            CommunicationChannel::forChannel(CommunicationChannel::EMAIL)->forceFill(['is_connected' => true])->save();

            return [$doctor->id, $branch->id, $patient->id];
        });

        return [$organization, $doctorId, $branchId, $patientId];
    }

    private function switchOnEmail(Organization $organization, string $eventKey): void
    {
        $this->onTenant($organization, fn () => AutomationRule::query()
            ->where('channel', CommunicationChannel::EMAIL)
            ->where('event_key', $eventKey)
            ->update(['is_enabled' => true]));
    }

    public function test_a_lab_report_ready_emails_the_patient_when_the_rule_is_on(): void
    {
        [$organization, $doctorId, $branchId, $patientId] = $this->clinicWithEmail();

        // The module-gated default only exists once the seeder has run for
        // this organisation — the same backfill tenants:migrate performs.
        $this->onTenant($organization, fn () => app(OptionalModuleTemplateSeeder::class)->seed($organization));
        $this->switchOnEmail($organization, AutomationRule::LAB_REPORT_READY);

        // Faked, not left to run inline: SendEmailMessage connects its own
        // tenant and disconnects when it finishes (same rule SendPushMessage
        // follows), which would pull this test's own connection out from
        // under the assertion below. The log row is written before the job
        // is even dispatched, so faking it costs nothing worth checking here.
        Queue::fake();

        $this->onTenant($organization, function () use ($doctorId, $branchId, $patientId) {
            $appointment = Appointment::on('organization')->create([
                'customer_id' => $patientId,
                'doctor_id' => $doctorId,
                'location_id' => $branchId,
                'appointment_date' => now()->toDateString(),
                'type' => Appointment::BOOKED,
                'status' => Appointment::STATUS_CHECKED_IN,
                'slot_at' => '10:00',
            ]);

            $orders = app(LabOrders::class);

            $order = $orders->create($appointment, ['items' => [['test_name' => 'CBC']]]);
            $orders->start($order);
            $orders->record($order->items->first(), ['result_value' => '14.2', 'result_unit' => 'g/dL']);
            $orders->complete($order);

            $log = MessageLog::query()->where('channel', CommunicationChannel::EMAIL)->sole();

            $this->assertSame('meera@example.com', $log->recipient);
            $this->assertSame('Lab report ready (email)', $log->template_name);
        });
    }

    public function test_a_new_patient_is_welcomed_by_email_when_the_rule_is_on(): void
    {
        [$organization] = $this->clinicWithEmail();

        $this->switchOnEmail($organization, AutomationRule::PATIENT_REGISTERED);

        Queue::fake();

        $this->onTenant($organization, function () {
            app(CustomerRepository::class)->create([
                'name' => 'New Patient',
                'phone' => '9810000010',
                'email' => 'new.patient@example.com',
                'is_active' => true,
            ]);

            $log = MessageLog::query()->where('channel', CommunicationChannel::EMAIL)->sole();

            $this->assertSame('new.patient@example.com', $log->recipient);
            $this->assertSame('Welcome — patient registered (email)', $log->template_name);
        });
    }

    public function test_a_new_patient_gets_no_email_while_the_rule_is_off(): void
    {
        [$organization] = $this->clinicWithEmail();

        $this->onTenant($organization, function () {
            app(CustomerRepository::class)->create([
                'name' => 'Quiet Patient',
                'phone' => '9810000011',
                'email' => 'quiet@example.com',
                'is_active' => true,
            ]);

            $this->assertSame(0, MessageLog::query()->count());
        });
    }
}
