<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Http\Middleware\ResolveTenantFromHeader;
use App\Jobs\SendPushNotification;
use App\Models\Platform\FirebaseDevice;
use App\Models\Platform\Organization;
use App\Models\Tenant\User as TenantUser;
use App\Services\Push\DeviceRegistry;
use App\Services\Push\FcmPushSender;
use App\Services\Push\LogPushSender;
use App\Services\Push\PushMessage;
use App\Services\Push\PushResult;
use App\Services\Push\PushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TenantTestCase;

/**
 * Firebase delivers; Laravel decides.
 *
 * What matters is where the walls are: a device belongs to whoever signed in,
 * in the organization they signed in to; a phone that moves to another
 * organization stops reaching the first; a push addressed to user 2 of one
 * clinic never reaches user 2 of another; and only FCM's own "this device is
 * gone" switches a device off.
 */
class PushNotificationTest extends TenantTestCase
{
    use RefreshDatabase;

    /** A Monday morning, so the Monday sitting is today and still ahead. */
    private const MONDAY = '2026-09-07';

    private const PHONE = '9876500021';

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');

        config(['push.driver' => 'log']);
    }

    /** A token for the phone app, as the mobile sign-in issues it — the owner's unless told otherwise. */
    private function phoneToken(Organization $organization, string $device = 'Test phone', ?string $email = null): string
    {
        $this->fresh();

        return $this->postJson('/api/v1/tenant/auth/token', [
            'subdomain' => $organization->subdomain,
            'email' => $email ?? $organization->email,
            'password' => self::PASSWORD,
            'device_name' => $device,
        ])->assertOk()->json('data.token');
    }

    private function fresh(): void
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();
    }

    /**
     * A request the way the app makes it — and WITHOUT the Origin header the
     * base TestCase adds for the browser. With it, Sanctum takes a phone for
     * the SPA and looks the bearer token up before X-Organization has chosen
     * a database; no phone sends one.
     */
    private function asPhone(Organization $organization, string $token): static
    {
        $this->fresh();

        return $this->withoutHeader('Origin')->withHeaders([
            ResolveTenantFromHeader::HEADER => $organization->subdomain,
            'Authorization' => "Bearer {$token}",
        ]);
    }

    private function register(Organization $organization, string $token, string $fcm, string $device = 'pixel-7'): TestResponse
    {
        return $this->asPhone($organization, $token)->postJson('/api/v1/tenant/devices', [
            'device_id' => $device,
            'fcm_token' => $fcm,
            'platform' => 'android',
            'app_version' => '1.4.0',
        ]);
    }

    private function ownerId(Organization $organization): int
    {
        return (int) $this->onTenant($organization, fn () => TenantUser::on('organization')
            ->where('email', $organization->email)
            ->value('id'));
    }

    /** Records what it was asked to send, and answers as told. */
    private function fakeSender(?PushResult $answer = null): object
    {
        $sender = new class($answer) implements PushSender
        {
            /** @var list<array{message: PushMessage, tokens: list<string>}> */
            public array $calls = [];

            public function __construct(private readonly ?PushResult $answer) {}

            public function send(PushMessage $message, array $tokens): PushResult
            {
                $this->calls[] = ['message' => $message, 'tokens' => $tokens];

                return $this->answer ?? new PushResult(sent: $tokens);
            }
        };

        $this->app->instance(PushSender::class, $sender);

        return $sender;
    }

    /*
    |--------------------------------------------------------------------------
    | Registering a device
    |--------------------------------------------------------------------------
    */

    public function test_a_signed_in_phone_registers_for_pushes(): void
    {
        $organization = $this->provisionOrganization('PN');
        $token = $this->phoneToken($organization);

        $this->register($organization, $token, 'fcm-token-1')
            ->assertOk()
            ->assertJsonPath('data.device_id', 'pixel-7')
            // A push token is a credential for reaching somebody; it is never echoed.
            ->assertJsonMissingPath('data.fcm_token');

        $device = FirebaseDevice::query()->sole();

        $this->assertSame($organization->id, $device->organization_id);
        $this->assertSame($this->ownerId($organization), $device->user_id);
        $this->assertNotNull($device->access_token_id);
        $this->assertTrue($device->is_active);
        $this->assertSame('1.4.0', $device->app_version);
    }

    public function test_one_person_can_have_many_devices_and_a_rotated_token_replaces_its_own(): void
    {
        $organization = $this->provisionOrganization('PN');
        $token = $this->phoneToken($organization);

        $this->register($organization, $token, 'fcm-phone', 'pixel-7')->assertOk();
        $this->register($organization, $token, 'fcm-tablet', 'galaxy-tab')->assertOk();

        $this->assertSame(2, FirebaseDevice::query()->active()->count());

        // Firebase rotated the phone's token: the old one is dead, not a third device.
        $this->register($organization, $token, 'fcm-phone-rotated', 'pixel-7')->assertOk();

        $this->assertEqualsCanonicalizing(
            ['fcm-phone-rotated', 'fcm-tablet'],
            FirebaseDevice::query()->active()->pluck('fcm_token')->all(),
        );
    }

    public function test_registering_needs_somebody_signed_in(): void
    {
        $organization = $this->provisionOrganization('PN');

        $this->fresh();

        $this->withHeaders([ResolveTenantFromHeader::HEADER => $organization->subdomain])
            ->postJson('/api/v1/tenant/devices', [
                'device_id' => 'pixel-7', 'fcm_token' => 'fcm-x', 'platform' => 'android',
            ])->assertStatus(401);

        $this->assertSame(0, FirebaseDevice::query()->count());
    }

    public function test_a_platform_that_is_not_a_phone_or_browser_is_refused(): void
    {
        $organization = $this->provisionOrganization('PN');
        $token = $this->phoneToken($organization);

        $this->asPhone($organization, $token)->postJson('/api/v1/tenant/devices', [
            'device_id' => 'fridge', 'fcm_token' => 'fcm-x', 'platform' => 'toaster',
        ])->assertStatus(422)->assertJsonValidationErrors('platform');
    }

    public function test_signing_out_switches_off_that_phone_and_no_other(): void
    {
        $organization = $this->provisionOrganization('PN');

        $phone = $this->phoneToken($organization, 'Phone');
        $tablet = $this->phoneToken($organization, 'Tablet');

        $this->register($organization, $phone, 'fcm-phone', 'pixel-7')->assertOk();
        $this->register($organization, $tablet, 'fcm-tablet', 'galaxy-tab')->assertOk();

        $this->asPhone($organization, $phone)->deleteJson('/api/v1/tenant/auth/token')->assertOk();

        $this->assertFalse(FirebaseDevice::query()->where('fcm_token', 'fcm-phone')->value('is_active'));
        $this->assertTrue(FirebaseDevice::query()->where('fcm_token', 'fcm-tablet')->value('is_active'));
    }

    public function test_deactivating_somebody_switches_off_their_phones(): void
    {
        $organization = $this->provisionOrganization('PN');

        $this->register($organization, $this->phoneToken($organization, 'Staff phone', self::STAFF_EMAIL), 'fcm-staff')
            ->assertOk();
        $this->register($organization, $this->phoneToken($organization), 'fcm-owner', 'owner-phone')->assertOk();

        $this->onTenant($organization, function () {
            $staff = TenantUser::on('organization')->where('email', self::STAFF_EMAIL)->firstOrFail();
            $staff->is_active = false;
            $staff->save();
        });

        $this->assertFalse(FirebaseDevice::query()->where('fcm_token', 'fcm-staff')->value('is_active'));
        $this->assertTrue(FirebaseDevice::query()->where('fcm_token', 'fcm-owner')->value('is_active'));
    }

    public function test_the_app_can_unregister_a_device(): void
    {
        $organization = $this->provisionOrganization('PN');
        $token = $this->phoneToken($organization);

        $this->register($organization, $token, 'fcm-phone')->assertOk();

        $this->asPhone($organization, $token)->deleteJson('/api/v1/tenant/devices/pixel-7')->assertOk();

        $this->assertFalse(FirebaseDevice::query()->where('fcm_token', 'fcm-phone')->value('is_active'));
    }

    /*
    |--------------------------------------------------------------------------
    | Isolation
    |--------------------------------------------------------------------------
    */

    public function test_a_phone_that_moves_to_another_organization_stops_reaching_the_first(): void
    {
        $apollo = $this->provisionOrganization('PA');
        $fortis = $this->provisionOrganization('PF');

        $this->register($apollo, $this->phoneToken($apollo), 'fcm-shared-phone')->assertOk();

        // The same phone, signed out of Apollo and into Fortis.
        $this->register($fortis, $this->phoneToken($fortis), 'fcm-shared-phone')->assertOk();

        $this->assertSame(1, FirebaseDevice::query()->count());

        $registry = app(DeviceRegistry::class);

        $this->assertSame([], $registry->tokensFor($apollo, [$this->ownerId($apollo)]));
        $this->assertSame(['fcm-shared-phone'], $registry->tokensFor($fortis, [$this->ownerId($fortis)]));
    }

    /**
     * Both owners are user 1 in their own database. A push for Apollo's user 1
     * must not reach Fortis's user 1.
     */
    public function test_a_push_reaches_only_that_organizations_people_even_when_ids_collide(): void
    {
        $apollo = $this->provisionOrganization('PA');
        $fortis = $this->provisionOrganization('PF');

        $this->assertSame($this->ownerId($apollo), $this->ownerId($fortis));

        $this->register($apollo, $this->phoneToken($apollo), 'fcm-apollo')->assertOk();
        $this->register($fortis, $this->phoneToken($fortis), 'fcm-fortis')->assertOk();

        $sender = $this->fakeSender();

        (new SendPushNotification($apollo->id, [$this->ownerId($apollo)], 'Hello', 'From Apollo', ['type' => 'test']))
            ->handle(app(DeviceRegistry::class), $sender);

        $this->assertCount(1, $sender->calls);
        $this->assertSame(['fcm-apollo'], $sender->calls[0]['tokens']);
        $this->assertSame($apollo->uuid, $sender->calls[0]['message']->data['organization']);
        // What the app compares with the clinic it is signed in to.
        $this->assertSame($apollo->subdomain, $sender->calls[0]['message']->data['workspace']);
    }

    public function test_a_token_from_one_organization_cannot_register_against_another(): void
    {
        $apollo = $this->provisionOrganization('PA');
        $fortis = $this->provisionOrganization('PF');

        $this->register($fortis, $this->phoneToken($apollo), 'fcm-x')->assertStatus(401);

        $this->assertSame(0, FirebaseDevice::query()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Sending
    |--------------------------------------------------------------------------
    */

    public function test_a_device_fcm_says_is_gone_is_switched_off(): void
    {
        $organization = $this->provisionOrganization('PN');
        $this->register($organization, $this->phoneToken($organization), 'fcm-gone')->assertOk();

        $sender = $this->fakeSender(new PushResult(invalid: ['fcm-gone']));

        (new SendPushNotification($organization->id, [$this->ownerId($organization)], 'Hi', 'There'))
            ->handle(app(DeviceRegistry::class), $sender);

        $this->assertFalse(FirebaseDevice::query()->where('fcm_token', 'fcm-gone')->value('is_active'));
    }

    public function test_only_the_devices_fcm_was_too_busy_for_are_retried(): void
    {
        $organization = $this->provisionOrganization('PN');
        $token = $this->phoneToken($organization);
        $this->register($organization, $token, 'fcm-ok', 'pixel-7')->assertOk();
        $this->register($organization, $token, 'fcm-busy', 'galaxy-tab')->assertOk();

        Queue::fake();

        $sender = $this->fakeSender(new PushResult(sent: ['fcm-ok'], retry: ['fcm-busy']));

        (new SendPushNotification($organization->id, [$this->ownerId($organization)], 'Hi', 'There'))
            ->handle(app(DeviceRegistry::class), $sender);

        Queue::assertPushed(SendPushNotification::class, fn (SendPushNotification $job) => $job->onlyTokens === ['fcm-busy']
            && $job->round === 1);

        // And nothing was switched off for being busy.
        $this->assertTrue(FirebaseDevice::query()->where('fcm_token', 'fcm-busy')->value('is_active'));
    }

    public function test_the_log_driver_is_the_default(): void
    {
        $this->assertInstanceOf(LogPushSender::class, app(PushSender::class));
    }

    /**
     * The real FCM sender, against a fake Google: a JWT signed with the
     * service account's key is exchanged for an access token, then one message
     * per device — and each answer is sorted by what it means.
     */
    public function test_the_fcm_sender_signs_in_to_google_and_sorts_every_answer(): void
    {
        /*
         * A fixed, TEST-ONLY key rather than one generated here: PHP on
         * Windows cannot generate keys without an openssl.cnf, while signing
         * with an existing key — all the sender does — needs nothing. The key
         * signs nothing real and belongs to no Google project.
         */
        $privatePem = (string) file_get_contents(base_path('tests/Support/fcm-test-only.key'));
        $publicPem = openssl_pkey_get_details(openssl_pkey_get_private($privatePem))['key'];

        Http::fake(function (HttpRequest $request) use ($publicPem) {
            if (str_contains($request->url(), 'oauth2.googleapis.com/token')) {
                // The assertion must be a JWT really signed with the service account's key.
                [$head, $claims, $signature] = explode('.', $request['assertion']);
                $valid = openssl_verify(
                    "{$head}.{$claims}",
                    base64_decode(strtr($signature, '-_', '+/')),
                    $publicPem,
                    OPENSSL_ALGO_SHA256,
                );

                return $valid === 1
                    ? Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600])
                    : Http::response(['error' => 'invalid_grant'], 400);
            }

            return match ($request['message']['token']) {
                'fcm-good' => Http::response(['name' => 'projects/clinic/messages/1']),
                'fcm-gone' => Http::response(['error' => ['code' => 404, 'status' => 'NOT_FOUND', 'details' => [
                    ['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED'],
                ]]], 404),
                'fcm-busy' => Http::response(['error' => ['code' => 503, 'status' => 'UNAVAILABLE']], 503),
                default => Http::response(['error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT', 'details' => [
                    ['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'INVALID_ARGUMENT'],
                ]]], 400),
            };
        });

        $sender = new FcmPushSender('clinic', [
            'client_email' => 'push@clinic.iam.gserviceaccount.com',
            'private_key' => $privatePem,
        ]);

        $result = $sender->send(
            new PushMessage('Appointment confirmed', 'See you at 10:30', ['type' => 'appointment_booked']),
            ['fcm-good', 'fcm-gone', 'fcm-busy', 'fcm-malformed'],
        );

        $this->assertSame(['fcm-good'], $result->sent);
        $this->assertSame(['fcm-gone'], $result->invalid);
        $this->assertSame(['fcm-busy'], $result->retry);
        // A refusal that is not about the device does NOT switch it off.
        $this->assertSame(['fcm-malformed'], $result->failed);

        // Read back from the JSON actually sent — what FCM would receive.
        Http::assertSent(function (HttpRequest $request) {
            $body = json_decode($request->body(), true);

            return str_contains($request->url(), 'fcm.googleapis.com/v1/projects/clinic/messages:send')
                && $request->hasHeader('Authorization', 'Bearer ya29.test')
                && $body['message']['notification']['title'] === 'Appointment confirmed'
                && $body['message']['data']['type'] === 'appointment_booked';
        });
    }
}
