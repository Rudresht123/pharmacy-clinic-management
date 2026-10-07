<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Jobs\SendEmailMessage;
use App\Models\Platform\Organization;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\AutomationDelivery;
use App\Models\Tenant\AutomationRule;
use App\Models\Tenant\CommunicationChannel;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorSchedule;
use App\Models\Tenant\Location;
use App\Models\Tenant\MessageTemplate;
use App\Support\Opd\Weekday;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TenantTestCase;

/**
 * "Your appointment is in an hour" — on the clinic's clock, once, and only
 * when every switch is on.
 *
 * Booked on Sunday morning for Monday 10:30, so both reminders have a moment
 * of their own: the day-before at Sunday 10:30, the hour-before at Monday
 * 09:30.
 */
class AppointmentRemindersTest extends TenantTestCase
{
    use RefreshDatabase;

    private const SUNDAY = '2026-09-06';

    private const MONDAY = '2026-09-07';

    private const REMINDER = 'Hi {{patient_name}}, this is a reminder for your appointment at {{time}} with {{doctor_name}}.';

    private Organization $organization;

    private int $branch;

    private int $doctor;

    private int $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');
        $this->travelTo(Carbon::parse(self::SUNDAY.' 09:00'));

        $this->organization = $this->provisionOrganization('AR');
        $this->grantModule($this->organization, 'appointments');
        $this->grantModule($this->organization, 'communication');

        [$this->branch, $this->doctor, $this->patient] = $this->onTenant($this->organization, function () {
            $branch = Location::on('organization')->create([
                'name' => 'Gurgaon', 'code' => 'AR-GGN', 'type' => Location::CLINIC, 'is_active' => true,
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
                'name' => 'Rohit Sharma', 'phone' => '9810000005',
                'email' => 'rohit@example.com', 'is_active' => true,
            ]);

            return [$branch->id, $doctor->id, $patient->id];
        });

        $this->signInAsOwner($this->organization);
    }

    private function remind(string $eventKey, int $leadMinutes, bool $connected = true): void
    {
        $this->onTenant($this->organization, function () use ($eventKey, $leadMinutes, $connected) {
            CommunicationChannel::forChannel(CommunicationChannel::EMAIL)
                ->forceFill(['is_connected' => $connected])
                ->save();

            $template = MessageTemplate::query()->create([
                'channel' => CommunicationChannel::EMAIL,
                'name' => "Reminder {$leadMinutes}",
                'category' => 'Reminder',
                'subject' => 'See you soon',
                'content' => self::REMINDER,
                'status' => MessageTemplate::APPROVED,
                'is_active' => true,
            ]);

            AutomationRule::query()->create([
                'channel' => CommunicationChannel::EMAIL,
                'event_key' => $eventKey,
                'title' => $eventKey,
                'message_template_id' => $template->id,
                'is_enabled' => true,
                'lead_minutes' => $leadMinutes,
            ]);
        });
    }

    private function book(string $at = '10:30'): int
    {
        return (int) $this->postJson('/api/v1/tenant/appointments', [
            'customer_id' => $this->patient,
            'doctor_id' => $this->doctor,
            'location_id' => $this->branch,
            'appointment_date' => self::MONDAY,
            'type' => Appointment::BOOKED,
            'slot_at' => $at,
        ])->assertCreated()->json('data.id');
    }

    private function runReminders(string $at): void
    {
        $this->travelTo(Carbon::parse($at));

        $this->artisan('communication:send-reminders', ['--org' => $this->organization->uuid])->assertSuccessful();
    }

    public function test_the_hour_reminder_goes_once_an_hour_before(): void
    {
        $this->remind(AutomationRule::APPOINTMENT_REMINDER_HOUR, 60);
        $this->book();

        Queue::fake();

        // A minute early: nothing.
        $this->runReminders(self::MONDAY.' 09:29');
        Queue::assertNotPushed(SendEmailMessage::class);

        $this->runReminders(self::MONDAY.' 09:31');
        Queue::assertPushed(SendEmailMessage::class, fn (SendEmailMessage $job) => $job->content['to'] === 'rohit@example.com'
            && $job->content['body'] === self::REMINDER
            && $job->content['variables']['time'] === '10:30 AM');

        // The scheduler asks again five minutes later: still one reminder.
        $this->runReminders(self::MONDAY.' 09:36');
        Queue::assertPushedTimes(SendEmailMessage::class, 1);
    }

    public function test_the_day_reminder_goes_the_day_before(): void
    {
        $this->remind(AutomationRule::APPOINTMENT_REMINDER_DAY, 1440);
        $this->book();

        Queue::fake();

        $this->runReminders(self::SUNDAY.' 10:31');

        Queue::assertPushedTimes(SendEmailMessage::class, 1);
    }

    public function test_a_booking_made_inside_the_window_gets_no_reminder(): void
    {
        $this->remind(AutomationRule::APPOINTMENT_REMINDER_HOUR, 60);

        // Booked at 10:00 for 10:30: the confirmation was enough.
        $this->travelTo(Carbon::parse(self::MONDAY.' 10:00'));
        $this->book();

        Queue::fake();
        $this->runReminders(self::MONDAY.' 10:05');

        Queue::assertNotPushed(SendEmailMessage::class);
    }

    public function test_a_cancelled_appointment_gets_no_reminder(): void
    {
        $this->remind(AutomationRule::APPOINTMENT_REMINDER_HOUR, 60);
        $visit = $this->book();

        $this->postJson("/api/v1/tenant/appointments/{$visit}/cancel")->assertOk();

        Queue::fake();
        $this->runReminders(self::MONDAY.' 09:31');

        Queue::assertNotPushed(SendEmailMessage::class);
    }

    /**
     * A switch that is off keeps the reminder silent — and does not use it
     * up: switched on before the appointment, it still goes.
     */
    public function test_a_reminder_held_back_by_a_switch_still_goes_once_switched_on(): void
    {
        $this->remind(AutomationRule::APPOINTMENT_REMINDER_HOUR, 60, connected: false);
        $this->book();

        Queue::fake();

        $this->runReminders(self::MONDAY.' 09:31');
        Queue::assertNotPushed(SendEmailMessage::class);
        $this->assertSame(0, $this->onTenant($this->organization, fn () => AutomationDelivery::query()->count()));

        $this->onTenant($this->organization, fn () => CommunicationChannel::forChannel(CommunicationChannel::EMAIL)
            ->forceFill(['is_connected' => true])
            ->save());

        $this->runReminders(self::MONDAY.' 09:40');
        Queue::assertPushedTimes(SendEmailMessage::class, 1);
    }
}
