<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Http\Middleware\ResolveTenantFromHeader;
use App\Jobs\SendPushMessage;
use App\Models\Platform\Organization;
use App\Models\Tenant\AutomationRule;
use App\Models\Tenant\CommunicationChannel;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorSchedule;
use App\Models\Tenant\Location;
use App\Models\Tenant\MessageLog;
use App\Models\Tenant\MessageTemplate;
use App\Models\Tenant\User as TenantUser;
use App\Services\Portal\Otp\OtpSender;
use App\Services\Push\DeviceRegistry;
use App\Services\Push\PushManager;
use App\Services\Push\PushMessage;
use App\Services\Push\PushResult;
use App\Services\Push\PushSender;
use App\Services\Tenancy\TenantConnectionService;
use App\Support\Opd\Weekday;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TenantTestCase;

/**
 * App notifications — a channel like email and WhatsApp.
 *
 * Its own templates (seeded from the platform's defaults), its own automation
 * rules, its own switch, its own delivery log, and Global → Organisation →
 * Branch wording. Nothing reaches a patient's phone until the clinic switches
 * the channel AND the rule on.
 */
class AppNotificationsChannelTest extends TenantTestCase
{
    use RefreshDatabase;

    /** A Monday morning, so the Monday sitting is today and still ahead. */
    private const MONDAY = '2026-09-07';

    private const PHONE = '9876500031';

    private Organization $organization;

    private int $gurgaon;

    private int $delhi;

    private int $doctor;

    private ChannelTestOtpSender $sms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');
        $this->travelTo(Carbon::parse(self::MONDAY.' 08:00'));

        $this->sms = new ChannelTestOtpSender;
        $this->app->instance(OtpSender::class, $this->sms);

        $this->organization = $this->provisionOrganization('AN');
        $this->grantModule($this->organization, 'appointments');
        $this->grantModule($this->organization, 'communication');

        [$this->gurgaon, $this->delhi, $this->doctor] = $this->onTenant($this->organization, function () {
            $gurgaon = Location::on('organization')->create([
                'name' => 'Gurgaon', 'code' => 'AN-GGN', 'type' => Location::CLINIC, 'is_active' => true,
            ]);
            $delhi = Location::on('organization')->create([
                'name' => 'Delhi', 'code' => 'AN-DEL', 'type' => Location::CLINIC, 'is_active' => true,
            ]);

            $doctor = Doctor::on('organization')->create([
                'name' => 'Dr. Priya Sharma', 'default_consultation_fee' => 500, 'is_active' => true,
            ]);

            DoctorSchedule::on('organization')->create([
                'doctor_id' => $doctor->id, 'location_id' => $gurgaon->id, 'name' => 'Morning OPD',
                'weekday' => Weekday::MONDAY, 'starts_at' => '10:00', 'ends_at' => '12:00',
                'slot_minutes' => 30, 'is_active' => true,
            ]);

            return [$gurgaon->id, $delhi->id, $doctor->id];
        });
    }

    private function fresh(): void
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();
    }

    /** The patient signs in to the app with a code, as the portal does. */
    private function patientToken(): string
    {
        $this->fresh();
        $this->withoutHeader('Origin')
            ->withHeaders([ResolveTenantFromHeader::HEADER => $this->organization->subdomain])
            ->postJson('/api/v1/tenant/portal/auth/code', ['phone' => self::PHONE])->assertOk();

        $this->fresh();

        return $this->withoutHeader('Origin')
            ->withHeaders([ResolveTenantFromHeader::HEADER => $this->organization->subdomain])
            ->postJson('/api/v1/tenant/portal/auth/verify', [
                'phone' => self::PHONE, 'code' => $this->sms->last, 'name' => 'Rahul Tiwari',
            ])->assertOk()->json('data.token');
    }

    private function asPatient(string $token): static
    {
        $this->fresh();

        return $this->withoutHeader('Origin')->withHeaders([
            ResolveTenantFromHeader::HEADER => $this->organization->subdomain,
            'Authorization' => "Bearer {$token}",
        ]);
    }

    private function bookInApp(string $token): int
    {
        return (int) $this->asPatient($token)->postJson('/api/v1/tenant/portal/appointments', [
            'doctor_id' => $this->doctor,
            'location_id' => $this->gurgaon,
            'appointment_date' => self::MONDAY,
            'slot_at' => '10:30',
        ])->assertCreated()->json('data.id');
    }

    private function switchOn(?string $ruleKey = AutomationRule::APPOINTMENT_CONFIRMED): void
    {
        $this->onTenant($this->organization, function () use ($ruleKey) {
            CommunicationChannel::forChannel(CommunicationChannel::PUSH)->forceFill(['is_connected' => true])->save();

            if ($ruleKey !== null) {
                AutomationRule::query()
                    ->where('channel', CommunicationChannel::PUSH)
                    ->where('event_key', $ruleKey)
                    ->update(['is_enabled' => true]);
            }
        });
    }

    private function pushTemplateId(string $name): int
    {
        return (int) $this->onTenant($this->organization, fn () => MessageTemplate::query()
            ->where('channel', CommunicationChannel::PUSH)
            ->where('name', $name)
            ->value('id'));
    }

    private function reword(int $templateId, int $locationId, string $content): TestResponse
    {
        return $this->putJson("/api/v1/tenant/communication/templates/{$templateId}/variants/{$locationId}", [
            'subject' => 'Booked at Gurgaon',
            'content' => $content,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Global: what every clinic starts with
    |--------------------------------------------------------------------------
    */

    public function test_every_clinic_starts_with_the_platform_templates_and_every_rule_off(): void
    {
        [$templates, $rules, $channel] = $this->onTenant($this->organization, fn () => [
            MessageTemplate::query()->where('channel', CommunicationChannel::PUSH)->get(),
            AutomationRule::query()->where('channel', CommunicationChannel::PUSH)->get(),
            CommunicationChannel::forChannel(CommunicationChannel::PUSH),
        ]);

        $this->assertCount(4, $templates);
        $this->assertTrue($templates->every(fn ($t) => $t->status === MessageTemplate::APPROVED && $t->is_active));

        $this->assertCount(5, $rules);
        $this->assertTrue($rules->every(fn ($r) => ! $r->is_enabled && $r->message_template_id !== null));

        $this->assertFalse($channel->is_connected);

        // And it reads like every other channel's screen.
        $this->signInAsOwner($this->organization);
        $this->getJson('/api/v1/tenant/communication/push/overview')
            ->assertOk()
            ->assertJsonPath('data.channel', CommunicationChannel::PUSH);
    }

    /*
    |--------------------------------------------------------------------------
    | Setting-based
    |--------------------------------------------------------------------------
    */

    public function test_nothing_reaches_a_phone_until_the_clinic_switches_it_on(): void
    {
        $token = $this->patientToken();

        Queue::fake();
        $this->bookInApp($token);

        Queue::assertNotPushed(SendPushMessage::class);
    }

    public function test_with_the_channel_and_the_rule_on_a_booking_wakes_the_patients_phone(): void
    {
        $this->switchOn();
        $token = $this->patientToken();

        $patientLogin = (int) $this->onTenant($this->organization, fn () => TenantUser::query()
            ->where('userable_type', Customer::class)->value('id'));

        Queue::fake();
        $visit = $this->bookInApp($token);

        Queue::assertPushed(SendPushMessage::class, fn (SendPushMessage $job) => $job->content['user_ids'] === [$patientLogin]
            && $job->content['title'] === 'Appointment confirmed'
            && $job->content['body'] === 'Hi Rahul Tiwari, your appointment with Dr. Priya Sharma is confirmed for 07 Sep 2026 at 10:30 AM.'
            && $job->content['data']['type'] === AutomationRule::APPOINTMENT_CONFIRMED);

        // In the same delivery log as email and WhatsApp.
        $log = $this->onTenant($this->organization, fn () => MessageLog::query()->where('channel', CommunicationChannel::PUSH)->sole());
        $this->assertSame(MessageLog::QUEUED, $log->status);
        $this->assertNotNull($log->customer_id);
    }

    public function test_the_rule_switched_off_keeps_the_phone_quiet_even_with_the_channel_on(): void
    {
        $this->switchOn(ruleKey: null);
        $token = $this->patientToken();

        Queue::fake();
        $this->bookInApp($token);

        Queue::assertNotPushed(SendPushMessage::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Organisation → Branch
    |--------------------------------------------------------------------------
    */

    public function test_a_branchs_own_wording_is_what_its_patients_get(): void
    {
        $this->switchOn();
        $this->signInAsOwner($this->organization);

        $this->reword($this->pushTemplateId('Appointment confirmed'), $this->gurgaon, 'Gurgaon says hi, {{patient_name}}! See you at {{time}}.')
            ->assertOk();

        $token = $this->patientToken();

        Queue::fake();
        $this->bookInApp($token);

        Queue::assertPushed(SendPushMessage::class, fn (SendPushMessage $job) => $job->content['title'] === 'Booked at Gurgaon'
            && $job->content['body'] === 'Gurgaon says hi, Rahul Tiwari! See you at 10:30 AM.');
    }

    public function test_a_branch_rewords_its_own_templates_and_nobody_elses(): void
    {
        $this->placeStaffAt($this->organization, $this->gurgaon);
        $this->setStaffCapabilities($this->organization, ['communication.view', 'communication.branch_templates']);
        $this->signInAsStaff($this->organization);

        $template = $this->pushTemplateId('Appointment confirmed');

        $this->reword($template, $this->gurgaon, 'Gurgaon wording.')->assertOk();
        $this->reword($template, $this->delhi, 'Delhi wording.')->assertStatus(403);
    }

    public function test_a_whatsapp_template_cannot_be_reworded_by_a_branch(): void
    {
        $this->signInAsOwner($this->organization);

        $whatsapp = (int) $this->onTenant($this->organization, fn () => MessageTemplate::query()->create([
            'channel' => CommunicationChannel::WHATSAPP, 'name' => 'Approved by Meta', 'category' => 'Utility',
            'content' => 'Hi {{patient_name}}', 'status' => MessageTemplate::APPROVED, 'is_active' => true,
        ])->id);

        $this->reword($whatsapp, $this->gurgaon, 'Something else')
            ->assertStatus(422)
            ->assertJsonValidationErrors('content');
    }

    public function test_a_variable_nothing_fills_is_refused_by_name(): void
    {
        $this->signInAsOwner($this->organization);

        $this->putJson('/api/v1/tenant/communication/templates/'.$this->pushTemplateId('Appointment confirmed'), [
            'name' => 'Appointment confirmed',
            'category' => 'Appointment',
            'subject' => 'Booked',
            'content' => 'Hi {{patinet_name}}',
        ])->assertStatus(422)->assertJsonValidationErrors(['content' => '{{patinet_name}} cannot be filled in']);
    }

    /*
    |--------------------------------------------------------------------------
    | Testing, and what the log says
    |--------------------------------------------------------------------------
    */

    public function test_a_test_notification_goes_to_the_senders_own_phone(): void
    {
        $this->signInAsOwner($this->organization);

        $owner = (int) $this->onTenant($this->organization, fn () => TenantUser::query()
            ->where('email', $this->organization->email)->value('id'));

        Queue::fake();

        $this->postJson('/api/v1/tenant/communication/push/test', [
            'message_template_id' => $this->pushTemplateId('Appointment reminder'),
        ])->assertOk();

        Queue::assertPushed(SendPushMessage::class, fn (SendPushMessage $job) => $job->content['user_ids'] === [$owner]);
        $this->assertTrue($this->onTenant($this->organization, fn () => (bool) MessageLog::query()->sole()->is_test));
    }

    public function test_the_log_says_sent_or_says_why_not(): void
    {
        $sender = new class implements PushSender
        {
            public ?PushMessage $last = null;

            public function send(PushMessage $message, array $tokens): PushResult
            {
                $this->last = $message;

                return new PushResult(sent: $tokens);
            }
        };

        [$withPhone, $without] = $this->onTenant($this->organization, function () {
            Queue::fake();

            $owner = TenantUser::query()->where('email', $this->organization->email)->firstOrFail();
            $staff = TenantUser::query()->where('email', self::STAFF_EMAIL)->firstOrFail();

            app(DeviceRegistry::class)->register($this->organization, $owner, [
                'device_id' => 'owner-phone', 'fcm_token' => 'fcm-owner', 'platform' => 'android',
            ]);

            $manager = app(PushManager::class);

            return [
                $manager->queueToUsers($this->organization, [$owner->id], 'Owner', 'Hi', 'There'),
                $manager->queueToUsers($this->organization, [$staff->id], 'Staff', 'Hi', 'There'),
            ];
        });

        foreach ([$withPhone, $without] as $log) {
            (new SendPushMessage($this->organization->id, $log->id, [
                'user_ids' => $log->is($withPhone)
                    ? [(int) $this->onTenant($this->organization, fn () => TenantUser::query()->where('email', $this->organization->email)->value('id'))]
                    : [(int) $this->onTenant($this->organization, fn () => TenantUser::query()->where('email', self::STAFF_EMAIL)->value('id'))],
                'title' => 'Hi',
                'body' => 'There',
            ]))->handle(app(TenantConnectionService::class), app(DeviceRegistry::class), $sender);
        }

        // The app shows a push only inside the clinic that sent it.
        $this->assertSame($this->organization->subdomain, $sender->last?->data['workspace']);

        $this->onTenant($this->organization, function () use ($withPhone, $without) {
            $this->assertSame(MessageLog::SENT, MessageLog::query()->find($withPhone->id)->status);

            $failed = MessageLog::query()->find($without->id);
            $this->assertSame(MessageLog::FAILED, $failed->status);
            $this->assertSame('No phone is registered for app notifications yet.', $failed->failure_reason);
        });
    }
}

/** Keeps the last code instead of texting it. */
class ChannelTestOtpSender implements OtpSender
{
    public ?string $last = null;

    public function send(string $phone, string $code, string $clinicName): void
    {
        $this->last = $code;
    }
}
