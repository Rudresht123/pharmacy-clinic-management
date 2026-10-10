<?php

namespace App\Jobs;

use App\Models\Platform\Organization;
use App\Services\Push\DeviceRegistry;
use App\Services\Push\PushMessage;
use App\Services\Push\PushSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Push one message to some people of one organization.
 *
 * NEVER TOUCHES A TENANT DATABASE. Unlike SendWhatsAppMessage, nothing here
 * has to be read back: the wording was settled where the event happened, on
 * the tenant connection, and the devices live in the master database. So
 * there is no connection to point anywhere and no way for a worker that just
 * served another clinic to send from the wrong one — the organization is an
 * id on the job, and every device lookup is filtered by it.
 *
 * RETRIES ONLY WHAT FAILED. FCM answers per device, so when two phones got the
 * push and one did not, the follow-up goes to that one alone instead of
 * waking the other two again.
 */
class SendPushNotification implements ShouldQueue
{
    use Queueable;

    /** Rounds after the first for devices FCM was too busy to take. */
    private const RETRY_ROUNDS = 3;

    /**
     * @param  list<int>  $userIds  tenant users of THIS organization
     * @param  array<string, scalar|null>  $data  references only — see PushMessage
     * @param  list<string>|null  $onlyTokens  set on a retry round: just these
     */
    public function __construct(
        public readonly int $organizationId,
        public readonly array $userIds,
        public readonly string $title,
        public readonly string $body,
        public readonly array $data = [],
        public readonly ?array $onlyTokens = null,
        public readonly int $round = 0,
    ) {
        $this->onQueue((string) config('push.queue', 'default'));
    }

    public function handle(DeviceRegistry $devices, PushSender $sender): void
    {
        $organization = Organization::query()->find($this->organizationId);

        // Deleted or suspended since the event: nobody there should be woken.
        if ($organization === null || ! $organization->canSignIn()) {
            return;
        }

        $live = $devices->tokensFor($organization, $this->userIds);

        // On a retry round, only the devices that still need it AND are
        // still registered — one switched off in the meantime is skipped.
        $tokens = $this->onlyTokens === null
            ? $live
            : array_values(array_intersect($this->onlyTokens, $live));

        if ($tokens === []) {
            return;
        }

        $result = $sender->send(
            new PushMessage($this->title, $this->body, $this->data + [
                'organization' => $organization->uuid,
                // What the app compares with its own sign-in's clinic.
                'workspace' => $organization->subdomain,
            ]),
            $tokens,
        );

        $devices->invalidate($result->invalid);

        if ($result->failed !== []) {
            Log::warning('[push] refused by FCM', [
                'organization_id' => $this->organizationId,
                'devices' => count($result->failed),
                'type' => $this->data['type'] ?? null,
            ]);
        }

        if ($result->retry !== [] && $this->round < self::RETRY_ROUNDS) {
            self::dispatch(
                $this->organizationId,
                $this->userIds,
                $this->title,
                $this->body,
                $this->data,
                $result->retry,
                $this->round + 1,
            )->delay(now()->addSeconds(30 * 2 ** $this->round));
        }
    }
}
