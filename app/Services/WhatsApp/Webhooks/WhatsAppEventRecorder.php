<?php

namespace App\Services\WhatsApp\Webhooks;

use App\Models\Tenant\MessageLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Moves a message along, from whatever a provider told us.
 *
 * Provider-independent by construction: it only ever sees NormalizedWhatsAppEvent,
 * so a new provider is a new parser and nothing here changes.
 */
class WhatsAppEventRecorder
{
    /**
     * Statuses in the order a message passes through them.
     *
     * Receipts arrive out of order more often than anyone expects — a `read`
     * can overtake its own `delivered` — and without this a message that has
     * been read gets walked backwards to delivered by a late receipt.
     */
    private const PROGRESSION = [
        MessageLog::QUEUED => 0,
        MessageLog::SENT => 1,
        MessageLog::DELIVERED => 2,
        MessageLog::READ => 3,
    ];

    /**
     * @param  list<NormalizedWhatsAppEvent>  $events
     * @return int how many matched a message we know about
     */
    public function record(array $events): int
    {
        $matched = 0;

        foreach ($events as $event) {
            $matched += $this->apply($event) ? 1 : 0;
        }

        return $matched;
    }

    private function apply(NormalizedWhatsAppEvent $event): bool
    {
        $status = $event->status();

        if ($status === null || $event->providerMessageId === null) {
            return false;
        }

        $log = MessageLog::query()
            ->where('provider', $event->provider)
            ->where('provider_message_id', $event->providerMessageId)
            ->first();

        if ($log === null) {
            /*
             * A receipt for something we did not send.
             *
             * Normal rather than alarming: a clinic that switched providers,
             * or a shared number, produces these. Logged at debug so it can be
             * found when somebody is looking, and ignored otherwise.
             */
            Log::debug('[whatsapp] receipt for an unknown message', [
                'provider' => $event->provider,
                'provider_message_id' => $event->providerMessageId,
            ]);

            return false;
        }

        $at = $event->occurredAt !== null
            ? Carbon::parse($event->occurredAt)
            : Carbon::now();

        // A failure can arrive at any point and always wins; progress only
        // ever moves forwards.
        if ($status !== MessageLog::FAILED && ! $this->advances($log->status, $status)) {
            return true;
        }

        $log->forceFill(array_filter([
            'status' => $status,
            'sent_at' => $status === MessageLog::SENT ? ($log->sent_at ?? $at) : $log->sent_at,
            'delivered_at' => $status === MessageLog::DELIVERED ? ($log->delivered_at ?? $at) : $log->delivered_at,
            'read_at' => $status === MessageLog::READ ? ($log->read_at ?? $at) : $log->read_at,
            'failed_at' => $status === MessageLog::FAILED ? $at : $log->failed_at,
            'error_code' => $event->errorCode ?? $log->error_code,
            'failure_reason' => $event->errorMessage !== null
                ? mb_substr($event->errorMessage, 0, 500)
                : $log->failure_reason,
        ], static fn ($value) => $value !== null))->save();

        return true;
    }

    /** Whether the new status is further along than the one recorded. */
    private function advances(string $current, string $next): bool
    {
        return (self::PROGRESSION[$next] ?? 0) > (self::PROGRESSION[$current] ?? 0);
    }
}
