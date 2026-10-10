<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Jobs\SendEmailMessage;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Platform\Organization;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\AutomationRule;
use App\Models\Tenant\CommunicationChannel;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorSchedule;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\InvoiceItem;
use App\Models\Tenant\Location;
use App\Models\Tenant\MessageLog;
use App\Models\Tenant\MessageTemplate;
use App\Services\Billing\BillingService;
use App\Support\Opd\Weekday;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TenantTestCase;

/**
 * The communication screen's automation rules, actually sending.
 *
 * SETTING-BASED: a message goes out on a channel only when the communication
 * module runs, the channel is connected, the clinic's rule for the event is
 * on, its template is active and approved, and the patient has an address
 * there. Most of these tests switch exactly one of those off and prove that
 * one switch is enough to keep a channel silent.
 */
class AutomatedMessagesTest extends TenantTestCase
{
    use RefreshDatabase;

    /** A Monday morning, so the Monday sitting is today and still ahead. */
    private const MONDAY = '2026-09-07';

    private Organization $organization;

    private int $branch;

    private int $doctor;

    private int $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');
        $this->travelTo(Carbon::parse(self::MONDAY.' 08:00'));

        $this->organization = $this->provisionOrganization('AM');
        $this->grantModule($this->organization, 'appointments');
        $this->grantModule($this->organization, 'communication');

        [$this->branch, $this->doctor, $this->patient] = $this->onTenant($this->organization, function () {
            $branch = Location::on('organization')->create([
                'name' => 'Gurgaon', 'code' => 'AM-GGN', 'type' => Location::CLINIC, 'is_active' => true,
            ]);

            $doctor = Doctor::on('organization')->create([
                'name' => 'Dr. Amit Singh', 'default_consultation_fee' => 500, 'is_active' => true,
            ]);

            DoctorSchedule::on('organization')->create([
                'doctor_id' => $doctor->id, 'location_id' => $branch->id, 'name' => 'Morning OPD',
                'weekday' => Weekday::MONDAY, 'starts_at' => '10:00', 'ends_at' => '12:00',
                'slot_minutes' => 30, 'is_active' => true,
            ]);

            $patient = Customer::on('organization')->create([
                'name' => 'Rohit Sharma', 'phone' => '9810000004',
                'email' => 'rohit@example.com', 'is_active' => true,
            ]);

            return [$branch->id, $doctor->id, $patient->id];
        });

        $this->signInAsOwner($this->organization);
    }

    /** Switch one channel on: connected, with an approved template and the rule on. */
    private function automate(
        string $channel,
        string $eventKey,
        string $content,
        string $status = MessageTemplate::APPROVED,
        bool $ruleOn = true,
        bool $connected = true,
    ): void {
        $this->onTenant($this->organization, function () use ($channel, $eventKey, $content, $status, $ruleOn, $connected) {
            CommunicationChannel::forChannel($channel)->forceFill(['is_connected' => $connected])->save();

            $template = MessageTemplate::query()->create([
                'channel' => $channel,
                'name' => "{$eventKey} {$channel}",
                'category' => 'Appointment',
                'subject' => $channel === CommunicationChannel::EMAIL ? 'Your appointment at {{clinic_name}}' : null,
                'content' => $content,
                'status' => $status,
                'is_active' => true,
            ]);

            // updateOrCreate, not create: every organisation now starts with
            // default email rules for confirmed/cancelled/payment_received
            // (see EmailDefaults/the 2026_10_23 migration) — this points
            // THAT row at the test's own template instead of colliding.
            AutomationRule::query()->updateOrCreate(
                ['channel' => $channel, 'event_key' => $eventKey],
                [
                    'title' => $eventKey,
                    'message_template_id' => $template->id,
                    'is_enabled' => $ruleOn,
                ],
            );
        });
    }

    private function book(): int
    {
        return (int) $this->postJson('/api/v1/tenant/appointments', [
            'customer_id' => $this->patient,
            'doctor_id' => $this->doctor,
            'location_id' => $this->branch,
            'appointment_date' => self::MONDAY,
            'type' => Appointment::BOOKED,
            'slot_at' => '10:30',
        ])->assertCreated()->json('data.id');
    }

    private const CONFIRMATION = 'Hi {{patient_name}}, your appointment with {{doctor_name}} is confirmed on {{date}} at {{time}}.';

    /*
    |--------------------------------------------------------------------------
    | Every switch on
    |--------------------------------------------------------------------------
    */

    public function test_a_booking_emails_the_patient_when_every_switch_is_on(): void
    {
        $this->automate(CommunicationChannel::EMAIL, AutomationRule::APPOINTMENT_CONFIRMED, self::CONFIRMATION);

        Queue::fake();

        $this->book();

        Queue::assertPushed(SendEmailMessage::class, function (SendEmailMessage $job) {
            return $job->organizationId === $this->organization->id
                && $job->content['to'] === 'rohit@example.com'
                && $job->content['body'] === self::CONFIRMATION
                && $job->content['variables']['patient_name'] === 'Rohit Sharma'
                && $job->content['variables']['doctor_name'] === 'Dr. Amit Singh'
                && $job->content['variables']['date'] === '07 Sep 2026'
                && $job->content['variables']['time'] === '10:30 AM'
                && $job->content['variables']['clinic_name'] === $this->organization->organization_name;
        });

        // In the same delivery log as every other message the clinic sends.
        $log = $this->onTenant($this->organization, fn () => MessageLog::query()->sole());

        $this->assertSame(CommunicationChannel::EMAIL, $log->channel);
        $this->assertSame($this->patient, (int) $log->customer_id);
        $this->assertSame(MessageLog::QUEUED, $log->status);
    }

    public function test_whatsapp_follows_its_own_switches(): void
    {
        $this->automate(CommunicationChannel::WHATSAPP, AutomationRule::APPOINTMENT_CONFIRMED, self::CONFIRMATION);

        Queue::fake();

        $this->book();

        Queue::assertPushed(SendWhatsAppMessage::class);
        Queue::assertNotPushed(SendEmailMessage::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Any one switch off
    |--------------------------------------------------------------------------
    */

    public function test_the_rule_switched_off_sends_nothing(): void
    {
        $this->automate(CommunicationChannel::EMAIL, AutomationRule::APPOINTMENT_CONFIRMED, self::CONFIRMATION, ruleOn: false);

        Queue::fake();
        $this->book();

        Queue::assertNotPushed(SendEmailMessage::class);
        $this->assertSame(0, $this->onTenant($this->organization, fn () => MessageLog::query()->count()));
    }

    public function test_email_not_connected_sends_nothing(): void
    {
        $this->automate(CommunicationChannel::EMAIL, AutomationRule::APPOINTMENT_CONFIRMED, self::CONFIRMATION, connected: false);

        Queue::fake();
        $this->book();

        Queue::assertNotPushed(SendEmailMessage::class);
    }

    public function test_a_draft_template_sends_nothing(): void
    {
        $this->automate(CommunicationChannel::EMAIL, AutomationRule::APPOINTMENT_CONFIRMED, self::CONFIRMATION, status: MessageTemplate::DRAFT);

        Queue::fake();
        $this->book();

        Queue::assertNotPushed(SendEmailMessage::class);
    }

    public function test_the_communication_module_switched_off_sends_nothing(): void
    {
        $this->automate(CommunicationChannel::EMAIL, AutomationRule::APPOINTMENT_CONFIRMED, self::CONFIRMATION);

        $this->putJson('/api/v1/tenant/module-settings', ['modules' => ['communication' => false]])->assertOk();

        Queue::fake();
        $this->book();

        Queue::assertNotPushed(SendEmailMessage::class);
    }

    public function test_a_patient_with_no_email_gets_none_but_whatsapp_still_goes(): void
    {
        $this->onTenant($this->organization, fn () => Customer::query()->whereKey($this->patient)->update(['email' => null]));

        $this->automate(CommunicationChannel::EMAIL, AutomationRule::APPOINTMENT_CONFIRMED, self::CONFIRMATION);
        $this->automate(CommunicationChannel::WHATSAPP, AutomationRule::APPOINTMENT_CONFIRMED, self::CONFIRMATION);

        Queue::fake();
        $this->book();

        Queue::assertNotPushed(SendEmailMessage::class);
        Queue::assertPushed(SendWhatsAppMessage::class);
    }

    /*
    |--------------------------------------------------------------------------
    | The other events
    |--------------------------------------------------------------------------
    */

    public function test_a_cancellation_sends_the_cancellation_notice_and_nothing_else(): void
    {
        $notice = 'Hi {{patient_name}}, your appointment on {{date}} has been cancelled.';

        $this->automate(CommunicationChannel::EMAIL, AutomationRule::APPOINTMENT_CANCELLED, $notice);

        $visit = $this->book();

        Queue::fake();

        $this->postJson("/api/v1/tenant/appointments/{$visit}/cancel", ['reason' => 'Doctor on leave'])->assertOk();

        Queue::assertPushed(SendEmailMessage::class, fn (SendEmailMessage $job) => $job->content['body'] === $notice
            && $job->content['variables']['date'] === '07 Sep 2026');
        Queue::assertPushedTimes(SendEmailMessage::class, 1);
    }

    public function test_a_walk_in_gets_no_confirmation(): void
    {
        $this->automate(CommunicationChannel::EMAIL, AutomationRule::APPOINTMENT_CONFIRMED, self::CONFIRMATION);

        Queue::fake();

        $this->postJson('/api/v1/tenant/appointments', [
            'customer_id' => $this->patient,
            'doctor_id' => $this->doctor,
            'location_id' => $this->branch,
            'appointment_date' => self::MONDAY,
            'type' => Appointment::WALK_IN,
        ])->assertCreated();

        Queue::assertNotPushed(SendEmailMessage::class);
    }

    public function test_a_payment_sends_the_receipt_email(): void
    {
        $receipt = 'Hi {{patient_name}}, we have received your payment of ₹{{amount}} for {{service}}.';

        $this->automate(CommunicationChannel::EMAIL, AutomationRule::PAYMENT_RECEIVED, $receipt);

        Queue::fake();

        $this->onTenant($this->organization, function () {
            $invoice = app(BillingService::class)->draw(
                [
                    'location_id' => $this->branch,
                    'customer_id' => $this->patient,
                    'trigger' => 'manual',
                    'kind' => Invoice::KIND_MANUAL,
                ],
                [['source_type' => InvoiceItem::SOURCE_CUSTOM, 'description' => 'Consultation', 'unit_price' => 500]],
            );

            app(BillingService::class)->recordPayment($invoice, ['method' => 'cash', 'amount' => 500]);
        });

        Queue::assertPushed(SendEmailMessage::class, fn (SendEmailMessage $job) => $job->content['to'] === 'rohit@example.com'
            && $job->content['variables']['amount'] === '500.00'
            && $job->content['variables']['service'] === 'Consultation');
    }
}
