<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Events\Realtime\ConversationRead;
use App\Events\Realtime\MessageSent;
use App\Jobs\SendPushMessage;
use App\Models\Platform\Module;
use App\Models\Platform\Organization;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Location;
use App\Models\Tenant\User as TenantUser;
use App\Services\Tenant\PatientAccountProvisioner;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TenantTestCase;

/**
 * Doctor-to-patient messaging, from both apps.
 *
 * The walls matter more than the rows: a thread opens only between a doctor
 * and a patient who share a visit, each side reads only its own threads, a
 * guessed id answers 404, and what arrives live is only ever one's own.
 */
class MessagingTest extends TenantTestCase
{
    use RefreshDatabase;

    private const STAFF_DOCTOR = '/api/v1/tenant/messages';

    private const PORTAL = '/api/v1/tenant/portal/messages';

    private Organization $organization;

    private int $branchId;

    /** @var array<string, int> doctor key => doctor id */
    private array $doctors = [];

    /** @var array<string, string> doctor key => token */
    private array $doctorTokens = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');

        Queue::fake();
        Event::fake([MessageSent::class, ConversationRead::class]);
        Storage::fake('local');

        $this->organization = $this->provisionOrganization('MSG');
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
    }

    private function fresh(): void
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();
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
     * A patient with an app login, optionally with a visit to a doctor.
     *
     * @return array{0: int, 1: string} customer id, token
     */
    private function patient(string $name, ?string $seenBy = 'anjali', string $status = Appointment::STATUS_COMPLETED): array
    {
        return $this->onTenant($this->organization, function () use ($name, $seenBy, $status) {
            $customer = Customer::on('organization')->create([
                'name' => $name,
                'phone' => '98'.random_int(10000000, 99999999),
                'is_active' => true,
            ]);

            if ($seenBy !== null) {
                Appointment::on('organization')->create([
                    'customer_id' => $customer->id,
                    'doctor_id' => $this->doctors[$seenBy],
                    'location_id' => $this->branchId,
                    'appointment_date' => now()->toDateString(),
                    'type' => Appointment::BOOKED,
                    'status' => $status,
                    'slot_at' => '10:00',
                ]);
            }

            $login = app(PatientAccountProvisioner::class)->open($customer, ['appointments']);

            return [$customer->id, $login->createToken('Patient phone')->plainTextToken];
        });
    }

    /*
    |--------------------------------------------------------------------------
    | The conversation itself
    |--------------------------------------------------------------------------
    */

    public function test_a_patient_and_their_doctor_write_to_each_other(): void
    {
        [$customerId, $patientToken] = $this->patient('Rahul Tiwari');

        // The patient finds the doctor they saw, and opens the thread.
        $this->as($patientToken)->getJson(self::PORTAL.'/contacts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->doctors['anjali'])
            ->assertJsonPath('data.0.on_app', true)
            ->assertJsonPath('data.0.conversation_id', null);

        $conversationId = $this->as($patientToken)
            ->postJson(self::PORTAL.'/conversations', ['doctor_id' => $this->doctors['anjali']])
            ->assertOk()
            ->assertJsonPath('meta.side', 'patient')
            ->json('data.id');

        $this->as($patientToken)
            ->postJson(self::PORTAL."/conversations/{$conversationId}/messages", ['body' => 'Fever since two days.'])
            ->assertCreated()
            ->assertJsonPath('data.sender_side', 'patient')
            ->assertJsonPath('data.body', 'Fever since two days.');

        // The doctor's inbox has it, unread.
        $this->asDoctor()->getJson(self::STAFF_DOCTOR.'/conversations')
            ->assertOk()
            ->assertJsonPath('meta.side', 'doctor')
            ->assertJsonPath('meta.unread', 1)
            ->assertJsonPath('data.0.id', $conversationId)
            ->assertJsonPath('data.0.counterpart.id', $customerId)
            ->assertJsonPath('data.0.counterpart.name', 'Rahul Tiwari')
            ->assertJsonPath('data.0.unread', 1)
            ->assertJsonPath('data.0.last_message.body', 'Fever since two days.');

        $this->asDoctor()->postJson(self::STAFF_DOCTOR."/conversations/{$conversationId}/messages", [
            'body' => 'Please share your temperature readings.',
        ])->assertCreated()->assertJsonPath('data.sender_side', 'doctor');

        // Both messages, oldest first, from either side.
        $this->as($patientToken)->getJson(self::PORTAL."/conversations/{$conversationId}/messages")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.sender_side', 'patient')
            ->assertJsonPath('data.1.body', 'Please share your temperature readings.')
            ->assertJsonPath('meta.has_more', false);

        // The doctor replying read everything before it.
        $this->asDoctor()->getJson(self::STAFF_DOCTOR.'/unread')->assertOk()->assertJsonPath('data.unread', 0);
        $this->as($patientToken)->getJson(self::PORTAL.'/unread')->assertOk()->assertJsonPath('data.unread', 1);
    }

    public function test_either_side_opening_finds_the_same_thread(): void
    {
        [$customerId, $patientToken] = $this->patient('Rahul Tiwari');

        $fromDoctor = $this->asDoctor()
            ->postJson(self::STAFF_DOCTOR.'/conversations', ['customer_id' => $customerId])
            ->assertOk()
            ->json('data.id');

        $fromPatient = $this->as($patientToken)
            ->postJson(self::PORTAL.'/conversations', ['doctor_id' => $this->doctors['anjali']])
            ->assertOk()
            ->json('data.id');

        $this->assertSame($fromDoctor, $fromPatient);
    }

    /** An opened thread with nothing in it does not sit in anybody's inbox. */
    public function test_an_empty_thread_is_not_listed(): void
    {
        [$customerId] = $this->patient('Rahul Tiwari');

        $this->asDoctor()->postJson(self::STAFF_DOCTOR.'/conversations', ['customer_id' => $customerId])->assertOk();

        $this->asDoctor()->getJson(self::STAFF_DOCTOR.'/conversations')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_reading_moves_forward_and_tells_the_other_side(): void
    {
        [, $patientToken] = $this->patient('Rahul Tiwari');

        $conversationId = $this->as($patientToken)
            ->postJson(self::PORTAL.'/conversations', ['doctor_id' => $this->doctors['anjali']])
            ->json('data.id');

        $first = $this->as($patientToken)
            ->postJson(self::PORTAL."/conversations/{$conversationId}/messages", ['body' => 'One'])
            ->json('data.id');
        $this->as($patientToken)->postJson(self::PORTAL."/conversations/{$conversationId}/messages", ['body' => 'Two']);

        $this->asDoctor()->postJson(self::STAFF_DOCTOR."/conversations/{$conversationId}/read", ['up_to' => $first])
            ->assertOk()
            ->assertJsonPath('data.last_read_id', $first);

        $this->asDoctor()->getJson(self::STAFF_DOCTOR.'/unread')->assertJsonPath('data.unread', 1);

        // Never backwards, and never past the end.
        $this->asDoctor()->postJson(self::STAFF_DOCTOR."/conversations/{$conversationId}/read", ['up_to' => 1])
            ->assertJsonPath('data.last_read_id', $first);

        $last = $this->asDoctor()->postJson(self::STAFF_DOCTOR."/conversations/{$conversationId}/read", ['up_to' => 999999])
            ->json('data.last_read_id');

        $this->assertGreaterThan($first, $last);
        $this->asDoctor()->getJson(self::STAFF_DOCTOR.'/unread')->assertJsonPath('data.unread', 0);

        // The patient's ticks turn: their thread now says how far the doctor read.
        $this->as($patientToken)->getJson(self::PORTAL."/conversations/{$conversationId}")
            ->assertJsonPath('data.counterpart_read_id', $last);

        Event::assertDispatched(ConversationRead::class, 2);
    }

    public function test_older_messages_page_in_and_a_reconnect_catches_up(): void
    {
        [, $patientToken] = $this->patient('Rahul Tiwari');

        $conversationId = $this->as($patientToken)
            ->postJson(self::PORTAL.'/conversations', ['doctor_id' => $this->doctors['anjali']])
            ->json('data.id');

        $ids = [];

        for ($i = 1; $i <= 35; $i++) {
            $ids[] = $this->as($patientToken)
                ->postJson(self::PORTAL."/conversations/{$conversationId}/messages", ['body' => "Message {$i}"])
                ->json('data.id');
        }

        $latest = $this->asDoctor()->getJson(self::STAFF_DOCTOR."/conversations/{$conversationId}/messages")
            ->assertOk()
            ->assertJsonCount(30, 'data')
            ->assertJsonPath('meta.has_more', true)
            ->assertJsonPath('data.29.body', 'Message 35');

        $this->asDoctor()
            ->getJson(self::STAFF_DOCTOR."/conversations/{$conversationId}/messages?before=".$latest->json('data.0.id'))
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.has_more', false)
            ->assertJsonPath('data.0.body', 'Message 1');

        $this->asDoctor()
            ->getJson(self::STAFF_DOCTOR."/conversations/{$conversationId}/messages?after=".$ids[32])
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.body', 'Message 35');
    }

    /*
    |--------------------------------------------------------------------------
    | Who may talk to whom
    |--------------------------------------------------------------------------
    */

    public function test_nobody_opens_a_thread_without_a_shared_visit(): void
    {
        [$customerId, $patientToken] = $this->patient('Rahul Tiwari');

        // Seen by Anjali, not by Vikram.
        $this->as($patientToken)
            ->postJson(self::PORTAL.'/conversations', ['doctor_id' => $this->doctors['vikram']])
            ->assertForbidden();

        $this->asDoctor('vikram')
            ->postJson(self::STAFF_DOCTOR.'/conversations', ['customer_id' => $customerId])
            ->assertForbidden();

        $this->asDoctor('vikram')->getJson(self::STAFF_DOCTOR.'/contacts')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_cancelled_booking_is_not_a_visit(): void
    {
        [$customerId] = $this->patient('Rahul Tiwari', 'anjali', Appointment::STATUS_CANCELLED);

        $this->asDoctor()
            ->postJson(self::STAFF_DOCTOR.'/conversations', ['customer_id' => $customerId])
            ->assertForbidden();
    }

    /** A guessed id is somebody else's thread, and answers as if it did not exist. */
    public function test_nobody_reads_another_persons_thread(): void
    {
        [, $rahul] = $this->patient('Rahul Tiwari');
        [, $asha] = $this->patient('Asha Rane');

        $conversationId = $this->as($rahul)
            ->postJson(self::PORTAL.'/conversations', ['doctor_id' => $this->doctors['anjali']])
            ->json('data.id');

        $messageId = $this->as($rahul)
            ->postJson(self::PORTAL."/conversations/{$conversationId}/messages", ['body' => 'Private'])
            ->json('data.id');

        $this->as($asha)->getJson(self::PORTAL."/conversations/{$conversationId}/messages")->assertNotFound();
        $this->as($asha)
            ->postJson(self::PORTAL."/conversations/{$conversationId}/messages", ['body' => 'Hello'])
            ->assertNotFound();
        $this->as($asha)->getJson(self::PORTAL."/attachments/{$messageId}")->assertNotFound();

        $this->asDoctor('vikram')->getJson(self::STAFF_DOCTOR."/conversations/{$conversationId}")->assertNotFound();
    }

    /** Messaging is a doctor's, not the desk's — even the owner has no side. */
    public function test_staff_who_are_not_doctors_have_no_inbox(): void
    {
        $this->signInAsOwner($this->organization);

        $this->getJson(self::STAFF_DOCTOR.'/conversations')->assertForbidden();
    }

    public function test_the_two_apps_routes_do_not_cross(): void
    {
        [, $patientToken] = $this->patient('Rahul Tiwari');

        $this->as($patientToken)->getJson(self::STAFF_DOCTOR.'/conversations')->assertForbidden();
        $this->asDoctor()->getJson(self::PORTAL.'/conversations')->assertForbidden();
    }

    public function test_taking_the_capability_off_the_patient_role_closes_messages(): void
    {
        [, $patientToken] = $this->patient('Rahul Tiwari');

        $this->onTenant($this->organization, fn () => \App\Models\Tenant\Role::query()
            ->where('slug', PatientAccountProvisioner::ROLE)
            ->firstOrFail()
            ->syncCapabilities(['portal.appointments.view']));

        $this->as($patientToken)->getJson(self::PORTAL.'/conversations')->assertForbidden();
    }

    public function test_a_patient_cannot_open_a_thread_with_a_doctor_who_has_no_login(): void
    {
        $doctorId = $this->onTenant($this->organization, fn () => \App\Models\Tenant\Doctor::on('organization')->create([
            'name' => 'Dr. Visiting Consultant',
            'is_active' => true,
        ])->id);

        $this->doctors['visiting'] = $doctorId;

        [, $patientToken] = $this->patient('Rahul Tiwari', 'visiting');

        $this->as($patientToken)->getJson(self::PORTAL.'/contacts')
            ->assertJsonPath('data.0.on_app', false);

        $this->as($patientToken)
            ->postJson(self::PORTAL.'/conversations', ['doctor_id' => $doctorId])
            ->assertStatus(422);
    }

    /*
    |--------------------------------------------------------------------------
    | Sending
    |--------------------------------------------------------------------------
    */

    public function test_an_empty_message_is_refused(): void
    {
        [, $patientToken] = $this->patient('Rahul Tiwari');

        $conversationId = $this->as($patientToken)
            ->postJson(self::PORTAL.'/conversations', ['doctor_id' => $this->doctors['anjali']])
            ->json('data.id');

        $this->as($patientToken)
            ->postJson(self::PORTAL."/conversations/{$conversationId}/messages", ['body' => ''])
            ->assertStatus(422);

        $this->as($patientToken)
            ->postJson(self::PORTAL."/conversations/{$conversationId}/messages", ['body' => '   '])
            ->assertStatus(422);
    }

    public function test_a_photo_is_stored_privately_and_streamed_only_to_the_two_people(): void
    {
        [, $patientToken] = $this->patient('Rahul Tiwari');

        $conversationId = $this->as($patientToken)
            ->postJson(self::PORTAL.'/conversations', ['doctor_id' => $this->doctors['anjali']])
            ->json('data.id');

        $sent = $this->as($patientToken)
            ->post(self::PORTAL."/conversations/{$conversationId}/messages", [
                'file' => UploadedFile::fake()->image('rash.jpg', 400, 300),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.body', null)
            ->assertJsonPath('data.attachment.name', 'rash.jpg')
            ->assertJsonPath('data.attachment.is_image', true)
            ->assertJsonMissingPath('data.attachment.url');

        $messageId = $sent->json('data.id');

        $this->asDoctor()->get(self::STAFF_DOCTOR."/attachments/{$messageId}")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');

        $this->as($patientToken)->get(self::PORTAL."/attachments/{$messageId}")->assertOk();

        $this->asDoctor('vikram')->getJson(self::STAFF_DOCTOR."/attachments/{$messageId}")->assertNotFound();

        $this->assertCount(1, Storage::disk('local')->allFiles('conversations'));
    }

    public function test_an_executable_is_not_accepted(): void
    {
        [, $patientToken] = $this->patient('Rahul Tiwari');

        $conversationId = $this->as($patientToken)
            ->postJson(self::PORTAL.'/conversations', ['doctor_id' => $this->doctors['anjali']])
            ->json('data.id');

        $this->as($patientToken)
            ->post(self::PORTAL."/conversations/{$conversationId}/messages", [
                'file' => UploadedFile::fake()->create('run.exe', 10, 'application/x-msdownload'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    /** Live to both, and a push to the other one — that never carries the words. */
    public function test_a_message_is_announced_live_and_pushed_to_the_recipient(): void
    {
        [$customerId, $patientToken] = $this->patient('Rahul Tiwari');

        $conversationId = $this->as($patientToken)
            ->postJson(self::PORTAL.'/conversations', ['doctor_id' => $this->doctors['anjali']])
            ->json('data.id');

        $this->as($patientToken)
            ->postJson(self::PORTAL."/conversations/{$conversationId}/messages", ['body' => 'My sugar is 240'])
            ->assertCreated();

        [$doctorLogin, $patientLogin] = $this->onTenant($this->organization, fn () => [
            TenantUser::query()->where('email', 'anjali@clinic.test')->value('id'),
            TenantUser::query()->where('userable_id', $customerId)->where('role', TenantUser::PATIENT)->value('id'),
        ]);

        Event::assertDispatched(MessageSent::class, function (MessageSent $event) use ($conversationId, $doctorLogin, $patientLogin) {
            return $event->payload['conversation_id'] === $conversationId
                && $event->organizationUuid === $this->organization->uuid
                && collect($event->userIds)->sort()->values()->all() === collect([$doctorLogin, $patientLogin])->sort()->values()->all()
                && $event->broadcastAs() === 'message.sent';
        });

        Queue::assertPushed(SendPushMessage::class, function (SendPushMessage $job) use ($doctorLogin, $conversationId) {
            $payload = $job->content;

            return $payload['user_ids'] === [$doctorLogin]
                && $payload['title'] === 'Rahul Tiwari'
                && $payload['body'] === 'Sent you a message'
                && ! str_contains($payload['body'], '240')
                && $payload['data']['type'] === 'message.received'
                && $payload['data']['conversation_id'] === $conversationId;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | The live channel
    |--------------------------------------------------------------------------
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
        ]);

        app(BroadcastManager::class)->forgetDrivers();

        require base_path('routes/channels.php');
    }

    public function test_only_the_owner_of_a_channel_may_listen_on_it(): void
    {
        $this->useReverb();

        [, $patientToken] = $this->patient('Rahul Tiwari');

        $channel = $this->as($patientToken)->getJson(self::PORTAL.'/conversations')
            ->assertOk()
            ->json('meta.channel');

        $this->assertStringStartsWith("private-org.{$this->organization->uuid}.user.", $channel);

        $this->as($patientToken)->postJson('/api/v1/broadcasting/auth', [
            'channel_name' => $channel,
            'socket_id' => '1234.5678',
        ])->assertOk()->assertJsonStructure(['auth']);

        $this->asDoctor()->postJson('/api/v1/broadcasting/auth', [
            'channel_name' => $channel,
            'socket_id' => '1234.5678',
        ])->assertForbidden();
    }
}
