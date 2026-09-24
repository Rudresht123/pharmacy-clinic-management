<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\SendWhatsAppMessage;
use App\Models\Platform\Organization;
use App\Models\Platform\PlatformSetting;
use App\Models\Tenant\CommunicationChannel;
use App\Models\Tenant\MessageLog;
use App\Models\Tenant\MessageTemplate;
use App\Models\Tenant\WhatsAppTemplateProvider;
use App\Services\WhatsApp\Providers\DigiwareWhatsAppProvider;
use App\Services\WhatsApp\Providers\LogWhatsAppProvider;
use App\Services\WhatsApp\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TenantTestCase;

/**
 * The manager: which provider runs, what gets logged, what gets queued.
 *
 * What is asserted: each clinic gets the provider IT chose rather than a
 * global one; a send is queued rather than made to wait; the log row exists
 * from the moment a message is asked for; credentials are ciphertext at rest;
 * and neither a token nor a patient's name is ever written into the log.
 *
 * Every test runs inside onClinic(). These are service tests that never make
 * an HTTP request, so the middleware that normally points the connection at a
 * tenant never runs and the connection has to be established by hand.
 */
class WhatsAppManagerTest extends TenantTestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');

        $this->organization = $this->provisionOrganization('W');
        $this->grantModule($this->organization, 'communication');
    }

    private function onClinic(callable $callback): mixed
    {
        return $this->onTenant($this->organization, $callback);
    }

    private function channel(string $providerKey, array $credentials = []): void
    {
        CommunicationChannel::forChannel(CommunicationChannel::WHATSAPP)->forceFill([
            'provider_key' => $providerKey,
            'credentials' => $credentials,
            'is_connected' => true,
        ])->save();
    }

    private function template(string $name = 'appointment_confirmation'): MessageTemplate
    {
        return MessageTemplate::query()->create([
            'channel' => 'whatsapp',
            'name' => $name,
            'category' => 'Appointment',
            'content' => 'Hi {{patient_name}}, you are seeing {{doctor_name}} on {{date}}.',
            'status' => MessageTemplate::APPROVED,
        ]);
    }

    private function queuedLog(): MessageLog
    {
        return MessageLog::query()->create([
            'channel' => 'whatsapp',
            'recipient' => '919876543210',
            'template_name' => 'appointment_confirmation',
            'status' => MessageLog::QUEUED,
        ]);
    }

    /* ------------------------------ selection ----------------------------- */

    public function test_a_clinic_gets_the_provider_it_chose(): void
    {
        $this->onClinic(function () {
            $this->channel('digiware', ['vendor_uid' => 'v1', 'token' => 't1']);

            $provider = whatsapp($this->organization)->provider();

            $this->assertInstanceOf(DigiwareWhatsAppProvider::class, $provider);
            $this->assertSame('digiware', $provider->key());
        });
    }

    /**
     * A clinic that has configured nothing must not quietly borrow whatever
     * the environment happens to hold.
     */
    public function test_a_clinic_with_no_provider_falls_back_to_the_log_driver(): void
    {
        config(['whatsapp.default' => 'log']);

        $this->onClinic(function () {
            $this->assertInstanceOf(
                LogWhatsAppProvider::class,
                whatsapp($this->organization)->provider(),
            );
        });
    }

    public function test_an_unknown_provider_falls_back_rather_than_throwing(): void
    {
        $this->onClinic(function () {
            $this->channel('some-provider-that-was-removed');

            $this->assertInstanceOf(
                LogWhatsAppProvider::class,
                whatsapp($this->organization)->provider(),
            );
        });
    }

    /**
     * The normal case, now that the superadmin owns the account.
     *
     * The clinic has set nothing at all: no provider key and no credentials.
     * It still sends, through the platform's account, because there is one
     * commercial relationship with the provider and one number patients see.
     */
    public function test_a_clinic_that_set_nothing_sends_through_the_platforms_account(): void
    {
        config(['whatsapp.default' => 'log']);

        PlatformSetting::query()->create([
            'key' => PlatformSetting::WHATSAPP,
            'value' => ['provider_key' => 'digiware'],
            'secret' => ['vendor_uid' => 'platform-vendor', 'token' => 'platform-token'],
        ]);

        Http::fake(['*' => Http::response(['result' => 'success', 'data' => ['wamid' => 'w.1']], 200)]);

        $this->onClinic(function () {
            $this->template();

            $manager = whatsapp($this->organization);

            $this->assertInstanceOf(DigiwareWhatsAppProvider::class, $manager->provider());

            $manager->sendNow(
                WhatsAppMessage::template('919876543210', 'appointment_confirmation'),
                $this->queuedLog(),
            );

            Http::assertSent(fn ($request) => str_contains($request->url(), 'platform-vendor'));
        });
    }

    /**
     * A clinic that brings its own account still wins.
     *
     * The deliberate exception: not something a clinic administrator can
     * arrange for themselves, but the data path has to support it.
     */
    public function test_a_clinics_own_credentials_override_the_platforms(): void
    {
        PlatformSetting::query()->create([
            'key' => PlatformSetting::WHATSAPP,
            'value' => ['provider_key' => 'digiware'],
            'secret' => ['vendor_uid' => 'platform-vendor', 'token' => 'platform-token'],
        ]);

        Http::fake(['*' => Http::response(['result' => 'success'], 200)]);

        $this->onClinic(function () {
            $this->channel('digiware', ['vendor_uid' => 'clinic-vendor']);
            $this->template();

            whatsapp($this->organization)->sendNow(
                WhatsAppMessage::template('919876543210', 'appointment_confirmation'),
                $this->queuedLog(),
            );

            // Its own vendor, but the platform's token — merged rather than
            // either/or, so a half-configured clinic is not a broken one.
            Http::assertSent(fn ($request) => str_contains($request->url(), 'clinic-vendor')
                && str_contains($request->url(), 'platform-token'));
        });
    }

    /* -------------------------------- queue ------------------------------- */

    public function test_sending_queues_a_job_and_writes_a_queued_log_row(): void
    {
        Queue::fake();

        $this->onClinic(function () {
            $this->channel('digiware', ['vendor_uid' => 'v1', 'token' => 't1']);
            $this->template();

            $log = whatsapp($this->organization)->sendTemplate(
                phone: '+91 98765 43210',
                template: 'appointment_confirmation',
                variables: ['patient_name' => 'Amit', 'doctor_name' => 'Dr Rahul'],
            );

            $this->assertSame(MessageLog::QUEUED, $log->status);
            $this->assertSame('919876543210', $log->recipient);

            Queue::assertPushed(
                SendWhatsAppMessage::class,
                fn (SendWhatsAppMessage $job) => $job->logId === $log->id
                    && $job->organizationId === $this->organization->getKey()
                    && $job->variables['patient_name'] === 'Amit',
            );
        });
    }

    /** Patient names ride on the job body, whose row the queue deletes. */
    public function test_the_log_records_which_variables_were_used_but_not_their_values(): void
    {
        Queue::fake();

        $this->onClinic(function () {
            $this->channel('digiware', ['vendor_uid' => 'v1', 'token' => 't1']);
            $this->template();

            $log = whatsapp($this->organization)->sendTemplate(
                phone: '919876543210',
                template: 'appointment_confirmation',
                variables: ['patient_name' => 'Amit Kumar'],
            );

            $stored = json_encode($log->fresh()->request_payload);

            $this->assertStringContainsString('patient_name', $stored);
            $this->assertStringNotContainsString('Amit Kumar', $stored);
        });
    }

    /* ------------------------------- logging ------------------------------ */

    public function test_a_successful_send_records_sent_with_the_provider_id(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success', 'message_id' => 'wamid.9'], 200)]);

        $this->onClinic(function () {
            $this->channel('digiware', ['vendor_uid' => 'v1', 'token' => 't1']);
            $this->template();
            $log = $this->queuedLog();

            $response = whatsapp($this->organization)->sendNow(
                WhatsAppMessage::template('919876543210', 'appointment_confirmation', ['patient_name' => 'Amit']),
                $log,
            );

            $this->assertTrue($response->success);

            $fresh = $log->fresh();
            $this->assertSame(MessageLog::SENT, $fresh->status);
            $this->assertSame('wamid.9', $fresh->provider_message_id);
            $this->assertSame(1, $fresh->attempts);
        });
    }

    public function test_a_failed_send_records_why(): void
    {
        Http::fake(['*' => Http::response(['status' => 'error', 'message' => 'Template not found'], 422)]);

        $this->onClinic(function () {
            $this->channel('digiware', ['vendor_uid' => 'v1', 'token' => 't1']);
            $this->template();
            $log = $this->queuedLog();

            whatsapp($this->organization)->sendNow(
                WhatsAppMessage::template('919876543210', 'appointment_confirmation'),
                $log,
            );

            $fresh = $log->fresh();

            $this->assertSame(MessageLog::FAILED, $fresh->status);
            $this->assertSame('Template not found', $fresh->failure_reason);
            $this->assertNotNull($fresh->failed_at);
        });
    }

    /* ------------------------------ templates ----------------------------- */

    /** A template the provider rejected must not be attempted. */
    public function test_a_template_the_provider_rejected_is_refused_without_a_request(): void
    {
        Http::fake();

        $this->onClinic(function () {
            $this->channel('digiware', ['vendor_uid' => 'v1', 'token' => 't1']);
            $template = $this->template();

            WhatsAppTemplateProvider::query()->create([
                'message_template_id' => $template->id,
                'provider' => 'digiware',
                'status' => WhatsAppTemplateProvider::REJECTED,
            ]);

            $response = whatsapp($this->organization)->sendNow(
                WhatsAppMessage::template('919876543210', 'appointment_confirmation'),
                $this->queuedLog(),
            );

            $this->assertFalse($response->success);
            $this->assertSame('template', $response->errorCode);
            Http::assertNothingSent();
        });
    }

    /** The provider's own name for a template wins over the clinic's. */
    public function test_a_mapped_template_is_sent_under_the_providers_own_name(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success'], 200)]);

        $this->onClinic(function () {
            $this->channel('digiware', ['vendor_uid' => 'v1', 'token' => 't1']);
            $template = $this->template();

            WhatsAppTemplateProvider::query()->create([
                'message_template_id' => $template->id,
                'provider' => 'digiware',
                'provider_template_name' => 'appt_confirm_v2',
                'status' => WhatsAppTemplateProvider::APPROVED,
            ]);

            whatsapp($this->organization)->sendNow(
                WhatsAppMessage::template('919876543210', 'appointment_confirmation', ['patient_name' => 'Amit']),
                $this->queuedLog(),
            );

            Http::assertSent(fn ($request) => $request['template_name'] === 'appt_confirm_v2');
        });
    }

    /* ------------------------------- secrets ------------------------------ */

    public function test_credentials_are_ciphertext_in_the_database(): void
    {
        $this->onClinic(function () {
            $this->channel('digiware', ['token' => 'super-secret-token']);

            $raw = DB::connection('organization')
                ->table('communication_channels')
                ->where('channel', 'whatsapp')
                ->value('credentials');

            $this->assertNotNull($raw);
            $this->assertStringNotContainsString('super-secret-token', (string) $raw);

            // And still readable through the model.
            $this->assertSame(
                'super-secret-token',
                CommunicationChannel::forChannel('whatsapp')->credentials['token'],
            );
        });
    }

    public function test_secrets_are_stripped_from_anything_logged(): void
    {
        $this->onClinic(function () {
            $redacted = whatsapp($this->organization)->redact([
                'template_name' => 'appointment_confirmation',
                'token' => 'secret-token',
                'nested' => ['access_token' => 'another-secret', 'phone_number' => '919876543210'],
            ]);

            $this->assertSame('[redacted]', $redacted['token']);
            $this->assertSame('[redacted]', $redacted['nested']['access_token']);
            $this->assertSame('919876543210', $redacted['nested']['phone_number']);
        });
    }
}
