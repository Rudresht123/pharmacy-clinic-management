<?php

namespace App\Services\Subscriptions;

use App\Models\Platform\Organization;
use App\Models\Platform\UsageBalance;
use App\Models\Platform\UsageRecord;
use App\Services\Communication\ChannelSender;
use App\Support\Subscriptions\Entitlements;
use App\Support\Subscriptions\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Meters WhatsApp, email, SMS and video usage against the month's allowance,
 * and records every billable event in the usage ledger — exactly once.
 *
 * ONE RECORD PER REAL EVENT. The idempotency key names the event itself —
 * `{organization}:{channel}:{reference_type}:{reference_id}`, the reference
 * being the tenant's own message log row or video call — so a retried job, a
 * duplicate delivery callback or a second "end call" all arrive at the same
 * key and record nothing new.
 *
 * THE CHARGE AND THE QUOTA MOVE TOGETHER. Recording takes the month's balance
 * row FOR UPDATE, checks the key, inserts the record and moves the balance in
 * one master-database transaction. A charge with no quota movement, or the
 * reverse, cannot exist.
 *
 * SMS IS DORMANT. Its rates and quotas can be configured, but nothing is
 * metered on a channel with no live provider (ChannelSender::DELIVERABLE).
 * When an SMS driver is added there, metering starts with no change here.
 */
class UsageMeter
{
    public function __construct(
        private readonly SubscriptionFacts $facts,
        private readonly UsageBalances $balances,
        private readonly PricingCatalog $catalog,
        private readonly SubscriptionClock $clock,
    ) {}

    /** Channels that are billed today: a live provider, or video. */
    public static function isMetered(string $channel): bool
    {
        return $channel === 'video' || in_array($channel, ChannelSender::DELIVERABLE, true);
    }

    /**
     * May `$quantity` units go out now? Asked BEFORE sending.
     *
     * Locked → no. Video without the feature → no. Quota left, or unlimited
     * → yes. Quota spent → yes only if the channel's rate allows overage.
     */
    public function authorize(
        Organization $organization,
        string $channel,
        int $quantity = 1,
        ?string $category = null,
        ?string $provider = null,
    ): UsageAuthorization {
        if (! self::isMetered($channel)) {
            return UsageAuthorization::allow(0, 0);
        }

        $subscription = $this->facts->subscription($organization);

        if ($subscription !== null && $this->clock->isLocked($subscription)) {
            return UsageAuthorization::deny('locked', 'The subscription has ended. Renew it to send again.');
        }

        if ($channel === 'video' && ! $this->facts->hasFeature($organization, Entitlements::VIDEO_CONSULTATION)) {
            return UsageAuthorization::deny('feature', 'Video consultation is not part of your plan.');
        }

        $balance = $this->balances->current($organization, Entitlements::CHANNEL_QUOTA[$channel]);
        $remaining = $balance->remaining();

        if ($remaining === null || $remaining >= $quantity) {
            return UsageAuthorization::allow($quantity, 0);
        }

        $rate = $this->catalog->rateFor($channel, $provider, $category);

        if ($rate !== null && $rate->overage_policy === 'allow') {
            return UsageAuthorization::allow($remaining, $quantity - $remaining);
        }

        return UsageAuthorization::deny('quota_exhausted', sprintf(
            'This month\'s %s are used up. Buy more credits or upgrade your plan.',
            Entitlements::label(Entitlements::CHANNEL_QUOTA[$channel]),
        ));
    }

    /**
     * Record one billable event. Safe to call any number of times for the
     * same reference: the first call records it, every later one returns the
     * record the first wrote.
     *
     * @param  array<string, mixed>  $meta
     */
    public function record(
        Organization $organization,
        string $channel,
        string $referenceType,
        int $referenceId,
        int $quantity,
        ?string $provider = null,
        ?string $category = null,
        ?string $destination = null,
        array $meta = [],
        ?CarbonInterface $occurredAt = null,
    ): ?UsageRecord {
        if (! self::isMetered($channel) || $quantity < 1) {
            return null;
        }

        $key = self::key($organization, $channel, $referenceType, $referenceId);
        $occurredAt ??= Carbon::now();
        $quota = Entitlements::CHANNEL_QUOTA[$channel];

        // Opened outside the transaction: it is idempotent itself, and the
        // lock below is what serializes the spending.
        $balance = $this->balances->current($organization, $quota, $occurredAt);

        return DB::transaction(function () use ($organization, $channel, $referenceType, $referenceId, $quantity, $provider, $category, $destination, $meta, $occurredAt, $key, $balance) {
            /** @var UsageBalance $balance */
            $balance = UsageBalance::query()->whereKey($balance->id)->lockForUpdate()->firstOrFail();

            $existing = UsageRecord::query()->where('idempotency_key', $key)->first();

            if ($existing !== null) {
                return $existing;
            }

            $remaining = $balance->remaining();
            $fromQuota = $remaining === null ? $quantity : min($quantity, $remaining);
            $overage = $quantity - $fromQuota;

            $rate = $this->catalog->rateFor($channel, $provider, $category, $destination);
            $unitPrice = $rate?->unitPriceAt($balance->used) ?? 0;
            $charge = Money::e4ToPaise($unitPrice * $overage);

            $inserted = UsageRecord::query()->insertOrIgnore([
                'organization_id' => $organization->getKey(),
                'subscription_id' => $this->facts->subscription($organization)?->id,
                'channel' => $channel,
                'provider' => $provider,
                'category' => $category,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'quantity' => $quantity,
                'unit' => $rate?->billing_unit ?? $this->defaultUnit($channel),
                'usage_rate_id' => $rate?->id,
                'provider_cost_e4' => ($rate?->provider_cost_e4 ?? 0) * $quantity,
                'price_e4' => $unitPrice,
                'from_quota' => $fromQuota,
                'overage' => $overage,
                'charge_paise' => $charge,
                'status' => UsageRecord::RECORDED,
                'occurred_at' => $occurredAt,
                'idempotency_key' => $key,
                'meta' => $meta === [] ? null : json_encode($meta),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            $record = UsageRecord::query()->where('idempotency_key', $key)->firstOrFail();

            // Only the call that actually inserted moves the balance.
            if ($inserted === 1) {
                $balance->forceFill([
                    'used' => $balance->used + $quantity,
                    'overage' => $balance->overage + $overage,
                    'overage_charge_paise' => $balance->overage_charge_paise + $charge,
                ])->save();
            }

            return $record;
        });
    }

    /**
     * Undo a charge for a send the provider later reported failed — unless
     * the rate it was charged at bills failures. Writes one reversal row
     * (idempotent the same way) and gives the units back; the original row is
     * never deleted.
     */
    public function reverse(Organization $organization, string $channel, string $referenceType, int $referenceId, string $reason): ?UsageRecord
    {
        $original = UsageRecord::query()
            ->where('idempotency_key', self::key($organization, $channel, $referenceType, $referenceId))
            ->where('status', UsageRecord::RECORDED)
            ->first();

        if ($original === null || ($original->rate?->charge_failed ?? false)) {
            return null;
        }

        $key = $original->idempotency_key.':reversal';
        $balance = $this->balances->current($organization, Entitlements::CHANNEL_QUOTA[$channel], $original->occurred_at);

        return DB::transaction(function () use ($original, $key, $balance, $reason) {
            $balance = UsageBalance::query()->whereKey($balance->id)->lockForUpdate()->firstOrFail();

            $inserted = UsageRecord::query()->insertOrIgnore([
                'organization_id' => $original->organization_id,
                'subscription_id' => $original->subscription_id,
                'channel' => $original->channel,
                'provider' => $original->provider,
                'category' => $original->category,
                'reference_type' => $original->reference_type,
                'reference_id' => $original->reference_id,
                'quantity' => $original->quantity,
                'unit' => $original->unit,
                'usage_rate_id' => $original->usage_rate_id,
                'provider_cost_e4' => $original->provider_cost_e4,
                'price_e4' => $original->price_e4,
                'from_quota' => $original->from_quota,
                'overage' => $original->overage,
                'charge_paise' => $original->charge_paise,
                'status' => UsageRecord::REVERSED,
                'reverses_id' => $original->id,
                'occurred_at' => Carbon::now(),
                'idempotency_key' => $key,
                'meta' => json_encode(['reason' => mb_substr($reason, 0, 300)]),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            if ($inserted === 1) {
                $this->balances->refund($balance, $original->from_quota, $original->overage, $original->charge_paise);
            }

            return UsageRecord::query()->where('idempotency_key', $key)->first();
        });
    }

    public static function key(Organization $organization, string $channel, string $referenceType, int $referenceId): string
    {
        return "{$organization->getKey()}:{$channel}:{$referenceType}:{$referenceId}";
    }

    private function defaultUnit(string $channel): string
    {
        return match ($channel) {
            'email' => 'email',
            'video' => 'minute',
            default => 'message',
        };
    }
}
