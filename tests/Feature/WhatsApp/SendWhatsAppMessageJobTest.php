<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\SendWhatsAppMessage;
use App\Models\Platform\Organization;
use App\Models\Tenant\CommunicationChannel;
use App\Models\Tenant\MessageLog;
use App\Models\Tenant\MessageTemplate;
use App\Services\Tenancy\TenantConnectionService;
use App\Services\WhatsApp\WhatsAppManager;
use App\Services\WhatsApp\WhatsAppSendFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TenantTestCase;

/**
 * The job that actually hands a queued message to the provider.
 *
 * What is asserted: the job finds its own tenant rather than inheriting one;
 * it leaves the connection where it found it; a free-text message is not
 * turned into a template lookup; a message already dealt with is left alone;
 * and only a transient fault goes back on the queue.
 *
 * These run WITHOUT a tenant connection established, deliberately. That is the
 * state a queue worker is in, and a job that only works when something else
 * has already connected is a job that works in tests and not in production.
 */
class SendWhatsAppMessageJobTest extends TenantTestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');

        $this->organization = $this->provisionOrganization('J');
        $this->grantModule($this->organization, 'communication');

        $this->onTenant($this->organization, function () {
            CommunicationChannel::forChannel(CommunicationChannel::WHATSAPP)->forceFill([
                'provider_key' => 'digiware',
                'credentials' => ['vendor_uid' => 'v1', 'token' => 't1'],
                'is_connected' => true,
            ])->save();
        });
    }

    /** A queued row, written the way the manager writes one. */
    private function queuedLog(string $templateName = 'appointment_confirmation'): MessageLog
    {
        return $this->onTenant($this->organization, function () use ($templateName) {
            if ($templateName !== 'Test message') {
                MessageTemplate::query()->create([
                    'channel' => 'whatsapp',
                    'name' => $templateName,
                    'category' => 'Appointment',
                    'content' => 'Hi {{patient_name}}, see you on {{date}}.',
                    'status' => MessageTemplate::APPROVED,
                ]);
            }

            return MessageLog::query()->create([
                'channel' => 'whatsapp',
                'provider' => 'digiware',
                'recipient' => '919876543210',
                'template_name' => $templateName,
                'status' => MessageLog::QUEUED,
            ]);
        });
    }

    private function perform(SendWhatsAppMessage $job): void
    {
        $job->handle(app(TenantConnectionService::class), app(WhatsAppManager::class));
    }

    private function refresh(int $id): MessageLog
    {
        return $this->onTenant($this->organization, fn () => MessageLog::query()->find($id));
    }

    /* ------------------------------- tenancy ------------------------------ */

    /**
     * The whole point of carrying an organization id.
     *
     * No connection is established before this runs — if the job did not make
     * its own, it would be reading whatever database the worker touched last.
     */
    public function test_the_job_connects_its_own_tenant(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success', 'message_id' => 'wamid.1'], 200)]);

        $log = $this->queuedLog();

        $this->perform(new SendWhatsAppMessage($this->organization->getKey(), $log->id, ['patient_name' => 'Amit']));

        $this->assertSame(MessageLog::SENT, $this->refresh($log->id)->status);
    }

    /** Otherwise the next job on this worker reads the wrong clinic's rows. */
    public function test_the_connection_is_released_even_when_the_send_throws(): void
    {
        Http::fake(fn () => throw new ConnectionException('down'));

        $log = $this->queuedLog();

        try {
            $this->perform(new SendWhatsAppMessage($this->organization->getKey(), $log->id));
        } catch (WhatsAppSendFailed) {
            // Expected: a connection fault is transient and goes back on the queue.
        }

        $this->assertNull(
            config('database.connections.organization.database'),
            'The job left a tenant connection behind.',
        );
    }

    /** The clinic was removed between queueing and running. */
    public function test_a_missing_organization_is_dropped_rather_than_retried(): void
    {
        Http::fake();

        $this->perform(new SendWhatsAppMessage(9_999_999, 1));

        Http::assertNothingSent();
    }

    /* ------------------------------- content ------------------------------ */

    /**
     * The log row records a template NAME, so a job rebuilt from the row alone
     * turns every free-text message into a lookup for a template that does not
     * exist. The shape has to travel with the job.
     */
    public function test_a_free_text_message_is_not_turned_into_a_template_lookup(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success'], 200)]);

        $log = $this->queuedLog('Test message');

        $this->perform(new SendWhatsAppMessage(
            $this->organization->getKey(),
            $log->id,
            [],
            ['type' => 'text', 'body' => 'Checking the connection'],
        ));

        $fresh = $this->refresh($log->id);

        $this->assertNotSame(
            'No approved \'Test message\' template for this provider.',
            $fresh->failure_reason,
            'A free-text message was resolved as a template.',
        );
    }

    public function test_a_template_message_is_sent_with_the_variables_the_job_carried(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success'], 200)]);

        $log = $this->queuedLog();

        $this->perform(new SendWhatsAppMessage(
            $this->organization->getKey(),
            $log->id,
            ['patient_name' => 'Amit', 'date' => '25 Sep'],
        ));

        Http::assertSent(fn ($request) => $request['field_1'] === 'Amit' && $request['field_2'] === '25 Sep');
    }

    /* ----------------------------- idempotence ---------------------------- */

    /**
     * A worker killed mid-flight leaves the message reserved until it times
     * out, and the job runs again. A patient receiving the same reminder twice
     * is worse than one arriving a minute late.
     */
    public function test_a_message_already_sent_is_not_sent_again(): void
    {
        Http::fake();

        $log = $this->queuedLog();

        $this->onTenant($this->organization, fn () => MessageLog::query()
            ->where('id', $log->id)
            ->update(['status' => MessageLog::SENT]));

        $this->perform(new SendWhatsAppMessage($this->organization->getKey(), $log->id));

        Http::assertNothingSent();
    }

    public function test_a_log_row_that_is_gone_is_not_an_error(): void
    {
        Http::fake();

        $this->perform(new SendWhatsAppMessage($this->organization->getKey(), 9_999_999));

        Http::assertNothingSent();
    }

    /* ------------------------------- retries ------------------------------ */

    public function test_a_transient_fault_goes_back_on_the_queue(): void
    {
        Http::fake(['*' => Http::response('gateway down', 503)]);

        $log = $this->queuedLog();

        $this->expectException(WhatsAppSendFailed::class);

        $this->perform(new SendWhatsAppMessage($this->organization->getKey(), $log->id));
    }

    /**
     * A provider that says "no such template" will say it again. Retrying only
     * delays somebody finding out.
     */
    public function test_a_refusal_is_recorded_and_not_retried(): void
    {
        Http::fake(['*' => Http::response(['status' => 'error', 'message' => 'Template not found'], 422)]);

        $log = $this->queuedLog();

        $this->perform(new SendWhatsAppMessage($this->organization->getKey(), $log->id));

        $fresh = $this->refresh($log->id);

        $this->assertSame(MessageLog::FAILED, $fresh->status);
        $this->assertSame('Template not found', $fresh->failure_reason);
    }

    /**
     * Every attempt spent. A row left at queued forever is indistinguishable
     * from one the worker has not reached yet.
     */
    public function test_giving_up_marks_the_row_failed(): void
    {
        $log = $this->queuedLog();

        (new SendWhatsAppMessage($this->organization->getKey(), $log->id))
            ->failed(new WhatsAppSendFailed('Gateway timed out'));

        $fresh = $this->refresh($log->id);

        $this->assertSame(MessageLog::FAILED, $fresh->status);
        $this->assertSame('Gateway timed out', $fresh->failure_reason);
        $this->assertNotNull($fresh->failed_at);
    }
}
