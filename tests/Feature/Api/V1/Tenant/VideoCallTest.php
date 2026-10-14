<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Events\Realtime\VideoCallSignalled;
use App\Events\Realtime\VideoCallUpdated;
use App\Jobs\SendPushMessage;
use App\Models\Platform\Module;
use App\Models\Platform\Organization;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Consultation;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Location;
use App\Models\Tenant\User as TenantUser;
use App\Models\Tenant\VideoCall;
use App\Models\Tenant\VideoCallSignal;
use App\Services\Tenant\PatientAccountProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TenantTestCase;

/**
 * Video consultations, from both apps.
 *
 * The walls first: only a visit booked as video can be rung, only by its own
 * doctor, only on its day, and each side reaches only its own calls. Then the
 * call itself: answering brings the visit into the room the way the desk
 * would, the handshake reaches only the other phone, and hanging up clears it.
 */
class VideoCallTest extends TenantTestCase
{
    use RefreshDatabase;

    private const STAFF = '/api/v1/tenant';

    private const PORTAL = '/api/v1/tenant/portal';

    private const OFFER = "v=0\r\no=- 4611 2 IN IP4 127.0.0.1\r\ns=-\r\nt=0 0\r\nm=video 9 UDP/TLS/RTP/SAVPF 96\r\n";

    private Organization $organization;

    private int $branchId;

    /** @var array<string, int> doctor key => doctor id */
    private array $doctors = [];

    /** @var array<string, string> doctor key => token */
    private array $doctorTokens = [];

    private string $defaultGuard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->defaultGuard = (string) config('auth.defaults.guard');

        $this->artisan('modules:sync');

        $this->organization = $this->provisionOrganization('VID');
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

        foreach (['anjali' => 'Dr. Anjali Sharma', 'vikram' => 'Dr. Vikram Rao'] as $key => $name) {
            $this->doctors[$key] = $this->postJson('/api/v1/tenant/doctors', [
                'name' => $name,
                'specialisation' => 'General Physician',
                'is_active' => true,
                'account' => ['email' => "{$key}@clinic.test", 'password' => 'doctor-secret-1'],
            ])->assertCreated()->json('data.id');
        }

        foreach (array_keys($this->doctors) as $key) {
            $this->fresh();

            $this->doctorTokens[$key] = $this->postJson('/api/v1/tenant/auth/token', [
                'subdomain' => $this->organization->subdomain,
                'email' => "{$key}@clinic.test",
                'password' => 'doctor-secret-1',
            ])->assertOk()->json('data.token');
        }

        Queue::fake();
        Event::fake([VideoCallUpdated::class, VideoCallSignalled::class]);
    }

    /**
     * As a new request would find the app.
     *
     * `auth:tenant-api` makes the token guard the default for the rest of the
     * process, and in a test the process is the whole test. Left that way,
     * Sanctum's session check asks for a user before the tenant is connected,
     * and a request after onTenant() — which disconnects — looks for tokens in
     * no database at all. Every real request starts from the default guard.
     */
    private function fresh(): void
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->app['auth']->setDefaultDriver($this->defaultGuard);
    }

    private function as(string $token): static
    {
        $this->fresh();

        return $this->withHeaders([
            'X-Organization' => $this->organization->subdomain,
            'Authorization' => "Bearer {$token}",
        ]);
    }

    private function asDoctor(string $key = 'anjali'): static
    {
        return $this->as($this->doctorTokens[$key]);
    }

    /**
     * A patient with an app login and one visit with a doctor.
     *
     * @return array{customer: int, visit: int, token: string, login: int}
     */
    private function patient(
        string $name = 'Rahul Tiwari',
        string $mode = Appointment::MODE_VIDEO,
        string $doctor = 'anjali',
        ?string $date = null,
        bool $withLogin = true,
    ): array {
        return $this->onTenant($this->organization, function () use ($name, $mode, $doctor, $date, $withLogin) {
            $customer = Customer::on('organization')->create([
                'name' => $name,
                'phone' => '98'.random_int(10000000, 99999999),
                'is_active' => true,
            ]);

            $visit = Appointment::on('organization')->create([
                'customer_id' => $customer->id,
                'doctor_id' => $this->doctors[$doctor],
                'location_id' => $this->branchId,
                'appointment_date' => $date ?? now()->toDateString(),
                'type' => Appointment::BOOKED,
                'mode' => $mode,
                'status' => Appointment::STATUS_BOOKED,
                'slot_at' => sprintf('%02d:%02d', 9 + intdiv($customer->id, 4), ($customer->id % 4) * 15),
            ]);

            $login = $withLogin ? app(PatientAccountProvisioner::class)->open($customer, ['appointments']) : null;

            return [
                'customer' => $customer->id,
                'visit' => $visit->id,
                'token' => $login?->createToken('Patient phone')->plainTextToken ?? '',
                'login' => (int) $login?->id,
            ];
        });
    }

    private function ring(int $visit, string $doctor = 'anjali')
    {
        return $this->asDoctor($doctor)->postJson(self::STAFF."/appointments/{$visit}/video-call");
    }

    private function visit(int $id): Appointment
    {
        return $this->onTenant($this->organization, fn () => Appointment::query()->findOrFail($id));
    }

    private function videoCall(int $id): VideoCall
    {
        return $this->onTenant($this->organization, fn () => VideoCall::query()->findOrFail($id));
    }

    private function doctorLogin(string $key = 'anjali'): int
    {
        return $this->onTenant($this->organization, fn () => (int) TenantUser::query()
            ->where('email', "{$key}@clinic.test")
            ->value('id'));
    }

    /*
    |--------------------------------------------------------------------------
    | The call
    |--------------------------------------------------------------------------
    */

    public function test_the_doctor_rings_the_patient_answers_and_the_visit_comes_into_the_room(): void
    {
        $patient = $this->patient();

        $callId = $this->ring($patient['visit'])
            ->assertOk()
            ->assertJsonPath('data.status', VideoCall::RINGING)
            ->assertJsonPath('data.side', 'doctor')
            ->assertJsonPath('data.appointment_id', $patient['visit'])
            ->assertJsonPath('data.patient.name', 'Rahul Tiwari')
            ->assertJsonPath('data.doctor.name', 'Dr. Anjali Sharma')
            // Where the phones may route media, and the thread for chatting during it.
            ->assertJsonPath('data.ice_servers.0.urls.0', 'stun:stun.l.google.com:19302')
            ->assertJsonStructure(['data' => ['conversation_id', 'ring_expires_at']])
            ->json('data.id');

        // Every phone the patient is signed in on hears it ring, and is woken by push.
        Event::assertDispatched(VideoCallUpdated::class, fn (VideoCallUpdated $event) => $event->userIds === [$patient['login']]
            && $event->call['status'] === VideoCall::RINGING
            && $event->broadcastOn()[0]->name === "private-org.{$this->organization->uuid}.user.{$patient['login']}.calls");
        Queue::assertPushed(SendPushMessage::class);

        // The patient's app, opening, finds the ring and the channel for the next one.
        $this->as($patient['token'])->getJson(self::PORTAL.'/video-calls/current')
            ->assertOk()
            ->assertJsonPath('data.call.id', $callId)
            ->assertJsonPath('data.call.side', 'patient')
            ->assertJsonPath('data.channel', "private-org.{$this->organization->uuid}.user.{$patient['login']}.calls");

        // The visit has not moved: ringing is not arriving.
        $this->assertSame(Appointment::STATUS_BOOKED, $this->visit($patient['visit'])->status);

        $this->as($patient['token'])->postJson(self::PORTAL."/video-calls/{$callId}/accept")
            ->assertOk()
            ->assertJsonPath('data.status', VideoCall::ACTIVE);

        // Answering is arriving: checked in with a token, called through by
        // the doctor who rang, and the consultation open for their notes.
        $visit = $this->visit($patient['visit']);
        $this->assertSame(Appointment::STATUS_IN_CONSULTATION, $visit->status);
        $this->assertSame(Appointment::QUEUE_WITH_DOCTOR, $visit->queue_status);
        $this->assertSame(Appointment::CONSULT_IN_PROGRESS, $visit->consultation_status);
        $this->assertSame(1, $visit->token_no);
        $this->assertSame($this->doctorLogin(), (int) $visit->consultation_started_by);
        $this->assertTrue($this->onTenant($this->organization, fn () => Consultation::query()
            ->where('appointment_id', $patient['visit'])->exists()));

        // The doctor's phone is told it connected.
        Event::assertDispatched(VideoCallUpdated::class, fn (VideoCallUpdated $event) => in_array($this->doctorLogin(), $event->userIds, true)
            && $event->call['status'] === VideoCall::ACTIVE);

        // So the doctor can write it up while they talk.
        $this->asDoctor()->putJson(self::STAFF."/appointments/{$patient['visit']}/consultation", [
            'chief_complaint' => 'Fever for two days',
        ])->assertOk();

        $this->as($patient['token'])->postJson(self::PORTAL."/video-calls/{$callId}/end")
            ->assertOk()
            ->assertJsonPath('data.status', VideoCall::ENDED)
            ->assertJsonPath('data.ended_by', 'patient');

        $this->asDoctor()->getJson(self::STAFF.'/video-calls/current')
            ->assertOk()
            ->assertJsonPath('data.call', null);

        // Hanging up is not finishing the consultation; the doctor still does that.
        $this->assertSame(Appointment::CONSULT_IN_PROGRESS, $this->visit($patient['visit'])->consultation_status);
    }

    public function test_the_handshake_reaches_only_the_other_phone_and_is_cleared_when_the_call_ends(): void
    {
        $patient = $this->patient();
        $callId = $this->ring($patient['visit'])->json('data.id');

        // Nothing passes before the patient picks up.
        $this->asDoctor()->postJson(self::STAFF."/video-calls/{$callId}/signals", ['type' => 'offer', 'sdp' => self::OFFER])
            ->assertStatus(409);

        $this->as($patient['token'])->postJson(self::PORTAL."/video-calls/{$callId}/accept")->assertOk();

        $offerId = $this->asDoctor()
            ->postJson(self::STAFF."/video-calls/{$callId}/signals", ['type' => 'offer', 'sdp' => self::OFFER])
            ->assertCreated()
            ->json('data.id');

        // A nudge to the patient's phone only — the offer itself is fetched.
        Event::assertDispatched(VideoCallSignalled::class, fn (VideoCallSignalled $event) => $event->userIds === [$patient['login']]
            && $event->signalId === $offerId
            && $event->type === 'offer');

        $this->as($patient['token'])->getJson(self::PORTAL."/video-calls/{$callId}/signals")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'offer')
            ->assertJsonPath('data.0.sdp', self::OFFER)
            ->assertJsonPath('meta.status', VideoCall::ACTIVE);

        $this->as($patient['token'])->postJson(self::PORTAL."/video-calls/{$callId}/signals", ['type' => 'answer', 'sdp' => 'v=0 answer'])
            ->assertCreated();
        $this->as($patient['token'])->postJson(self::PORTAL."/video-calls/{$callId}/signals", [
            'type' => 'candidates',
            'candidates' => [
                ['candidate' => 'candidate:1 1 udp 2122260223 192.168.1.4 50000 typ host', 'sdp_mid' => '0', 'sdp_mline_index' => 0],
            ],
        ])->assertCreated();

        // A patient whose app was closed mid-call asks for a fresh offer.
        $this->as($patient['token'])->postJson(self::PORTAL."/video-calls/{$callId}/signals", ['type' => 'restart'])
            ->assertCreated();
        $this->as($patient['token'])->postJson(self::PORTAL."/video-calls/{$callId}/signals", ['type' => 'restart', 'sdp' => 'x'])
            ->assertUnprocessable();

        // Each side reads only what the OTHER sent, past where it got to.
        $this->asDoctor()->getJson(self::STAFF."/video-calls/{$callId}/signals?after={$offerId}")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.type', 'answer')
            ->assertJsonPath('data.0.sdp', "v=0 answer\r\n")
            ->assertJsonPath('data.1.candidates.0.sdp_mline_index', 0)
            ->assertJsonPath('data.2.type', 'restart');

        // Malformed steps are refused rather than relayed.
        $this->asDoctor()->postJson(self::STAFF."/video-calls/{$callId}/signals", ['type' => 'offer'])
            ->assertUnprocessable();
        $this->asDoctor()->postJson(self::STAFF."/video-calls/{$callId}/signals", ['type' => 'candidates', 'sdp' => 'x'])
            ->assertUnprocessable();

        $this->asDoctor()->postJson(self::STAFF."/video-calls/{$callId}/end")
            ->assertOk()
            ->assertJsonPath('data.ended_by', 'doctor');

        // Both phones' addresses are gone with the call.
        $this->assertSame(0, $this->onTenant($this->organization, fn () => VideoCallSignal::query()->count()));
    }

    public function test_a_second_tap_finds_the_same_ring_and_a_busy_doctor_cannot_ring_twice(): void
    {
        $first = $this->patient('Rahul Tiwari');
        $second = $this->patient('Meera Nair');

        $callId = $this->ring($first['visit'])->assertOk()->json('data.id');

        $this->ring($first['visit'])->assertOk()->assertJsonPath('data.id', $callId);

        $this->ring($second['visit'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'You are already on a call with Rahul Tiwari. End it before starting another.');

        $this->assertSame(1, $this->onTenant($this->organization, fn () => VideoCall::query()->count()));
    }

    public function test_a_ring_nobody_answers_is_missed_and_cannot_be_picked_up_later(): void
    {
        $patient = $this->patient();
        $callId = $this->ring($patient['visit'])->json('data.id');

        $this->travel(VideoCall::RING_SECONDS + 1)->seconds();

        $this->as($patient['token'])->postJson(self::PORTAL."/video-calls/{$callId}/accept")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This call was missed. Your doctor can call again.');

        $call = $this->videoCall($callId);
        $this->assertSame(VideoCall::MISSED, $call->status);
        $this->assertSame(VideoCall::BY_SYSTEM, $call->ended_by);

        // And the visit never moved.
        $this->assertSame(Appointment::STATUS_BOOKED, $this->visit($patient['visit'])->status);

        // The doctor rings again: a new call, not the stale one.
        $this->ring($patient['visit'])->assertOk()->assertJsonPath('data.status', VideoCall::RINGING);
        $this->assertNotSame($callId, $this->ring($patient['visit'])->json('data.id'));
    }

    public function test_the_doctors_phone_giving_up_records_a_missed_call(): void
    {
        $patient = $this->patient();
        $callId = $this->ring($patient['visit'])->json('data.id');

        $this->asDoctor()->postJson(self::STAFF."/video-calls/{$callId}/end", ['reason' => 'no_answer'])
            ->assertOk()
            ->assertJsonPath('data.status', VideoCall::MISSED);

        // The patient's ringing screen is told to stop.
        Event::assertDispatched(VideoCallUpdated::class, fn (VideoCallUpdated $event) => in_array($patient['login'], $event->userIds, true)
            && $event->call['status'] === VideoCall::MISSED);
    }

    public function test_a_patient_declines_and_the_doctor_is_told(): void
    {
        $patient = $this->patient();
        $callId = $this->ring($patient['visit'])->json('data.id');

        $this->as($patient['token'])->postJson(self::PORTAL."/video-calls/{$callId}/decline")
            ->assertOk()
            ->assertJsonPath('data.status', VideoCall::DECLINED)
            ->assertJsonPath('data.ended_by', 'patient');

        Event::assertDispatched(VideoCallUpdated::class, fn (VideoCallUpdated $event) => in_array($this->doctorLogin(), $event->userIds, true)
            && $event->call['status'] === VideoCall::DECLINED);

        // A declined call cannot then be answered.
        $this->as($patient['token'])->postJson(self::PORTAL."/video-calls/{$callId}/accept")->assertStatus(409);
    }

    /*
    |--------------------------------------------------------------------------
    | The walls
    |--------------------------------------------------------------------------
    */

    public function test_only_a_video_visit_of_today_with_a_patient_on_the_app_can_be_rung(): void
    {
        $inClinic = $this->patient('Asha Rane', Appointment::MODE_IN_CLINIC);
        $this->ring($inClinic['visit'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This visit was booked to come in to the clinic. Only a video visit can be called.');

        $tomorrow = $this->patient('Kiran Das', date: now()->addDay()->toDateString());
        $this->ring($tomorrow['visit'])->assertUnprocessable();

        $noApp = $this->patient('Sunil Rao', withLogin: false);
        $this->ring($noApp['visit'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Sunil Rao is not signed in to the app, so a call cannot reach them. Please phone them instead.');

        $this->assertSame(0, $this->onTenant($this->organization, fn () => VideoCall::query()->count()));
    }

    public function test_each_side_reaches_only_its_own_calls(): void
    {
        $mine = $this->patient('Rahul Tiwari');
        $other = $this->patient('Meera Nair');

        // Another doctor's visit reads as missing.
        $this->ring($mine['visit'], 'vikram')->assertNotFound();

        $callId = $this->ring($mine['visit'])->json('data.id');

        // Another patient cannot see, answer or hang up on it.
        $this->as($other['token'])->getJson(self::PORTAL."/video-calls/{$callId}")->assertNotFound();
        $this->as($other['token'])->postJson(self::PORTAL."/video-calls/{$callId}/accept")->assertNotFound();
        $this->as($other['token'])->postJson(self::PORTAL."/video-calls/{$callId}/end")->assertNotFound();

        // Nor another doctor.
        $this->asDoctor('vikram')->getJson(self::STAFF."/video-calls/{$callId}")->assertNotFound();

        // A patient cannot ring, and a doctor cannot answer.
        $this->as($mine['token'])->postJson(self::STAFF."/appointments/{$mine['visit']}/video-call")->assertForbidden();
        $this->asDoctor()->postJson(self::PORTAL."/video-calls/{$callId}/accept")->assertForbidden();

        $this->assertSame(VideoCall::RINGING, $this->videoCall($callId)->status);
    }

    /*
    |--------------------------------------------------------------------------
    | What the lists say
    |--------------------------------------------------------------------------
    */

    public function test_the_lists_say_which_visits_are_video_and_which_are_ringing(): void
    {
        $video = $this->patient('Rahul Tiwari');
        $this->patient('Asha Rane', Appointment::MODE_IN_CLINIC);

        $callId = $this->ring($video['visit'])->json('data.id');

        $queue = collect($this->asDoctor()->getJson(self::STAFF.'/opd/my-day')->assertOk()->json('data.queue'))
            ->keyBy('customer_name');

        $this->assertSame(Appointment::MODE_VIDEO, $queue['Rahul Tiwari']['mode']);
        $this->assertTrue($queue['Rahul Tiwari']['available']['video_call']);
        $this->assertSame(['id' => $callId, 'status' => VideoCall::RINGING], $queue['Rahul Tiwari']['video_call']);

        $this->assertSame(Appointment::MODE_IN_CLINIC, $queue['Asha Rane']['mode']);
        $this->assertFalse($queue['Asha Rane']['available']['video_call']);
        $this->assertNull($queue['Asha Rane']['video_call']);

        // The patient's own list carries the ring, for a "Join call" button.
        $this->as($video['token'])->getJson(self::PORTAL.'/appointments')
            ->assertOk()
            ->assertJsonPath('data.0.mode', Appointment::MODE_VIDEO)
            ->assertJsonPath('data.0.video_call.id', $callId)
            ->assertJsonPath('data.0.video_call.status', VideoCall::RINGING);
    }
}
