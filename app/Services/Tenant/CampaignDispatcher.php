<?php

namespace App\Services\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\Campaign;
use App\Models\Tenant\CampaignRecipient;
use App\Models\Tenant\CommunicationChannel;
use App\Models\Tenant\Customer;
use App\Services\Communication\ChannelSender;
use App\Services\Tenancy\CurrentTenant;
use Illuminate\Support\Carbon;

/**
 * Sends a campaign: resolves who, writes their copies, and hands each message
 * to its channel.
 *
 * It used to stop at "queued" — a delivery-log row and nothing behind it —
 * from before any provider existed. Each message now goes through
 * ChannelSender, the same door the automation rules use, so a campaign is
 * held to the same switches: the communication module running, the channel
 * connected, the template active and approved, the patient reachable there.
 * One of the first three off and the campaign FAILS, saying which, rather
 * than quietly reaching nobody.
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
        private readonly ChannelSender $channels,
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
            $organization = CurrentTenant::organization();
            $problem = $this->cannotSend($campaign, $organization);

            if ($problem !== null) {
                $campaign->forceFill(['status' => Campaign::FAILED, 'failure_reason' => $problem])->save();

                return 0;
            }

            $written = $this->resolveRecipients($campaign, $organization);

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
     * Why this campaign cannot go out at all, or null when it can.
     *
     * Asked once, before anybody is written: the module, the connection and
     * the template are the same for every patient, so a campaign failing on
     * one fails cleanly with one sentence instead of a thousand empty rows.
     */
    private function cannotSend(Campaign $campaign, ?Organization $organization): ?string
    {
        if ($organization === null) {
            return 'The organisation could not be found.';
        }

        if (! in_array($campaign->channel, ChannelSender::DELIVERABLE, true)) {
            return 'This channel cannot send yet.';
        }

        $communication = $this->channels->communication($organization, null);

        if (! $communication['enabled']) {
            return 'Communication is switched off for the organisation.';
        }

        if (! ($communication['channels'][$campaign->channel] ?? false)) {
            return 'This channel is not connected.';
        }

        if (! $this->ownWording($campaign) && ! ChannelSender::sendable($campaign->template, $campaign->channel)) {
            return 'The campaign\'s template is not an approved, active template for this channel.';
        }

        return null;
    }

    /**
     * Whether the campaign carries its own words rather than a template's —
     * allowed for email and App notifications, never WhatsApp, whose messages
     * must be templates the provider approved.
     */
    private function ownWording(Campaign $campaign): bool
    {
        return in_array($campaign->channel, CommunicationChannel::REWORDABLE, true)
            && filled($campaign->content);
    }

    /**
     * Write one row per patient, and hand each message to its channel.
     *
     * Chunked so a clinic-sized audience is not loaded at once. NOT wrapped in
     * a transaction: each message's delivery job is dispatched as its row is
     * written, and a job a worker picks up before an enclosing transaction
     * commits would find no row to send. The recipients' unique key is what
     * makes a re-run safe instead.
     */
    private function resolveRecipients(Campaign $campaign, Organization $organization): int
    {
        $filters = $this->audience->filtersFor($campaign);
        $written = 0;

        $this->audience->query($campaign->channel, $filters)
            ->chunkById(self::CHUNK, function ($customers) use ($campaign, $organization, &$written) {
                foreach ($customers as $customer) {
                    $written += $this->writeOne($campaign, $customer, $organization) ? 1 : 0;
                }
            });

        return $written;
    }

    /**
     * One patient's copy, plus the message handed to its channel.
     *
     * Returns false when the row already existed — a re-run after a crash,
     * which must not send the message a second time — or when this patient
     * turned out to be unreachable, who is recorded as skipped.
     */
    private function writeOne(Campaign $campaign, Customer $customer, Organization $organization): bool
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

        $variables = $this->variables($customer, $organization);
        $extra = ['campaign_id' => $campaign->id, 'message_template_id' => $campaign->message_template_id];
        $data = ['type' => 'campaign', 'campaign_id' => $campaign->id];

        $log = $this->ownWording($campaign)
            ? $this->channels->queueWording(
                $organization,
                $campaign->channel,
                $customer,
                $campaign->subject ?: $campaign->template?->subject,
                (string) $campaign->content,
                $variables,
                $campaign->name,
                $extra,
                $data,
            )
            : $this->channels->queue(
                $organization,
                $campaign->channel,
                $customer,
                $campaign->template,
                $variables,
                // The patient's own branch, so its wording is the one they get.
                $customer->registered_location_id === null ? null : (int) $customer->registered_location_id,
                $extra,
                $data,
                $campaign->subject,
            );

        CampaignRecipient::query()->create([
            'campaign_id' => $campaign->id,
            'customer_id' => $customer->id,
            'recipient_name' => $customer->name,
            'recipient' => $address,
            // Handed to the channel; what happened next is on the log row.
            'status' => $log !== null ? CampaignRecipient::SENT : CampaignRecipient::SKIPPED,
        ]);

        return $log !== null;
    }

    /**
     * What a campaign's template may say about each patient.
     *
     * @return array<string, scalar|null>
     */
    private function variables(Customer $customer, Organization $organization): array
    {
        return [
            'patient_name' => $customer->name,
            'phone' => $customer->phone,
            'clinic_name' => $organization->organization_name,
            'date' => Carbon::now()->format('d M Y'),
        ];
    }
}
