<?php

namespace App\Services\Tenant;

use App\Models\Tenant\Campaign;
use App\Models\Tenant\CampaignRecipient;
use App\Models\Tenant\Customer;
use App\Models\Tenant\MessageLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sends a campaign: resolves who, writes their copies, queues the messages.
 *
 * Deliberately stops at "queued". Handing a message to WhatsApp or to an SMTP
 * server is a provider's job and there is no provider wired up yet — but
 * everything up to that point is real, and the moment one exists it reads the
 * queue this writes rather than needing any of this changed.
 *
 * Idempotent by construction. `campaign_recipients` is unique on
 * (campaign_id, customer_id), so a dispatcher that dies half way and runs
 * again re-inserts nothing and simply carries on from where it stopped.
 */
class CampaignDispatcher
{
    /**
     * How many patients are resolved at a time.
     *
     * A clinic with twelve thousand patients would otherwise load twelve
     * thousand models to write twelve thousand rows.
     */
    private const CHUNK = 500;

    public function __construct(
        private readonly AudienceResolver $audience,
    ) {}

    /**
     * Every campaign whose time has come.
     *
     * @return array{dispatched: int, recipients: int}
     */
    public function dispatchDue(?Carbon $at = null): array
    {
        $dispatched = 0;
        $recipients = 0;

        foreach (Campaign::query()->due($at)->get() as $campaign) {
            $recipients += $this->dispatch($campaign);
            $dispatched++;
        }

        return ['dispatched' => $dispatched, 'recipients' => $recipients];
    }

    /**
     * One campaign, start to finish.
     *
     * Marked `sending` before any work and `completed` after, so a campaign
     * caught mid-flight by a crash is visibly stuck rather than looking
     * scheduled forever.
     *
     * @return int how many patients it reached
     */
    public function dispatch(Campaign $campaign): int
    {
        $campaign->forceFill([
            'status' => Campaign::SENDING,
            'started_at' => Carbon::now(),
        ])->save();

        try {
            $written = $this->resolveRecipients($campaign);

            $campaign->forceFill([
                'status' => Campaign::COMPLETED,
                'completed_at' => Carbon::now(),
                'recipients_count' => $campaign->recipients()->count(),
                'sent_count' => $campaign->recipients()->where('status', CampaignRecipient::SENT)->count(),
            ])->save();

            return $written;
        } catch (\Throwable $exception) {
            // The reason is kept on the row: a campaign that failed at 2am
            // has to be able to explain itself at 9.
            $campaign->forceFill([
                'status' => Campaign::FAILED,
                'failure_reason' => mb_substr($exception->getMessage(), 0, 500),
            ])->save();

            throw $exception;
        }
    }

    /**
     * Write one row per patient, and one queued message each.
     *
     * Chunked, and each chunk in its own transaction: a clinic-sized audience
     * should not hold one transaction open for the whole send, and a chunk
     * that fails should not undo the ones already written.
     */
    private function resolveRecipients(Campaign $campaign): int
    {
        $filters = $this->audience->filtersFor($campaign);
        $written = 0;

        $this->audience->query($campaign->channel, $filters)
            ->chunkById(self::CHUNK, function ($customers) use ($campaign, &$written) {
                DB::connection('organization')->transaction(function () use ($campaign, $customers, &$written) {
                    foreach ($customers as $customer) {
                        $written += $this->writeOne($campaign, $customer) ? 1 : 0;
                    }
                });
            });

        return $written;
    }

    /**
     * One patient's copy, plus the message queued for them.
     *
     * Returns false when the row already existed — a re-run after a crash,
     * which must not queue the message a second time.
     */
    private function writeOne(Campaign $campaign, Customer $customer): bool
    {
        $address = $this->audience->addressFor($customer, $campaign->channel);

        if ($address === null || $address === '') {
            return false;
        }

        $existing = CampaignRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->where('customer_id', $customer->id)
            ->exists();

        if ($existing) {
            return false;
        }

        CampaignRecipient::query()->create([
            'campaign_id' => $campaign->id,
            'customer_id' => $customer->id,
            'recipient_name' => $customer->name,
            'recipient' => $address,
            'status' => CampaignRecipient::PENDING,
        ]);

        // The delivery log is one table across triggered and bulk sending, so
        // a campaign's messages appear in the same place as everything else.
        MessageLog::query()->create([
            'channel' => $campaign->channel,
            'customer_id' => $customer->id,
            'campaign_id' => $campaign->id,
            'message_template_id' => $campaign->message_template_id,
            'recipient_name' => $customer->name,
            'recipient' => $address,
            'template_name' => $campaign->name,
            'subject' => $campaign->subject,
            'status' => MessageLog::QUEUED,
            'scheduled_for' => $campaign->scheduled_at ?? Carbon::now(),
        ]);

        return true;
    }
}
