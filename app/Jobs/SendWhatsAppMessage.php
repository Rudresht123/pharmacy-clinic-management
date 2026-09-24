<?php

namespace App\Jobs;

use App\Models\Platform\Organization;
use App\Models\Tenant\MessageLog;
use App\Services\Tenancy\TenantConnectionService;
use App\Services\WhatsApp\WhatsAppManager;
use App\Services\WhatsApp\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppSendFailed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Hands one queued message to the provider.
 *
 * Carries an ORGANIZATION ID and a LOG ID, and nothing else. Not the message,
 * because the row already holds it; not the credentials, because a serialized
 * job body sits in the jobs table in the clear and a token there is a token in
 * the database.
 *
 * CONNECTS ITS OWN TENANT. A worker serves many clinics in one process and
 * the connection is shared mutable state — ResolveTenantFromHeader carries the
 * same warning for the same reason. Assuming the connection is already pointed
 * anywhere is how one clinic's message is written to another clinic's database.
 *
 * Retries are for TRANSIENT faults only. A provider that says "no such
 * template" will say it again; retrying that three times only delays somebody
 * finding out.
 */
class SendWhatsAppMessage implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, string|int|float|null>  $variables
     *         Carried here rather than on the log row: the jobs table row is
     *         deleted when this finishes, so patient names live for seconds
     *         instead of forever in a delivery log support staff can read.
     * @param  array<string, mixed>  $content
     *         What the log row cannot say: whether this is a template, free
     *         text or media, and the text itself. The row records the template
     *         NAME, so a job rebuilt from the row alone would turn every free
     *         text message into a lookup for a template that does not exist.
     */
    public function __construct(
        public readonly int $organizationId,
        public readonly int $logId,
        public readonly array $variables = [],
        public readonly array $content = [],
    ) {}

    public function tries(): int
    {
        return (int) config('whatsapp.queue.tries', 3);
    }

    public function timeout(): int
    {
        return (int) config('whatsapp.queue.timeout', 60);
    }

    /**
     * Seconds between attempts.
     *
     * A provider that is down tends to be down for minutes, so the gaps widen
     * rather than hammering it three times in as many seconds.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return config('whatsapp.queue.backoff', [10, 60, 300]);
    }

    public function handle(TenantConnectionService $tenants, WhatsAppManager $whatsapp): void
    {
        $organization = Organization::query()->find($this->organizationId);

        if ($organization === null || $organization->database_name === null) {
            // The clinic was removed between queueing and running. Nothing to
            // send and nowhere to record that, so it is dropped rather than
            // retried forever against a database that is gone.
            Log::warning('[whatsapp] organization missing, dropping message', [
                'organization_id' => $this->organizationId,
                'log_id' => $this->logId,
            ]);

            return;
        }

        $tenants->connect($organization->database_name);

        try {
            $this->send($whatsapp->forOrganization($organization));
        } finally {
            // Always, so the next job on this worker cannot inherit the
            // connection and read the wrong clinic's rows.
            $tenants->disconnect();
        }
    }

    private function send(WhatsAppManager $whatsapp): void
    {
        $log = MessageLog::query()->find($this->logId);

        if ($log === null) {
            return;
        }

        /*
         * Already dealt with.
         *
         * A job can run twice — a worker killed mid-flight leaves the message
         * reserved until it times out — and a patient receiving the same
         * reminder twice is worse than one arriving a minute late.
         */
        if ($log->status !== MessageLog::QUEUED) {
            return;
        }

        $response = $whatsapp->sendNow($this->message($log), $log);

        /*
         * A transient failure goes back on the queue by throwing; anything
         * else is final and the log already says why.
         *
         * Throwing on the LAST attempt would only add a noisy exception to a
         * row that is already marked failed, so it stops there.
         */
        if (! $response->success && $response->retryable && $this->attempts() < $this->tries()) {
            throw new WhatsAppSendFailed($response->message);
        }
    }

    /**
     * The message this job was queued for, rebuilt.
     *
     * Template is the default and the overwhelming majority: it is what an
     * appointment reminder is, and what WhatsApp permits outside a live
     * conversation. Text and media exist for the cases inside one.
     */
    private function message(MessageLog $log): WhatsAppMessage
    {
        $phone = (string) $log->recipient;

        return match ($this->content['type'] ?? WhatsAppMessage::TEMPLATE) {
            WhatsAppMessage::TEXT => WhatsAppMessage::text(
                phone: $phone,
                body: (string) ($this->content['body'] ?? ''),
            ),

            WhatsAppMessage::MEDIA => WhatsAppMessage::media(
                phone: $phone,
                mediaUrl: (string) ($this->content['media_url'] ?? ''),
                caption: $this->content['caption'] ?? null,
            ),

            default => WhatsAppMessage::template(
                phone: $phone,
                template: (string) $log->template_name,
                variables: $this->variables,
            ),
        };
    }

    /**
     * Every attempt is spent and it still has not gone.
     *
     * The row is marked failed here rather than left at queued, because a
     * message stuck at queued forever is indistinguishable from one the
     * worker has not reached yet.
     */
    public function failed(?Throwable $exception): void
    {
        try {
            $organization = Organization::query()->find($this->organizationId);

            if ($organization?->database_name === null) {
                return;
            }

            app(TenantConnectionService::class)->run(
                $organization->database_name,
                function () use ($exception) {
                    MessageLog::query()->where('id', $this->logId)->update([
                        'status' => MessageLog::FAILED,
                        'failed_at' => Carbon::now(),
                        'failure_reason' => mb_substr(
                            $exception?->getMessage() ?? 'Gave up after repeated failures.',
                            0,
                            500,
                        ),
                    ]);
                },
            );
        } catch (Throwable $inner) {
            // Never let the failure handler fail: it would mask the original.
            Log::error('[whatsapp] could not record a failed send', [
                'log_id' => $this->logId,
                'detail' => $inner->getMessage(),
            ]);
        }
    }
}
