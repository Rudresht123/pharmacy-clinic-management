<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Broadcasting\DoctorQueueChannel;
use App\Events\Realtime\Queue\QueueConsultationCompleted;
use App\Events\Realtime\Queue\QueueConsultationStarted;
use App\Events\Realtime\Queue\QueuePatientCalled;
use App\Events\Realtime\Queue\QueuePatientCheckedIn;
use App\Events\Realtime\Queue\QueuePatientSkipped;
use App\Events\Realtime\Queue\QueuePaused;
use App\Events\Realtime\Queue\QueuePriorityChanged;
use App\Events\Realtime\Queue\QueueResumed;
use App\Events\Realtime\Queue\QueueUpdated;
use App\Models\Platform\Module;
use App\Models\Platform\Organization;
use App\Models\Platform\OrganizationModule;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Department;
use App\Models\Tenant\DepartmentQueueSetting;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorQueueSetting;
use App\Models\Tenant\DoctorSchedule;
use App\Models\Tenant\Location;
use App\Models\Tenant\PatientNotification;
use App\Models\Tenant\QueueCounter;
use App\Models\Tenant\QueueDisplay;
use App\Models\Tenant\QueueEvent;
use App\Models\Tenant\User;
use App\Services\Opd\BookingService;
use App\Services\Permissions\Permission;
use App\Services\Portal\Otp\OtpSender;
use App\Services\Queue\EstimatedWaitingTimeService;
use App\Services\Queue\QueueClock;
use App\Services\Tenancy\CurrentTenant;
use App\Services\Tenancy\TenantConnectionService;
use App\Support\Opd\Weekday;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\TenantTestCase;

/**
 * Four doctors, four rooms, one waiting room.
 *
 * The sentences that matter:
 *
 *   - every doctor's queue is their own: their numbers, their line, their calls.
 *   - nothing done to one doctor's queue moves or even wakes another's.
 *   - a television hears only the doctors it shows, and never a patient's name.
 *   - everything still flows through the visit workflow the rest of the OPD uses.
 */
class SmartQueueTest extends TenantTestCase
{
    use RefreshDatabase;

    /** A Monday morning, inside the sitting every doctor here has. */
    private const MONDAY = '2026-09-07';

    private const DOCTOR_PASSWORD = 'doctor-secret-1';

    private Organization $clinic;

    private int $branch;

    /** @var array<string, int> doctor ids by short name */
    private array $doctors = [];

    private int $patientCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');

        $this->travelTo(Carbon::parse(self::MONDAY.' 11:00'));
    }

    /*
    |--------------------------------------------------------------------------
    | The hospital
    |--------------------------------------------------------------------------
    */

    /** @param  array<string, string>  $doctors  short name => room */
    private function hospital(array $doctors = ['sharma' => '01', 'verma' => '02']): void
    {
        $this->clinic = $this->provisionOrganization('SQ');

        foreach (['appointments', 'queue', 'laboratory', 'prescriptions', 'medicines', 'pharmacy'] as $module) {
            $this->grantModule($this->clinic, $module);
        }

        $this->signInAsOwner($this->clinic);

        $this->branch = $this->onTenant($this->clinic, fn () => Location::on('organization')->create([
            'name' => 'Gurgaon',
            'code' => 'GGN',
            'type' => Location::CLINIC,
            'is_active' => true,
        ])->id);

        foreach ($doctors as $name => $room) {
            $doctor = $this->postJson('/api/v1/tenant/doctors', [
                'name' => 'Dr. '.ucfirst($name),
                'is_active' => true,
                'account' => ['email' => "{$name}@clinic.test", 'password' => self::DOCTOR_PASSWORD],
            ])->assertCreated()->json('data');

            $this->doctors[$name] = (int) $doctor['id'];

            $this->putJson("/api/v1/tenant/doctors/{$doctor['id']}/schedules", [
                'schedules' => [[
                    'location_id' => $this->branch,
                    'name' => 'OPD',
                    'weekday' => Weekday::MONDAY,
                    'starts_at' => '09:00',
                    'ends_at' => '17:00',
                    'slot_minutes' => 15,
                    'is_active' => true,
                ]],
            ])->assertOk();

            $this->putJson("/api/v1/tenant/queue/settings/doctors/{$doctor['id']}", [
                'location_id' => $this->branch,
                'room_no' => $room,
            ])->assertOk();
        }

        $this->signInAsReceptionist($this->clinic, $this->branch);
    }

    private function signInAsDoctor(string $name): void
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/tenant/auth/login', [
            'subdomain' => $this->clinic->subdomain,
            'email' => "{$name}@clinic.test",
            'password' => self::DOCTOR_PASSWORD,
        ])->assertOk();
    }

    private function patient(?string $name = null, ?string $phone = null): int
    {
        $this->patientCounter++;

        return $this->onTenant($this->clinic, fn () => Customer::on('organization')->create([
            'name' => $name ?? "Patient {$this->patientCounter}",
            'phone' => $phone ?? '98100'.str_pad((string) $this->patientCounter, 5, '0', STR_PAD_LEFT),
            'is_active' => true,
        ])->id);
    }

    /** @return array<string, mixed> the visit, as the API answers */
    private function walkIn(string $doctor, ?int $patient = null): array
    {
        return $this->postJson('/api/v1/tenant/appointments', [
            'customer_id' => $patient ?? $this->patient(),
            'doctor_id' => $this->doctors[$doctor],
            'location_id' => $this->branch,
            'appointment_date' => self::MONDAY,
            'type' => Appointment::WALK_IN,
        ])->assertCreated()->json('data');
    }

    private function callNext(string $doctor): TestResponse
    {
        return $this->postJson("/api/v1/tenant/queue/doctors/{$this->doctors[$doctor]}/call-next", [
            'location_id' => $this->branch,
        ]);
    }

    /** @return array<string, mixed> */
    private function card(string $doctor): array
    {
        return collect($this->getJson("/api/v1/tenant/queue/board?location_id={$this->branch}")->assertOk()->json('data.cards'))
            ->firstWhere('doctor_id', $this->doctors[$doctor]);
    }

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
    }

    /**
     * Make a display as the owner, and hand the desk back.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function display(array $values): array
    {
        $this->signInAsOwner($this->clinic);

        $made = $this->postJson('/api/v1/tenant/queue-displays', $values + [
            'location_id' => $this->branch,
        ])->assertCreated()->json('data');

        $this->signInAsReceptionist($this->clinic, $this->branch);

        return $made + ['token' => basename($made['url'])];
    }

    /*
    |--------------------------------------------------------------------------
    | 1–5. Independent queues, tokens, and who goes in which
    |--------------------------------------------------------------------------
    */

    public function test_every_doctor_has_their_own_token_run_and_prefix(): void
    {
        $this->hospital();

        $this->assertSame('A001', $this->walkIn('sharma')['token_label']);
        $this->assertSame('A002', $this->walkIn('sharma')['token_label']);

        // The next doctor starts their own run, whatever the branch has issued.
        $this->assertSame('B001', $this->walkIn('verma')['token_label']);
        $this->assertSame('A003', $this->walkIn('sharma')['token_label']);
    }

    public function test_a_prefix_can_be_configured_and_is_unique_at_the_branch(): void
    {
        $this->hospital();

        $this->signInAsOwner($this->clinic);

        $this->putJson("/api/v1/tenant/queue/settings/doctors/{$this->doctors['sharma']}", [
            'location_id' => $this->branch,
            'token_prefix' => 'car',
        ])->assertOk()->assertJsonPath('data.token_prefix', 'CAR');

        $this->putJson("/api/v1/tenant/queue/settings/doctors/{$this->doctors['verma']}", [
            'location_id' => $this->branch,
            'token_prefix' => 'CAR',
        ])->assertStatus(422);

        $this->signInAsReceptionist($this->clinic, $this->branch);

        $this->assertSame('CAR001', $this->walkIn('sharma')['token_label']);
    }

    /**
     * What SELECT FOR UPDATE is doing: holding one doctor's counter makes a
     * second connection wait for that doctor, and nobody else.
     */
    public function test_holding_one_doctors_counter_does_not_touch_another_doctors(): void
    {
        $this->hospital();

        $this->walkIn('sharma');
        $this->walkIn('verma');

        $this->onTenant($this->clinic, function () {
            $second = DB::connection('organization');

            $other = new \PDO(
                "pgsql:host={$second->getConfig('host')};port={$second->getConfig('port')};dbname={$second->getDatabaseName()}",
                $second->getConfig('username'),
                $second->getConfig('password'),
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
            );

            $sharma = (int) QueueCounter::query()->where('doctor_id', $this->doctors['sharma'])->value('id');
            $verma = (int) QueueCounter::query()->where('doctor_id', $this->doctors['verma'])->value('id');

            $second->transaction(function () use ($other, $sharma, $verma) {
                QueueCounter::query()->whereKey($sharma)->lockForUpdate()->first();

                $other->beginTransaction();

                // Dr. Verma's counter is free while Dr. Sharma's is held.
                $this->assertSame(1, $other->query("SELECT id FROM queue_counters WHERE id = {$verma} FOR UPDATE NOWAIT")->rowCount());

                $other->rollBack();

                // Dr. Sharma's is not: this is what makes two check-ins take turns.
                try {
                    $other->query("SELECT id FROM queue_counters WHERE id = {$sharma} FOR UPDATE NOWAIT");
                    $this->fail('The held counter should not have been available.');
                } catch (\PDOException $exception) {
                    $this->assertSame('55P03', $exception->getCode());
                }
            });
        });
    }

    public function test_two_receptionists_checking_in_one_patient_do_not_give_two_tokens(): void
    {
        $this->hospital();

        $booked = $this->postJson('/api/v1/tenant/appointments', [
            'customer_id' => $this->patient(),
            'doctor_id' => $this->doctors['sharma'],
            'location_id' => $this->branch,
            'appointment_date' => self::MONDAY,
            'type' => Appointment::BOOKED,
            'slot_at' => '14:00',
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/tenant/appointments/{$booked['id']}/check-in")->assertOk();
        $this->postJson("/api/v1/tenant/appointments/{$booked['id']}/check-in")->assertStatus(422);

        $this->assertSame('A002', $this->walkIn('sharma')['token_label']);
    }

    public function test_a_booked_patient_joins_their_own_doctors_queue_on_check_in(): void
    {
        $this->hospital();

        $booked = $this->postJson('/api/v1/tenant/appointments', [
            'customer_id' => $this->patient(),
            'doctor_id' => $this->doctors['verma'],
            'location_id' => $this->branch,
            'appointment_date' => self::MONDAY,
            'type' => Appointment::BOOKED,
            'slot_at' => '14:00',
        ])->assertCreated()->json('data');

        // No token until they arrive, and nobody picks a doctor again.
        $this->assertNull($booked['token_label']);

        $arrived = $this->postJson("/api/v1/tenant/appointments/{$booked['id']}/check-in")
            ->assertOk()->assertJsonPath('message', 'Token B001 issued')->json('data');

        $this->assertSame($this->doctors['verma'], $arrived['doctor_id']);
        $this->assertSame(1, $this->card('verma')['waiting']);
        $this->assertSame(0, $this->card('sharma')['waiting']);
    }

    public function test_a_walk_in_joins_the_queue_of_the_doctor_chosen(): void
    {
        $this->hospital();

        $this->walkIn('verma');

        $this->assertSame(['B001'], $this->card('verma')['next']);
        $this->assertSame([], $this->card('sharma')['next']);
    }

    public function test_tokens_restart_with_the_clinic_day(): void
    {
        $this->hospital();

        $this->walkIn('sharma');
        $this->walkIn('sharma');

        $this->travelTo(Carbon::parse('2026-09-14 11:00'));

        $next = $this->postJson('/api/v1/tenant/appointments', [
            'customer_id' => $this->patient(),
            'doctor_id' => $this->doctors['sharma'],
            'location_id' => $this->branch,
            'appointment_date' => '2026-09-14',
            'type' => Appointment::WALK_IN,
        ])->assertCreated()->json('data');

        $this->assertSame('A001', $next['token_label']);
    }

    public function test_a_tenants_midnight_decides_what_today_is(): void
    {
        $this->hospital();

        Organization::query()->whereKey($this->clinic->id)->update(['timezone' => 'America/New_York']);

        // Remembered per database for the life of the process; this test changes it midway.
        (new \ReflectionProperty(CurrentTenant::class, 'resolved'))->setValue(null, []);

        // 03:00 on Tuesday in Delhi is still Monday evening in New York.
        $this->travelTo(Carbon::parse('2026-09-08 03:00', 'Asia/Kolkata'));

        $this->assertSame(self::MONDAY, $this->onTenant($this->clinic, fn () => QueueClock::today()));
    }

    public function test_a_department_can_share_one_run_between_its_doctors(): void
    {
        $this->hospital();

        $this->onTenant($this->clinic, function () {
            $department = Department::on('organization')->create(['name' => 'Heart Clinic', 'is_active' => true]);

            Doctor::on('organization')
                ->whereIn('id', [$this->doctors['sharma'], $this->doctors['verma']])
                ->update(['department_id' => $department->id]);

            DepartmentQueueSetting::on('organization')->create([
                'location_id' => $this->branch,
                'department_id' => $department->id,
                'token_prefix' => 'C',
            ]);
        });

        $this->assertSame('C001', $this->walkIn('sharma')['token_label']);
        $this->assertSame('C002', $this->walkIn('verma')['token_label']);
    }

    /*
    |--------------------------------------------------------------------------
    | 6–7. Call next, per doctor
    |--------------------------------------------------------------------------
    */

    public function test_call_next_moves_only_that_doctors_queue(): void
    {
        $this->hospital();

        $this->walkIn('sharma');
        $this->walkIn('sharma');
        $this->walkIn('verma');

        $this->callNext('sharma')->assertOk()->assertJsonPath('data.token_label', 'A001');

        $sharma = $this->card('sharma');
        $verma = $this->card('verma');

        $this->assertSame('A001', $sharma['now_calling']);
        $this->assertSame(1, $sharma['waiting']);

        // Dr. Verma's card has not moved.
        $this->assertNull($verma['now_calling']);
        $this->assertSame(1, $verma['waiting']);
        $this->assertSame(['B001'], $verma['next']);
    }

    /** The acceptance scenario's heart: both at once, each on their own channel. */
    public function test_two_doctors_calling_at_once_each_update_only_their_own_channel(): void
    {
        $this->hospital();

        $this->walkIn('sharma');
        $this->walkIn('verma');

        Event::fake([QueuePatientCalled::class]);

        $this->callNext('sharma')->assertOk();
        $this->callNext('verma')->assertOk();

        Event::assertDispatchedTimes(QueuePatientCalled::class, 2);

        foreach (['sharma' => 'A001', 'verma' => 'B001'] as $doctor => $token) {
            Event::assertDispatched(QueuePatientCalled::class, function (QueuePatientCalled $event) use ($doctor, $token) {
                return $event->doctorId === $this->doctors[$doctor]
                    && $event->payload['token'] === $token
                    && $event->payload['card']['now_calling'] === $token
                    && $event->broadcastOn()->name === 'private-'.DoctorQueueChannel::name(
                        $this->clinic->uuid,
                        $this->branch,
                        $this->doctors[$doctor],
                    );
            });
        }
    }

    public function test_call_next_waits_for_the_room_to_be_ready(): void
    {
        $this->hospital();

        $this->walkIn('sharma');
        $this->walkIn('sharma');

        $this->callNext('sharma')->assertOk();

        // A001 is at the door. The doctor deals with them first.
        $this->callNext('sharma')->assertStatus(422)->assertJsonPath('message', 'Token A001 has been called and has not come in. Start, recall or skip them first.');
    }

    public function test_call_next_says_so_when_nobody_is_waiting(): void
    {
        $this->hospital();

        $this->callNext('sharma')->assertStatus(422)->assertJsonPath('message', 'Nobody is waiting for this doctor.');
    }

    public function test_a_video_visit_never_reaches_the_waiting_room_screens(): void
    {
        $this->hospital();

        $this->onTenant($this->clinic, function () {
            $visit = Appointment::on('organization')->create([
                'customer_id' => Customer::on('organization')->create(['name' => 'Remote', 'phone' => '9810099999', 'is_active' => true])->id,
                'doctor_id' => $this->doctors['sharma'],
                'location_id' => $this->branch,
                'appointment_date' => self::MONDAY,
                'type' => Appointment::BOOKED,
                'mode' => Appointment::MODE_VIDEO,
                'status' => Appointment::STATUS_BOOKED,
                'slot_at' => '12:00',
            ]);

            app(BookingService::class)->checkIn($visit);
        });

        $this->assertSame(0, $this->card('sharma')['waiting']);
        $this->callNext('sharma')->assertStatus(422);
    }

    /*
    |--------------------------------------------------------------------------
    | 8–9, 14–15. The televisions
    |--------------------------------------------------------------------------
    */

    public function test_the_central_tv_shows_every_doctor_and_a_room_tv_only_its_own(): void
    {
        $this->hospital(['sharma' => '01', 'verma' => '02', 'gupta' => '03', 'singh' => '04']);

        foreach (array_keys($this->doctors) as $doctor) {
            $this->walkIn($doctor);
        }

        $central = $this->display(['display_name' => 'Reception', 'display_type' => QueueDisplay::RECEPTION]);
        $roomOne = $this->display([
            'display_name' => 'Room 01',
            'display_type' => QueueDisplay::DOCTOR,
            'doctor_ids' => [$this->doctors['sharma']],
        ]);

        $wall = $this->getJson("/api/v1/display/{$central['token']}")->assertOk()->json('data');
        $this->assertCount(4, $wall['cards']);
        $this->assertCount(4, $wall['channels']);
        $this->assertEqualsCanonicalizing(
            ['01', '02', '03', '04'],
            array_column($wall['cards'], 'room'),
        );

        $room = $this->getJson("/api/v1/display/{$roomOne['token']}")->assertOk()->json('data');
        $this->assertCount(1, $room['cards']);
        $this->assertSame($this->doctors['sharma'], $room['cards'][0]['doctor_id']);
        $this->assertSame([
            'private-'.DoctorQueueChannel::name($this->clinic->uuid, $this->branch, $this->doctors['sharma']),
        ], $room['channels']);
    }

    public function test_a_display_is_signed_only_for_the_channels_it_shows(): void
    {
        $this->hospital();
        $this->useReverb();

        $roomOne = $this->display([
            'display_name' => 'Room 01',
            'display_type' => QueueDisplay::DOCTOR,
            'doctor_ids' => [$this->doctors['sharma']],
        ]);

        $own = 'private-'.DoctorQueueChannel::name($this->clinic->uuid, $this->branch, $this->doctors['sharma']);
        $next = 'private-'.DoctorQueueChannel::name($this->clinic->uuid, $this->branch, $this->doctors['verma']);

        $signed = $this->postJson("/api/v1/display/{$roomOne['token']}/broadcasting/auth", [
            'socket_id' => '1234.5678',
            'channel_name' => $own,
        ])->assertOk()->json('auth');

        $this->assertSame(
            'test-key:'.hash_hmac('sha256', "1234.5678:{$own}", 'test-secret'),
            $signed,
        );

        $this->postJson("/api/v1/display/{$roomOne['token']}/broadcasting/auth", [
            'socket_id' => '1234.5678',
            'channel_name' => $next,
        ])->assertForbidden();
    }

    /**
     * The clinic chose to name the patient being called, so they know it is them.
     * That is the whole of it: nobody waiting is ever named, and no phone number
     * reaches a screen in a public room.
     */
    public function test_a_display_names_only_the_patient_being_called(): void
    {
        $this->hospital();

        $this->walkIn('sharma', $this->patient('Rahul Unmistakable', '9812345678'));
        $this->walkIn('sharma', $this->patient('Sita Stillwaiting', '9812345679'));

        $central = $this->display(['display_name' => 'Reception', 'display_type' => QueueDisplay::RECEPTION]);

        Event::fake([QueuePatientCalled::class]);

        // Nobody called yet: tokens only.
        $before = $this->getJson("/api/v1/display/{$central['token']}")->assertOk()->getContent();

        $this->assertStringNotContainsString('Unmistakable', $before);
        $this->assertStringNotContainsString('Stillwaiting', $before);

        $this->callNext('sharma')->assertOk();

        $after = $this->getJson("/api/v1/display/{$central['token']}")->assertOk();

        $this->assertSame(
            'Rahul Unmistakable',
            collect($after->json('data.cards'))->firstWhere('doctor_id', $this->doctors['sharma'])['patient']['name'],
        );
        $this->assertStringNotContainsString('Stillwaiting', $after->getContent());
        $this->assertStringNotContainsString('98123456', $after->getContent());

        Event::assertDispatched(QueuePatientCalled::class, function (QueuePatientCalled $event) {
            $said = json_encode($event->broadcastWith());

            return str_contains($said, 'Unmistakable')
                && ! str_contains($said, 'Stillwaiting')
                && ! str_contains($said, '98123456');
        });
    }

    /** The doctor's own picture goes on the wall: it is the one face the room may see. */
    public function test_a_display_card_carries_the_doctors_photo(): void
    {
        $this->hospital();

        $central = $this->display(['display_name' => 'Reception', 'display_type' => QueueDisplay::RECEPTION]);

        $card = $this->getJson("/api/v1/display/{$central['token']}")->assertOk()->json('data.cards.0');

        $this->assertArrayHasKey('photo_url', $card);
        $this->assertArrayHasKey('doctor_name', $card);
    }

    public function test_only_the_hash_of_a_display_token_is_stored(): void
    {
        $this->hospital();

        $display = $this->display(['display_name' => 'Reception', 'display_type' => QueueDisplay::RECEPTION]);

        $stored = $this->onTenant($this->clinic, fn () => QueueDisplay::on('organization')->findOrFail($display['id']));

        $this->assertSame(hash('sha256', $display['token']), $stored->secure_display_token_hash);
        $this->assertStringNotContainsString($stored->secure_display_token_hash, $display['token']);

        // And no list ever shows the link again.
        $this->signInAsOwner($this->clinic);
        $listed = $this->getJson('/api/v1/tenant/queue-displays')->assertOk()->getContent();
        $this->assertStringNotContainsString($display['token'], $listed);
        $this->assertStringNotContainsString('secure_display_token_hash', $listed);
    }

    public function test_a_made_up_display_token_opens_nothing(): void
    {
        $this->hospital();

        $this->getJson('/api/v1/display/not-a-real-token-at-all-0000')->assertNotFound();

        // The right clinic and the wrong secret is no better.
        $this->getJson("/api/v1/display/{$this->clinic->uuid}-".str_repeat('x', 40))->assertNotFound();
    }

    public function test_a_revoked_display_stops_and_rotating_replaces_its_link(): void
    {
        $this->hospital();

        $display = $this->display(['display_name' => 'Reception', 'display_type' => QueueDisplay::RECEPTION]);

        $this->getJson("/api/v1/display/{$display['token']}")->assertOk();

        $this->signInAsOwner($this->clinic);
        $this->postJson("/api/v1/tenant/queue-displays/{$display['id']}/revoke")->assertOk();

        $this->getJson("/api/v1/display/{$display['token']}")
            ->assertForbidden()
            ->assertJsonPath('code', 'display_revoked');

        $rotated = $this->postJson("/api/v1/tenant/queue-displays/{$display['id']}/rotate")->assertOk()->json('data');
        $token = basename($rotated['url']);

        $this->assertNotSame($display['token'], $token);

        // The old link is gone for good; the new one opens.
        $this->getJson("/api/v1/display/{$display['token']}")->assertNotFound();
        $this->getJson("/api/v1/display/{$token}")->assertOk();
    }

    public function test_a_display_past_its_expiry_or_switched_off_refuses(): void
    {
        $this->hospital();

        $this->signInAsOwner($this->clinic);

        $made = $this->postJson('/api/v1/tenant/queue-displays', [
            'display_name' => 'Temporary',
            'display_type' => QueueDisplay::RECEPTION,
            'location_id' => $this->branch,
            'expires_at' => Carbon::parse(self::MONDAY.' 12:00')->toIso8601String(),
        ])->assertCreated()->json('data');

        $token = basename($made['url']);

        $this->getJson("/api/v1/display/{$token}")->assertOk();

        $this->travelTo(Carbon::parse(self::MONDAY.' 13:00'));

        $this->getJson("/api/v1/display/{$token}")->assertForbidden()->assertJsonPath('code', 'display_expired');

        $this->onTenant($this->clinic, fn () => QueueDisplay::on('organization')->whereKey($made['id'])
            ->update(['expires_at' => null, 'is_active' => false]));

        $this->getJson("/api/v1/display/{$token}")->assertForbidden()->assertJsonPath('code', 'display_inactive');
    }

    public function test_a_screen_reports_when_it_was_last_seen(): void
    {
        $this->hospital();

        $display = $this->display(['display_name' => 'Reception', 'display_type' => QueueDisplay::RECEPTION]);

        $this->assertFalse($display['is_online']);

        $this->getJson("/api/v1/display/{$display['token']}")->assertOk();

        $this->signInAsOwner($this->clinic);

        $this->getJson("/api/v1/tenant/queue-displays/{$display['id']}")
            ->assertOk()
            ->assertJsonPath('data.is_online', true);

        $this->travel(10)->minutes();

        $this->getJson("/api/v1/tenant/queue-displays/{$display['id']}")
            ->assertOk()
            ->assertJsonPath('data.is_online', false);
    }

    public function test_a_room_display_must_name_exactly_one_doctor_who_works_there(): void
    {
        $this->hospital();

        $this->signInAsOwner($this->clinic);

        $this->postJson('/api/v1/tenant/queue-displays', [
            'display_name' => 'Room',
            'display_type' => QueueDisplay::DOCTOR,
            'location_id' => $this->branch,
            'doctor_ids' => [$this->doctors['sharma'], $this->doctors['verma']],
        ])->assertUnprocessable()->assertJsonValidationErrors('doctor_ids');

        $elsewhere = $this->onTenant($this->clinic, fn () => Doctor::on('organization')->create([
            'name' => 'Dr. Elsewhere',
            'is_active' => true,
        ])->id);

        $this->postJson('/api/v1/tenant/queue-displays', [
            'display_name' => 'Room',
            'display_type' => QueueDisplay::DOCTOR,
            'location_id' => $this->branch,
            'doctor_ids' => [$elsewhere],
        ])->assertUnprocessable()->assertJsonValidationErrors('doctor_ids');
    }

    /*
    |--------------------------------------------------------------------------
    | 10. Priority
    |--------------------------------------------------------------------------
    */

    public function test_an_emergency_goes_ahead_of_everyone(): void
    {
        $this->hospital();

        $this->walkIn('sharma');
        $this->walkIn('sharma');
        $last = $this->walkIn('sharma');

        $this->postJson("/api/v1/tenant/queue/visits/{$last['id']}/priority", [
            'priority' => Appointment::PRIORITY_EMERGENCY,
            'reason' => 'Collapsed at the desk',
        ])->assertOk();

        $this->assertSame(['A003', 'A001', 'A002'], $this->card('sharma')['next']);

        $this->callNext('sharma')->assertOk()->assertJsonPath('data.token_label', 'A003');
    }

    public function test_an_urgent_patient_gets_a_head_start_not_a_free_pass(): void
    {
        $this->hospital();

        // Waiting since 09:00 — far longer than the head start.
        $this->travelTo(Carbon::parse(self::MONDAY.' 09:00'));
        $this->walkIn('sharma');

        // Waiting since 10:50.
        $this->travelTo(Carbon::parse(self::MONDAY.' 10:50'));
        $recent = $this->walkIn('sharma');

        $this->travelTo(Carbon::parse(self::MONDAY.' 11:00'));
        $urgent = $this->walkIn('sharma');

        $this->postJson("/api/v1/tenant/queue/visits/{$urgent['id']}/priority", [
            'priority' => Appointment::PRIORITY_URGENT,
            'reason' => 'Severe pain',
        ])->assertOk();

        // Ahead of who has waited ten minutes; behind who has waited two hours.
        $this->assertSame(['A001', 'A003', 'A002'], $this->card('sharma')['next']);
        $this->assertSame('A002', $recent['token_label']);
    }

    public function test_changing_priority_needs_a_reason_and_is_written_down(): void
    {
        $this->hospital();

        $visit = $this->walkIn('sharma');

        $this->postJson("/api/v1/tenant/queue/visits/{$visit['id']}/priority", [
            'priority' => Appointment::PRIORITY_URGENT,
        ])->assertUnprocessable()->assertJsonValidationErrors('reason');

        Event::fake([QueuePriorityChanged::class]);

        $this->postJson("/api/v1/tenant/queue/visits/{$visit['id']}/priority", [
            'priority' => Appointment::PRIORITY_URGENT,
            'reason' => 'Severe pain',
        ])->assertOk();

        Event::assertDispatched(QueuePriorityChanged::class);

        $logged = $this->onTenant($this->clinic, fn () => QueueEvent::on('organization')
            ->where('appointment_id', $visit['id'])
            ->where('event_type', QueueEvent::PRIORITY_CHANGED)
            ->firstOrFail());

        $this->assertSame('normal', $logged->metadata['from']);
        $this->assertSame('urgent', $logged->metadata['to']);
        $this->assertSame('Severe pain', $logged->metadata['reason']);
        $this->assertNotNull($logged->performed_by);
    }

    public function test_a_doctor_who_takes_no_urgent_patients_still_takes_emergencies(): void
    {
        $this->hospital();

        $this->onTenant($this->clinic, fn () => DoctorQueueSetting::on('organization')
            ->where('doctor_id', $this->doctors['sharma'])
            ->update(['allow_priority' => false]));

        $visit = $this->walkIn('sharma');

        $this->postJson("/api/v1/tenant/queue/visits/{$visit['id']}/priority", [
            'priority' => Appointment::PRIORITY_URGENT,
            'reason' => 'Severe pain',
        ])->assertStatus(422);

        $this->postJson("/api/v1/tenant/queue/visits/{$visit['id']}/priority", [
            'priority' => Appointment::PRIORITY_EMERGENCY,
            'reason' => 'Collapsed',
        ])->assertOk();
    }

    public function test_a_walk_in_can_arrive_as_an_emergency_with_a_reason(): void
    {
        $this->hospital();

        $this->postJson('/api/v1/tenant/appointments', [
            'customer_id' => $this->patient(),
            'doctor_id' => $this->doctors['sharma'],
            'location_id' => $this->branch,
            'appointment_date' => self::MONDAY,
            'type' => Appointment::WALK_IN,
            'queue_priority' => Appointment::PRIORITY_EMERGENCY,
        ])->assertUnprocessable()->assertJsonValidationErrors('priority_reason');

        $this->postJson('/api/v1/tenant/appointments', [
            'customer_id' => $this->patient(),
            'doctor_id' => $this->doctors['sharma'],
            'location_id' => $this->branch,
            'appointment_date' => self::MONDAY,
            'type' => Appointment::WALK_IN,
            'queue_priority' => Appointment::PRIORITY_EMERGENCY,
            'priority_reason' => 'Chest pain',
        ])->assertCreated()->assertJsonPath('data.queue_priority', 'emergency');
    }

    /*
    |--------------------------------------------------------------------------
    | 11. The consultation workflow
    |--------------------------------------------------------------------------
    */

    public function test_the_whole_workflow_from_waiting_to_completed(): void
    {
        $this->hospital();

        Event::fake([
            QueuePatientCheckedIn::class,
            QueuePatientCalled::class,
            QueueConsultationStarted::class,
            QueueConsultationCompleted::class,
        ]);

        $visit = $this->walkIn('sharma');
        Event::assertDispatched(QueuePatientCheckedIn::class);

        $this->callNext('sharma')->assertOk();
        Event::assertDispatched(QueuePatientCalled::class);

        $this->signInAsDoctor('sharma');

        $this->postJson("/api/v1/tenant/appointments/{$visit['id']}/consultation/start")->assertOk();
        Event::assertDispatched(QueueConsultationStarted::class);

        $this->putJson("/api/v1/tenant/appointments/{$visit['id']}/consultation", [
            'chief_complaint' => 'Headache',
        ])->assertOk();

        $this->postJson("/api/v1/tenant/appointments/{$visit['id']}/consultation/complete")->assertOk();
        Event::assertDispatched(QueueConsultationCompleted::class);

        $stored = $this->onTenant($this->clinic, fn () => Appointment::on('organization')->findOrFail($visit['id']));

        $this->assertNull($stored->queue_status);
        $this->assertSame(Appointment::CONSULT_COMPLETED, $stored->consultation_status);
        $this->assertNotNull($stored->called_at);
        $this->assertNotNull($stored->started_at);
        $this->assertNotNull($stored->completed_at);

        $this->assertSame(
            [QueueEvent::CHECKED_IN, QueueEvent::CALLED, QueueEvent::SERVING, QueueEvent::COMPLETED],
            $this->onTenant($this->clinic, fn () => QueueEvent::on('organization')
                ->where('appointment_id', $visit['id'])
                ->orderBy('id')
                ->pluck('event_type')
                ->all()),
        );
    }

    public function test_where_the_queue_calls_by_itself_finishing_calls_the_next_patient(): void
    {
        $this->hospital();

        $this->onTenant($this->clinic, fn () => DoctorQueueSetting::on('organization')
            ->where('doctor_id', $this->doctors['sharma'])
            ->update(['auto_call_enabled' => true]));

        $first = $this->walkIn('sharma');
        $this->walkIn('sharma');
        $this->walkIn('verma');

        $this->callNext('sharma')->assertOk();

        $this->signInAsDoctor('sharma');

        $this->postJson("/api/v1/tenant/appointments/{$first['id']}/consultation/start")->assertOk();
        $this->putJson("/api/v1/tenant/appointments/{$first['id']}/consultation", ['chief_complaint' => 'Cough'])->assertOk();
        $this->postJson("/api/v1/tenant/appointments/{$first['id']}/consultation/complete")->assertOk();

        $this->signInAsReceptionist($this->clinic, $this->branch);

        $this->assertSame('A002', $this->card('sharma')['now_calling']);
        // Nobody else's queue called by itself.
        $this->assertNull($this->card('verma')['now_calling']);
    }

    public function test_a_skipped_patient_keeps_their_token_and_can_be_recalled(): void
    {
        $this->hospital();

        $first = $this->walkIn('sharma');
        $this->walkIn('sharma');

        $this->callNext('sharma')->assertOk();

        Event::fake([QueuePatientSkipped::class, QueuePatientCalled::class]);

        $this->postJson("/api/v1/tenant/queue/visits/{$first['id']}/skip")->assertOk()
            ->assertJsonPath('data.queue_status', Appointment::QUEUE_SKIPPED);
        Event::assertDispatched(QueuePatientSkipped::class);

        // The doctor is not held up: A002 can be called.
        $this->callNext('sharma')->assertOk()->assertJsonPath('data.token_label', 'A002');

        $recalled = $this->postJson("/api/v1/tenant/queue/visits/{$first['id']}/recall")->assertOk()->json('data');

        $this->assertSame('A001', $recalled['token_label']);
        $this->assertSame(Appointment::QUEUE_CALLED, $recalled['queue_status']);

        // Told to the television, marked so it can say it a second time.
        Event::assertDispatched(QueuePatientCalled::class, fn (QueuePatientCalled $event) => $event->payload['announce']['recalled'] === true
            && $event->payload['token'] === 'A001');
    }

    public function test_somebody_who_never_came_in_can_be_recorded_as_gone(): void
    {
        $this->hospital();

        $visit = $this->walkIn('sharma');

        // Still waiting: nobody has even asked for them yet.
        $this->postJson("/api/v1/tenant/queue/visits/{$visit['id']}/absent")->assertStatus(422);

        $this->callNext('sharma')->assertOk();

        $this->postJson("/api/v1/tenant/queue/visits/{$visit['id']}/absent")->assertOk()
            ->assertJsonPath('data.status', Appointment::STATUS_NO_SHOW)
            ->assertJsonPath('data.queue_status', null);

        $this->assertSame(0, $this->card('sharma')['waiting']);
        $this->assertNull($this->card('sharma')['now_calling']);
    }

    public function test_the_old_desk_screen_moves_the_same_queue_and_tells_the_televisions(): void
    {
        $this->hospital();

        $visit = $this->walkIn('sharma');

        Event::fake([QueuePatientCalled::class, QueueUpdated::class]);

        $this->postJson("/api/v1/tenant/appointments/{$visit['id']}/call")->assertOk();
        Event::assertDispatched(QueuePatientCalled::class);

        $this->signInAsOwner($this->clinic);

        $this->postJson("/api/v1/tenant/appointments/{$visit['id']}/cancel")->assertOk();
        Event::assertDispatched(QueueUpdated::class);
    }

    public function test_a_rolled_back_change_is_never_announced(): void
    {
        $this->hospital();

        Event::fake([QueuePatientCheckedIn::class]);

        $this->onTenant($this->clinic, function () {
            DB::connection(TenantConnectionService::CONNECTION)->setTransactionManager(new DatabaseTransactionsManager);

            $visit = Appointment::on('organization')->create([
                'customer_id' => Customer::on('organization')->create(['name' => 'Roll Back', 'phone' => '9810088888', 'is_active' => true])->id,
                'doctor_id' => $this->doctors['sharma'],
                'location_id' => $this->branch,
                'appointment_date' => self::MONDAY,
                'type' => Appointment::WALK_IN,
                'status' => Appointment::STATUS_BOOKED,
            ]);

            try {
                DB::connection('organization')->transaction(function () use ($visit) {
                    app(BookingService::class)->checkIn($visit);

                    throw new \RuntimeException('Something went wrong after the check-in.');
                });
            } catch (\RuntimeException) {
                // Expected.
            }

            // The number went back with the transaction.
            $this->assertSame(0, (int) QueueCounter::query()->where('doctor_id', $this->doctors['sharma'])->value('last_number'));
        });

        Event::assertNotDispatched(QueuePatientCheckedIn::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Pause and resume
    |--------------------------------------------------------------------------
    */

    public function test_a_paused_queue_calls_nobody_until_resumed(): void
    {
        $this->hospital();

        $this->walkIn('sharma');
        $visit = $this->walkIn('sharma');

        Event::fake([QueuePaused::class, QueueResumed::class]);

        $this->postJson("/api/v1/tenant/queue/doctors/{$this->doctors['sharma']}/pause", [
            'location_id' => $this->branch,
            'reason' => 'Lunch',
        ])->assertOk();

        Event::assertDispatched(QueuePaused::class, fn (QueuePaused $event) => $event->payload['card']['paused'] === true);

        $this->callNext('sharma')->assertStatus(422);

        // Whichever screen it comes from.
        $this->postJson("/api/v1/tenant/appointments/{$visit['id']}/call")->assertStatus(422);

        // Nobody else's queue stopped.
        $this->assertFalse($this->card('verma')['paused']);

        $this->postJson("/api/v1/tenant/queue/doctors/{$this->doctors['sharma']}/resume", [
            'location_id' => $this->branch,
        ])->assertOk();

        Event::assertDispatched(QueueResumed::class);

        $this->callNext('sharma')->assertOk();
    }

    public function test_an_emergency_overrides_a_pause_and_a_busy_room(): void
    {
        $this->hospital();

        $this->walkIn('sharma');
        $emergency = $this->walkIn('sharma');

        $this->callNext('sharma')->assertOk();

        $this->postJson("/api/v1/tenant/queue/doctors/{$this->doctors['sharma']}/pause", [
            'location_id' => $this->branch,
        ])->assertOk();

        $this->postJson("/api/v1/tenant/queue/visits/{$emergency['id']}/priority", [
            'priority' => Appointment::PRIORITY_EMERGENCY,
            'reason' => 'Collapsed',
        ])->assertOk();

        $this->callNext('sharma')->assertOk()->assertJsonPath('data.token_label', 'A002');

        $logged = $this->onTenant($this->clinic, fn () => QueueEvent::on('organization')
            ->where('appointment_id', $emergency['id'])
            ->where('event_type', QueueEvent::CALLED)
            ->firstOrFail());

        $this->assertSame('emergency', $logged->metadata['override']);
    }

    public function test_a_pause_does_not_survive_into_the_next_day(): void
    {
        $this->hospital();

        $this->postJson("/api/v1/tenant/queue/doctors/{$this->doctors['sharma']}/pause", [
            'location_id' => $this->branch,
        ])->assertOk();

        $this->assertTrue($this->card('sharma')['paused']);

        $this->travelTo(Carbon::parse('2026-09-14 09:30'));

        $this->assertFalse($this->card('sharma')['paused']);
    }

    /*
    |--------------------------------------------------------------------------
    | Reassignment
    |--------------------------------------------------------------------------
    */

    public function test_moving_a_patient_gives_a_new_token_keeps_their_wait_and_is_written_down(): void
    {
        $this->hospital();

        $this->travelTo(Carbon::parse(self::MONDAY.' 09:30'));
        $moved = $this->walkIn('sharma');

        $this->travelTo(Carbon::parse(self::MONDAY.' 10:30'));
        $this->walkIn('verma');

        $this->travelTo(Carbon::parse(self::MONDAY.' 11:00'));

        $this->postJson("/api/v1/tenant/queue/visits/{$moved['id']}/reassign", [
            'doctor_id' => $this->doctors['verma'],
        ])->assertUnprocessable()->assertJsonValidationErrors('reason');

        Event::fake([QueueUpdated::class]);

        $after = $this->postJson("/api/v1/tenant/queue/visits/{$moved['id']}/reassign", [
            'doctor_id' => $this->doctors['verma'],
            'reason' => 'Dr. Sharma called away',
        ])->assertOk()->json('data');

        $this->assertSame('B002', $after['token_label']);

        // Both doctors' cards change, each on their own channel.
        Event::assertDispatched(QueueUpdated::class, fn (QueueUpdated $event) => $event->doctorId === $this->doctors['verma']);
        Event::assertDispatched(QueueUpdated::class, fn (QueueUpdated $event) => $event->doctorId === $this->doctors['sharma']);

        // They arrived before B001, and do not lose their place for moving.
        $this->assertSame(['B002', 'B001'], $this->card('verma')['next']);
        $this->assertSame(0, $this->card('sharma')['waiting']);

        $logged = $this->onTenant($this->clinic, fn () => QueueEvent::on('organization')
            ->where('appointment_id', $moved['id'])
            ->where('event_type', QueueEvent::REASSIGNED)
            ->firstOrFail());

        $this->assertSame('A001', $logged->metadata['from']['token']);
        $this->assertSame($this->doctors['sharma'], $logged->metadata['from']['doctor_id']);
        $this->assertSame('B002', $logged->metadata['to']['token']);
        $this->assertSame('Dr. Sharma called away', $logged->metadata['reason']);
    }

    public function test_a_booked_time_is_not_carried_to_the_new_doctors_diary(): void
    {
        $this->hospital();

        $booked = $this->postJson('/api/v1/tenant/appointments', [
            'customer_id' => $this->patient(),
            'doctor_id' => $this->doctors['sharma'],
            'location_id' => $this->branch,
            'appointment_date' => self::MONDAY,
            'type' => Appointment::BOOKED,
            'slot_at' => '14:00',
        ])->assertCreated()->json('data');

        // The other doctor has somebody at that very time.
        $this->postJson('/api/v1/tenant/appointments', [
            'customer_id' => $this->patient(),
            'doctor_id' => $this->doctors['verma'],
            'location_id' => $this->branch,
            'appointment_date' => self::MONDAY,
            'type' => Appointment::BOOKED,
            'slot_at' => '14:00',
        ])->assertCreated();

        $this->postJson("/api/v1/tenant/appointments/{$booked['id']}/check-in")->assertOk();

        $this->postJson("/api/v1/tenant/queue/visits/{$booked['id']}/reassign", [
            'doctor_id' => $this->doctors['verma'],
            'reason' => 'Patient preference',
        ])->assertOk()->assertJsonPath('data.slot_at', null);
    }

    public function test_only_somebody_still_waiting_can_be_moved_and_only_to_a_doctor_here(): void
    {
        $this->hospital();

        $visit = $this->walkIn('sharma');

        $this->postJson("/api/v1/tenant/queue/visits/{$visit['id']}/reassign", [
            'doctor_id' => $this->doctors['sharma'],
            'reason' => 'Nothing',
        ])->assertStatus(422);

        $elsewhere = $this->onTenant($this->clinic, fn () => Doctor::on('organization')->create([
            'name' => 'Dr. Elsewhere',
            'is_active' => true,
        ])->id);

        $this->postJson("/api/v1/tenant/queue/visits/{$visit['id']}/reassign", [
            'doctor_id' => $elsewhere,
            'reason' => 'Nothing',
        ])->assertStatus(422);

        $this->callNext('sharma')->assertOk();

        $this->signInAsDoctor('sharma');
        $this->postJson("/api/v1/tenant/appointments/{$visit['id']}/consultation/start")->assertOk();
        $this->signInAsReceptionist($this->clinic, $this->branch);

        $this->postJson("/api/v1/tenant/queue/visits/{$visit['id']}/reassign", [
            'doctor_id' => $this->doctors['verma'],
            'reason' => 'Too late',
        ])->assertStatus(422);
    }

    /*
    |--------------------------------------------------------------------------
    | 12. Waiting time
    |--------------------------------------------------------------------------
    */

    public function test_the_arithmetic_is_the_one_on_the_brief(): void
    {
        $estimates = new EstimatedWaitingTimeService;

        // 4 ahead at 10 minutes, 5 left of the current patient.
        $this->assertSame(45, $estimates->minutes(4, 10, 5));

        // 2 ahead at 8 minutes, 3 left.
        $this->assertSame(19, $estimates->minutes(2, 8, 3));

        $this->assertSame(30, $estimates->minutes(2, 10, -5, 10));
    }

    public function test_each_doctors_wait_is_worked_out_from_their_own_queue(): void
    {
        $this->hospital();

        $this->onTenant($this->clinic, function () {
            DoctorQueueSetting::on('organization')->where('doctor_id', $this->doctors['sharma'])
                ->update(['average_consultation_minutes' => 10]);
            DoctorQueueSetting::on('organization')->where('doctor_id', $this->doctors['verma'])
                ->update(['average_consultation_minutes' => 8]);
        });

        // Dr. Sharma: somebody in the room for 5 minutes, then 4 waiting.
        $first = $this->walkIn('sharma');
        $this->walkIn('sharma');
        $this->walkIn('sharma');
        $this->walkIn('sharma');
        $this->walkIn('sharma');

        // Dr. Verma: somebody in the room for 5 minutes, then 2 waiting.
        $theirs = $this->walkIn('verma');
        $this->walkIn('verma');
        $this->walkIn('verma');

        $this->callNext('sharma')->assertOk();
        $this->callNext('verma')->assertOk();

        $this->signInAsDoctor('sharma');
        $this->postJson("/api/v1/tenant/appointments/{$first['id']}/consultation/start")->assertOk();
        $this->signInAsDoctor('verma');
        $this->postJson("/api/v1/tenant/appointments/{$theirs['id']}/consultation/start")->assertOk();

        $this->travel(5)->minutes();

        $this->signInAsReceptionist($this->clinic, $this->branch);

        $this->assertSame(45, $this->card('sharma')['estimated_wait_minutes']);
        $this->assertSame(19, $this->card('verma')['estimated_wait_minutes']);
    }

    public function test_a_doctors_own_history_replaces_the_configured_average(): void
    {
        $this->hospital();

        $this->onTenant($this->clinic, function () {
            $patient = Customer::on('organization')->create(['name' => 'History', 'phone' => '9810077777', 'is_active' => true]);

            // Three finished consultations of 20 minutes each.
            foreach ([1, 2, 3] as $token) {
                (new Appointment)->forceFill([
                    'customer_id' => $patient->id,
                    'doctor_id' => $this->doctors['sharma'],
                    'location_id' => $this->branch,
                    'appointment_date' => '2026-08-31',
                    'type' => Appointment::WALK_IN,
                    'status' => Appointment::STATUS_COMPLETED,
                    'token_no' => $token,
                    'consultation_status' => Appointment::CONSULT_COMPLETED,
                    'started_at' => Carbon::parse('2026-08-31 10:00'),
                    'completed_at' => Carbon::parse('2026-08-31 10:20'),
                ])->save();
            }

            $this->assertSame(20, app(EstimatedWaitingTimeService::class)->averageMinutes($this->doctors['sharma'], $this->branch, 10));
            $this->assertSame(10, app(EstimatedWaitingTimeService::class)->averageMinutes($this->doctors['verma'], $this->branch, 10));
        });
    }

    /*
    |--------------------------------------------------------------------------
    | 13. Tenant isolation
    |--------------------------------------------------------------------------
    */

    public function test_one_clinics_display_token_cannot_read_anothers(): void
    {
        $this->hospital();

        $ours = $this->display(['display_name' => 'Reception', 'display_type' => QueueDisplay::RECEPTION]);

        $other = $this->provisionOrganization('SO');
        $this->grantModule($other, 'appointments');
        $this->grantModule($other, 'queue');

        // Our secret, under the other clinic's name: it is looked for in their
        // database, where it does not exist.
        $secret = substr($ours['token'], strrpos($ours['token'], '-') + 1);

        $this->getJson("/api/v1/display/{$other->uuid}-{$secret}")->assertNotFound();
    }

    public function test_staff_of_one_clinic_cannot_hear_anothers_queue_channel(): void
    {
        $this->hospital();

        $other = $this->provisionOrganization('SO');
        $this->grantModule($other, 'appointments');
        $this->grantModule($other, 'queue');

        $this->useReverb();
        app(BroadcastManager::class)->forgetDrivers();
        require base_path('routes/channels.php');

        $otherBranch = $this->onTenant($other, fn () => Location::on('organization')->create([
            'name' => 'Noida',
            'code' => 'NDA',
            'type' => Location::CLINIC,
            'is_active' => true,
        ])->id);

        $this->signInAsReceptionist($other, $otherBranch);

        $this->postJson('/api/v1/broadcasting/auth', [
            'channel_name' => 'private-'.DoctorQueueChannel::name($this->clinic->uuid, $this->branch, $this->doctors['sharma']),
            'socket_id' => '1234.5678',
        ])->assertForbidden();

        // Their own branch's channel, though, is theirs.
        $this->postJson('/api/v1/broadcasting/auth', [
            'channel_name' => 'private-'.DoctorQueueChannel::name($other->uuid, $otherBranch, 1),
            'socket_id' => '1234.5678',
        ])->assertOk();
    }

    public function test_queue_rows_live_only_in_the_clinics_own_database(): void
    {
        $this->hospital();

        $this->walkIn('sharma');

        $other = $this->provisionOrganization('SO');

        $this->assertSame(1, $this->onTenant($this->clinic, fn () => QueueEvent::on('organization')->where('event_type', QueueEvent::CHECKED_IN)->count()));
        $this->assertSame(0, $this->onTenant($other, fn () => QueueEvent::on('organization')->count()));
        $this->assertSame(0, $this->onTenant($other, fn () => QueueCounter::on('organization')->count()));
    }

    /*
    |--------------------------------------------------------------------------
    | 16. Who may do what
    |--------------------------------------------------------------------------
    */

    public function test_a_doctor_drives_their_own_queue_and_nobody_elses(): void
    {
        $this->hospital();

        $mine = $this->walkIn('sharma');
        $this->walkIn('verma');

        $this->signInAsDoctor('sharma');

        $this->callNext('verma')->assertForbidden();
        $this->postJson("/api/v1/tenant/queue/doctors/{$this->doctors['verma']}/pause", [
            'location_id' => $this->branch,
        ])->assertForbidden();
        $this->getJson("/api/v1/tenant/queue/doctors/{$this->doctors['verma']}?location_id={$this->branch}")
            ->assertForbidden();

        $this->callNext('sharma')->assertOk();

        $this->getJson("/api/v1/tenant/queue/doctors/{$this->doctors['sharma']}?location_id={$this->branch}")
            ->assertOk()
            ->assertJsonPath('data.called.0.id', $mine['id']);
    }

    public function test_an_explicit_grant_lets_one_doctor_run_anothers_queue(): void
    {
        $this->hospital();

        $this->walkIn('verma');

        $this->signInAsDoctor('sharma');
        $this->callNext('verma')->assertForbidden();

        $this->onTenant($this->clinic, function () {
            $user = User::on('organization')
                ->where('userable_type', Doctor::class)
                ->where('userable_id', $this->doctors['sharma'])
                ->firstOrFail();

            $role = $user->membershipAt($this->branch)?->role ?? $user->permissionRole;
            $role->syncCapabilities([...$role->capabilityKeys(), 'queue.any_doctor']);
        });

        $this->app->forgetInstance(Permission::class);
        $this->signInAsDoctor('sharma');

        $this->callNext('verma')->assertOk();
    }

    public function test_the_desk_drives_every_queue_at_its_branch(): void
    {
        $this->hospital();

        $this->walkIn('sharma');
        $this->walkIn('verma');

        $this->callNext('sharma')->assertOk();
        $this->callNext('verma')->assertOk();
    }

    public function test_somebody_without_the_tick_is_refused(): void
    {
        $this->hospital();

        $visit = $this->walkIn('sharma');

        $this->placeStaffAt($this->clinic, $this->branch);
        $this->setStaffCapabilities($this->clinic, ['appointments.view']);
        $this->signInAsStaff($this->clinic);

        $this->getJson("/api/v1/tenant/queue/board?location_id={$this->branch}")->assertForbidden();
        $this->callNext('sharma')->assertForbidden();
        $this->postJson("/api/v1/tenant/queue/visits/{$visit['id']}/skip")->assertForbidden();
        $this->postJson("/api/v1/tenant/queue/visits/{$visit['id']}/priority", [
            'priority' => 'urgent',
            'reason' => 'x',
        ])->assertForbidden();
        $this->postJson("/api/v1/tenant/queue/visits/{$visit['id']}/reassign", [
            'doctor_id' => $this->doctors['verma'],
            'reason' => 'x',
        ])->assertForbidden();
        $this->getJson('/api/v1/tenant/queue-displays')->assertForbidden();
    }

    public function test_the_account_holder_may_watch_the_queue_but_not_work_it(): void
    {
        $this->hospital();

        $visit = $this->walkIn('sharma');

        $this->signInAsOwner($this->clinic);

        $this->getJson("/api/v1/tenant/queue/board?location_id={$this->branch}")->assertOk();
        $this->callNext('sharma')->assertForbidden();
        $this->postJson("/api/v1/tenant/queue/visits/{$visit['id']}/priority", [
            'priority' => 'urgent',
            'reason' => 'x',
        ])->assertForbidden();
    }

    public function test_the_clinics_own_arrangement_can_keep_calling_with_the_desk(): void
    {
        $this->hospital();

        $this->walkIn('sharma');

        $this->signInAsOwner($this->clinic);
        $this->putJson('/api/v1/tenant/appointment-settings', [
            'check_in_by' => 'desk',
            'call_by' => 'desk',
            'consult_start_by' => 'doctor',
        ])->assertOk();

        $this->signInAsDoctor('sharma');
        $this->callNext('sharma')->assertForbidden();

        $this->signInAsReceptionist($this->clinic, $this->branch);
        $this->callNext('sharma')->assertOk();
    }

    public function test_a_branch_a_person_does_not_work_at_is_refused(): void
    {
        $this->hospital();

        $elsewhere = $this->onTenant($this->clinic, fn () => Location::on('organization')->create([
            'name' => 'Noida',
            'code' => 'NDA',
            'type' => Location::CLINIC,
            'is_active' => true,
        ])->id);

        $this->getJson("/api/v1/tenant/queue/board?location_id={$elsewhere}")->assertForbidden();

        $this->postJson("/api/v1/tenant/queue/doctors/{$this->doctors['sharma']}/call-next", [
            'location_id' => $elsewhere,
        ])->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Sold on its own
    |--------------------------------------------------------------------------
    */

    /** Take the module back, and start the next request from a clean answer. */
    private function withoutTheModule(): void
    {
        $this->grantModule($this->clinic, 'queue');

        OrganizationModule::query()
            ->where('organization_id', $this->clinic->id)
            ->where('module_id', Module::where('key', 'queue')->value('id'))
            ->update(['is_enabled' => false]);

        $this->app->forgetInstance(Permission::class);
        $this->signInAsReceptionist($this->clinic, $this->branch);
    }

    public function test_without_the_module_every_door_is_shut(): void
    {
        $this->hospital();

        $visit = $this->walkIn('sharma');
        $display = $this->display(['display_name' => 'Reception', 'display_type' => QueueDisplay::RECEPTION]);

        $this->withoutTheModule();

        $this->getJson("/api/v1/tenant/queue/board?location_id={$this->branch}")->assertForbidden();
        $this->callNext('sharma')->assertForbidden();
        $this->postJson("/api/v1/tenant/queue/visits/{$visit['id']}/skip")->assertForbidden();

        $this->getJson("/api/v1/display/{$display['token']}")
            ->assertForbidden()
            ->assertJsonPath('code', 'display_unavailable');

        $this->signInAsOwner($this->clinic);

        $this->getJson('/api/v1/tenant/queue-displays')->assertForbidden();
        $this->getJson("/api/v1/tenant/queue/settings?location_id={$this->branch}")->assertForbidden();
    }

    public function test_without_the_module_the_opd_works_exactly_as_it_did(): void
    {
        $this->hospital();
        $this->withoutTheModule();

        $first = $this->walkIn('sharma');
        $second = $this->walkIn('verma');

        // One plain run across the branch, whoever the doctor is.
        $this->assertSame(1, $first['token_no']);
        $this->assertSame('1', $first['token_label']);
        $this->assertSame(2, $second['token_no']);
        $this->assertSame('2', $second['token_label']);

        // The desk's own screen still calls, and nothing is announced or written.
        Event::fake([QueuePatientCalled::class]);

        $this->postJson("/api/v1/tenant/appointments/{$first['id']}/call")->assertOk();

        Event::assertNotDispatched(QueuePatientCalled::class);

        $this->assertSame(0, $this->onTenant($this->clinic, fn () => QueueEvent::on('organization')
            ->where('appointment_id', $first['id'])
            ->count()));
        $this->assertSame(0, $this->onTenant($this->clinic, fn () => PatientNotification::on('organization')
            ->where('type', 'token_called')
            ->count()));
    }

    public function test_a_role_seeded_before_buying_is_ready_the_day_the_module_is_turned_on(): void
    {
        $this->hospital();
        $this->withoutTheModule();

        $this->getJson("/api/v1/tenant/queue/board?location_id={$this->branch}")->assertForbidden();

        $this->grantModule($this->clinic, 'queue');
        $this->app->forgetInstance(Permission::class);
        $this->signInAsReceptionist($this->clinic, $this->branch);

        $this->getJson("/api/v1/tenant/queue/board?location_id={$this->branch}")->assertOk();
    }

    public function test_a_tick_taken_off_a_role_really_is_taken_off(): void
    {
        $this->hospital();

        $this->walkIn('sharma');
        $visit = $this->walkIn('sharma');

        $this->placeStaffAt($this->clinic, $this->branch);

        // Sees the board and nothing else.
        $this->setStaffCapabilities($this->clinic, ['queue.board']);
        $this->signInAsStaff($this->clinic);

        $this->getJson("/api/v1/tenant/queue/board?location_id={$this->branch}")->assertOk();
        $this->callNext('sharma')->assertForbidden();
        $this->postJson("/api/v1/tenant/queue/visits/{$visit['id']}/priority", [
            'priority' => 'urgent',
            'reason' => 'x',
        ])->assertForbidden();

        // May run a queue, and still not move anybody up it.
        $this->setStaffCapabilities($this->clinic, ['queue.board', 'queue.control']);
        $this->app->forgetInstance(Permission::class);
        $this->signInAsStaff($this->clinic);

        $this->callNext('sharma')->assertOk();
        $this->postJson("/api/v1/tenant/queue/visits/{$visit['id']}/priority", [
            'priority' => 'urgent',
            'reason' => 'x',
        ])->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | The board and history
    |--------------------------------------------------------------------------
    */

    public function test_the_board_shows_every_doctor_and_can_be_narrowed_to_a_department(): void
    {
        $this->hospital(['sharma' => '01', 'verma' => '02', 'gupta' => '03']);

        $department = $this->onTenant($this->clinic, function () {
            $department = Department::on('organization')->create(['name' => 'Heart Clinic', 'is_active' => true]);

            Doctor::on('organization')->whereKey($this->doctors['verma'])->update(['department_id' => $department->id]);

            return $department->id;
        });

        $all = $this->getJson("/api/v1/tenant/queue/board?location_id={$this->branch}")->assertOk()->json('data');

        $this->assertCount(3, $all['cards']);
        $this->assertCount(3, $all['channels']);

        $cardiology = $this->getJson("/api/v1/tenant/queue/board?location_id={$this->branch}&department_id={$department}")
            ->assertOk()->json('data');

        $this->assertSame([$this->doctors['verma']], array_column($cardiology['cards'], 'doctor_id'));
    }

    public function test_a_doctor_on_leave_is_not_on_the_board_unless_patients_are_waiting(): void
    {
        $this->hospital();

        $this->onTenant($this->clinic, fn () => DoctorSchedule::on('organization')
            ->where('doctor_id', $this->doctors['verma'])
            ->delete());

        Cache::flush();

        $ids = array_column(
            $this->getJson("/api/v1/tenant/queue/board?location_id={$this->branch}")->assertOk()->json('data.cards'),
            'doctor_id',
        );

        $this->assertNotContains($this->doctors['verma'], $ids);
    }

    public function test_the_history_says_who_did_what(): void
    {
        $this->hospital();

        $visit = $this->walkIn('sharma');
        $this->callNext('sharma')->assertOk();
        $this->postJson("/api/v1/tenant/queue/visits/{$visit['id']}/skip", ['reason' => 'Not answering'])->assertOk();

        $rows = $this->getJson("/api/v1/tenant/queue/doctors/{$this->doctors['sharma']}/history?location_id={$this->branch}")
            ->assertOk()->json('data');

        $this->assertSame(
            [QueueEvent::SKIPPED, QueueEvent::CALLED, QueueEvent::CHECKED_IN],
            array_slice(array_column($rows, 'event_type'), 0, 3),
        );
        $this->assertSame('Reception', $rows[0]['actor_name']);
        $this->assertSame('Not answering', $rows[0]['metadata']['reason']);
        $this->assertSame('A001', $rows[0]['token']);
    }

    /*
    |--------------------------------------------------------------------------
    | The patient's phone
    |--------------------------------------------------------------------------
    */

    private function signInPatient(string $phone, string $name): string
    {
        $sms = new class implements OtpSender
        {
            public ?string $last = null;

            public function send(string $phone, string $code, string $clinicName): void
            {
                $this->last = $code;
            }
        };

        $this->app->instance(OtpSender::class, $sms);

        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $this->withHeaders(['X-Organization' => $this->clinic->subdomain])
            ->postJson('/api/v1/tenant/portal/auth/code', ['phone' => $phone])
            ->assertOk();

        return $this->withHeaders(['X-Organization' => $this->clinic->subdomain])
            ->postJson('/api/v1/tenant/portal/auth/verify', ['phone' => $phone, 'code' => $sms->last, 'name' => $name])
            ->assertOk()
            ->json('data.token');
    }

    private function asPatient(string $token): static
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        return $this->withHeaders([
            'X-Organization' => $this->clinic->subdomain,
            'Authorization' => "Bearer {$token}",
        ]);
    }

    public function test_a_patient_sees_their_place_and_nobody_elses_visit(): void
    {
        $this->hospital();

        $token = $this->signInPatient('9876500011', 'Rahul Tiwari');
        $stranger = $this->signInPatient('9876500022', 'Someone Else');

        $mine = $this->onTenant($this->clinic, fn () => Customer::on('organization')->where('phone', '9876500011')->value('id'));
        $theirs = $this->onTenant($this->clinic, fn () => Customer::on('organization')->where('phone', '9876500022')->value('id'));

        $this->signInAsReceptionist($this->clinic, $this->branch);

        $this->walkIn('sharma');
        $this->walkIn('sharma', $theirs);
        $visit = $this->walkIn('sharma', $mine);

        $this->onTenant($this->clinic, fn () => DoctorQueueSetting::on('organization')
            ->where('doctor_id', $this->doctors['sharma'])
            ->update(['average_consultation_minutes' => 10]));

        $status = $this->asPatient($token)->getJson('/api/v1/tenant/portal/queue')->assertOk()->json('data');

        $this->assertCount(1, $status);
        $this->assertSame($visit['id'], $status[0]['appointment_id']);
        $this->assertSame('A003', $status[0]['token']);
        $this->assertSame('01', $status[0]['room']);
        $this->assertSame('waiting', $status[0]['queue_status']);
        $this->assertSame(2, $status[0]['patients_ahead']);
        $this->assertSame(20, $status[0]['estimated_wait_minutes']);
        $this->assertTrue($status[0]['estimate_is_approximate']);

        // Somebody else's visit does not exist as far as they are concerned.
        $this->asPatient($stranger)->getJson("/api/v1/tenant/portal/queue/{$visit['id']}")->assertNotFound();
        $this->asPatient($token)->getJson("/api/v1/tenant/portal/queue/{$visit['id']}")->assertOk();
    }

    public function test_a_patient_is_told_when_their_token_is_called(): void
    {
        $this->hospital();

        $patient = $this->patient('Asha Rane');
        $this->walkIn('sharma', $patient);

        $this->callNext('sharma')->assertOk();

        $note = $this->onTenant($this->clinic, fn () => PatientNotification::on('organization')
            ->where('customer_id', $patient)
            ->where('type', 'token_called')
            ->firstOrFail());

        $this->assertStringContainsString('A001', $note->body);
        $this->assertStringContainsString('Room 01', $note->body);
    }

    /*
    |--------------------------------------------------------------------------
    | The acceptance scenario, as far as a test can run it
    |--------------------------------------------------------------------------
    */

    public function test_four_doctors_run_side_by_side_under_one_television(): void
    {
        $this->hospital(['sharma' => '01', 'verma' => '02', 'gupta' => '03', 'singh' => '04']);

        foreach (array_keys($this->doctors) as $doctor) {
            $this->walkIn($doctor);
            $this->walkIn($doctor);
        }

        $central = $this->display(['display_name' => 'Reception', 'display_type' => QueueDisplay::RECEPTION]);

        Event::fake([QueuePatientCalled::class]);

        $this->callNext('sharma')->assertOk();
        $this->callNext('verma')->assertOk();

        Event::assertDispatched(QueuePatientCalled::class, fn ($event) => $event->doctorId === $this->doctors['sharma'] && $event->payload['token'] === 'A001');
        Event::assertDispatched(QueuePatientCalled::class, fn ($event) => $event->doctorId === $this->doctors['verma'] && $event->payload['token'] === 'B001');
        Event::assertNotDispatched(QueuePatientCalled::class, fn ($event) => $event->doctorId === $this->doctors['gupta']);
        Event::assertNotDispatched(QueuePatientCalled::class, fn ($event) => $event->doctorId === $this->doctors['singh']);

        $cards = collect($this->getJson("/api/v1/display/{$central['token']}")->assertOk()->json('data.cards'))
            ->keyBy('doctor_id');

        $this->assertSame('A001', $cards[$this->doctors['sharma']]['now_calling']);
        $this->assertSame(['A002'], $cards[$this->doctors['sharma']]['next']);
        $this->assertSame('B001', $cards[$this->doctors['verma']]['now_calling']);
        $this->assertSame(['B002'], $cards[$this->doctors['verma']]['next']);
        $this->assertNull($cards[$this->doctors['gupta']]['now_calling']);
        $this->assertSame(2, $cards[$this->doctors['singh']]['waiting']);
    }
}
