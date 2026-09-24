<?php

namespace App\Jobs;

use App\Models\Platform\Organization;
use App\Models\Tenant\MessageLog;
use App\Services\Email\EmailManager;
use App\Services\Email\EmailMessage;
use App\Services\Tenancy\TenantConnectionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Hands one queued email to the clinic's mail server.
 *
 * Mirrors SendWhatsAppMessage deliberately — same tenancy rule, same
 * idempotence check, same retry rule — because the two are the same problem
 * and two jobs that solve it differently is how one of them ends up wrong.
 *
 * CONNECTS ITS OWN TENANT. A worker serves many clinics in one process and the
 * connection is shared mutable state; assuming it is already pointed anywhere
 * is how one clinic's mail is written to another clinic's database.
 */
class SendEmailMessage implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $content
     *         Subject, body and variables ride here rather than on the log
     *         row: the jobs table row is deleted when this finishes, so
     *         patient names live for seconds instead of forever in a delivery
     *         log support staff can read.
     */
    public function __construct(
        public readonly int $organizationId,
        public readonly int $logId,
        public readonly array $content = [],
    ) {}

    public function tries(): int
    {
        return (int) config('email.queue.tries', 3);
    }

    public function timeout(): int
    {
        return (int) config('email.queue.timeout', 60);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return config('email.queue.backoff', [10, 60, 300]);
    }

    public function handle(TenantConnectionService $tenants, EmailManager $email): void
    {
        $organization = Organization::query()->find($this->organizationId);

        if ($organization === null || $organization->database_name === null) {
            // The clinic was removed between queueing and running. Nothing to
            // send and nowhere to record it, so it is dropped rather than
            // retried forever against a database that is gone.
            Log::warning('[email] organization missing, dropping message', [
                'organization_id' => $this->organizationId,
                'log_id' => $this->logId,
            ]);

            return;
        }

        $tenants->connect($organization->database_name);

        try {
            $this->send($email->forOrganization($organization));
        } finally {
            // Always, so the next job on this worker cannot inherit the
            // connection and read the wrong clinic's rows.
            $tenants->disconnect();
        }
    }

    private function send(EmailManager $email): void
    {
        $log = MessageLog::query()->find($this->logId);

        if ($log === null) {
            return;
        }

        /*
         * Already dealt with. A worker killed mid-flight leaves the message
         * reserved until it times out, and a patient receiving the same
         * confirmation twice is worse than one arriving a minute late.
         */
        if ($log->status !== MessageLog::QUEUED) {
            return;
        }

        $result = $email->sendNow(
            EmailMessage::make(
                to: (string) ($this->content['to'] ?? $log->recipient),
                subject: (string) ($this->content['subject'] ?? $log->subject),
                body: (string) ($this->content['body'] ?? ''),
                template: $this->content['template'] ?? $log->template_name,
                variables: $this->content['variables'] ?? [],
            ),
            $log,
        );

        /*
         * A transient failure goes back on the queue by throwing; anything
         * else is final and the log already says why.
         *
         * Throwing on the LAST attempt would only add a noisy exception to a
         * row that is already marked failed, so it stops there.
         */
        if (! $result->success && $result->retryable && $this->attempts() < $this->tries()) {
            throw new RuntimeException($result->message);
        }
    }

    /**
     * Every attempt is spent and it still has not gone.
     *
     * Marked failed here rather than left at queued, because a message stuck
     * at queued forever is indistinguishable from one the worker has not
     * reached yet.
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
            Log::error('[email] could not record a failed send', [
                'log_id' => $this->logId,
                'detail' => $inner->getMessage(),
            ]);
        }
    }
}
