<?php

namespace App\Jobs;

use App\Models\Platform\Organization;
use App\Models\Tenant\MessageLog;
use App\Services\Push\DeviceRegistry;
use App\Services\Push\PushMessage;
use App\Services\Push\PushSender;
use App\Services\Tenancy\TenantConnectionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Hands one logged App notification to the person's phones.
 *
 * Mirrors SendEmailMessage — same tenancy rule, same once-only check, same
 * retry rule — because the delivery log is one table across channels and the
 * row has to end up saying what happened, whichever channel it is.
 *
 * CONNECTS ITS OWN TENANT for the log row, and disconnects in `finally`. The
 * phones themselves are in the master registry, found by organization and
 * login, never by anything the job body could have been told.
 */
class SendPushMessage implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array{user_ids?: list<int>, title?: string, body?: string, data?: array<string, scalar|null>}  $content
     *                                                                                                                  The wording rides here rather than on the log row, which
     *                                                                                                                  support staff read; the job row is deleted when it finishes.
     */
    public function __construct(
        public readonly int $organizationId,
        public readonly int $logId,
        public readonly array $content = [],
    ) {
        $this->onQueue((string) config('push.queue', 'default'));
    }

    public function tries(): int
    {
        return 3;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(TenantConnectionService $tenants, DeviceRegistry $devices, PushSender $sender): void
    {
        $organization = Organization::query()->find($this->organizationId);

        if ($organization === null || $organization->database_name === null) {
            Log::warning('[push] organization missing, dropping message', [
                'organization_id' => $this->organizationId,
                'log_id' => $this->logId,
            ]);

            return;
        }

        // Left as found afterwards: disconnected on a worker, the request's
        // own connection when run inline (see TenantConnectionService::run).
        $tenants->run($organization->database_name, fn () => $this->send($organization, $devices, $sender));
    }

    private function send(Organization $organization, DeviceRegistry $devices, PushSender $sender): void
    {
        $log = MessageLog::query()->find($this->logId);

        // Already dealt with — a worker killed mid-flight, a replay.
        if ($log === null || $log->status !== MessageLog::QUEUED) {
            return;
        }

        $tokens = $devices->tokensFor($organization, $this->content['user_ids'] ?? []);

        if ($tokens === []) {
            $this->fail($log, 'No phone is registered for app notifications yet.');

            return;
        }

        $result = $sender->send(
            new PushMessage(
                (string) ($this->content['title'] ?? ''),
                (string) ($this->content['body'] ?? ''),
                // `workspace` is what the app stores as its sign-in's clinic,
                // so a phone can tell a push from the clinic it is in now from
                // one sent before it moved.
                ($this->content['data'] ?? []) + ['organization' => $organization->uuid, 'workspace' => $organization->subdomain],
            ),
            $tokens,
        );

        $devices->invalidate($result->invalid);

        if ($result->sent !== []) {
            $log->forceFill(['status' => MessageLog::SENT, 'sent_at' => Carbon::now()])->save();

            return;
        }

        // Only busy — worth another go while attempts remain.
        if ($result->retry !== [] && $this->attempts() < $this->tries()) {
            throw new RuntimeException('Firebase was busy; trying again.');
        }

        $this->fail($log, match (true) {
            $result->invalid !== [] && $result->retry === [] && $result->failed === [] => 'The app has been removed or signed out on every registered phone.',
            config('push.driver', 'log') === 'log' => 'App notifications are not set up on this platform yet.',
            default => 'Firebase refused the notification.',
        });
    }

    private function fail(MessageLog $log, string $reason): void
    {
        $log->forceFill([
            'status' => MessageLog::FAILED,
            'failed_at' => Carbon::now(),
            'failure_reason' => $reason,
        ])->save();
    }

    /** Every attempt spent: say so on the row, rather than leave it "queued" forever. */
    public function failed(?Throwable $exception): void
    {
        try {
            $organization = Organization::query()->find($this->organizationId);

            if ($organization?->database_name === null) {
                return;
            }

            app(TenantConnectionService::class)->run(
                $organization->database_name,
                fn () => MessageLog::query()->where('id', $this->logId)->where('status', MessageLog::QUEUED)->update([
                    'status' => MessageLog::FAILED,
                    'failed_at' => Carbon::now(),
                    'failure_reason' => mb_substr($exception?->getMessage() ?? 'Gave up after repeated failures.', 0, 500),
                ]),
            );
        } catch (Throwable $inner) {
            Log::error('[push] could not record a failed send', ['log_id' => $this->logId, 'detail' => $inner->getMessage()]);
        }
    }
}
