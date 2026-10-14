<?php

namespace App\Services\Subscriptions;

use App\Models\Platform\Organization;
use App\Models\Platform\UsageBalance;
use App\Support\Subscriptions\Entitlements;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Each organization's monthly allowance of every quota, and what is spent.
 *
 * THE PERIOD IS THE CALENDAR MONTH, in the organization's timezone — the same
 * for monthly and annual plans, because the plan's quotas are "per month"
 * either way. A row is opened lazily the first time a month is asked about,
 * with `included` copied from the subscription at that moment.
 *
 * Spending is one conditional UPDATE, never read-modify-write, so two sends
 * racing for the last credit cannot both have it.
 */
class UsageBalances
{
    public function __construct(
        private readonly SubscriptionFacts $facts,
    ) {}

    /** This month's row for one quota, opened if it is the first ask. */
    public function current(Organization $organization, string $quota, ?CarbonInterface $at = null): UsageBalance
    {
        [$start, $end] = $this->period($organization, $at);

        $existing = UsageBalance::query()
            ->where('organization_id', $organization->getKey())
            ->where('quota_key', $quota)
            ->where('period_start', $start)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        // ON CONFLICT DO NOTHING: two requests opening the same month both
        // succeed, and both then read the one row that exists.
        UsageBalance::query()->insertOrIgnore([
            'organization_id' => $organization->getKey(),
            'subscription_id' => $this->facts->subscription($organization)?->id,
            'quota_key' => $quota,
            'period_start' => $start,
            'period_end' => $end,
            'included' => $this->facts->limit($organization, $quota),
            'purchased' => 0,
            'granted' => 0,
            'used' => 0,
            'overage' => 0,
            'overage_charge_paise' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return UsageBalance::query()
            ->where('organization_id', $organization->getKey())
            ->where('quota_key', $quota)
            ->where('period_start', $start)
            ->firstOrFail();
    }

    /**
     * Take `$quantity` from the allowance. Returns how many came out of it —
     * all of them, or 0 when there was not enough left. Never part: a
     * message is one unit and is either covered or it is not.
     */
    public function consume(UsageBalance $balance, int $quantity): int
    {
        if ($balance->included === null) {
            UsageBalance::query()->whereKey($balance->id)->update([
                'used' => DB::raw('used + '.(int) $quantity),
            ]);

            return $quantity;
        }

        $taken = UsageBalance::query()
            ->whereKey($balance->id)
            ->whereRaw('(included + purchased + granted - used) >= ?', [$quantity])
            ->update([
                'used' => DB::raw('used + '.(int) $quantity),
            ]);

        return $taken === 1 ? $quantity : 0;
    }

    /** Record units charged past the allowance. */
    public function addOverage(UsageBalance $balance, int $quantity, int $chargePaise): void
    {
        UsageBalance::query()->whereKey($balance->id)->update([
            'used' => DB::raw('used + '.(int) $quantity),
            'overage' => DB::raw('overage + '.(int) $quantity),
            'overage_charge_paise' => DB::raw('overage_charge_paise + '.(int) $chargePaise),
        ]);
    }

    /** Give units back — a reversed charge. */
    public function refund(UsageBalance $balance, int $fromQuota, int $overage, int $chargePaise): void
    {
        UsageBalance::query()->whereKey($balance->id)->update([
            'used' => DB::raw('GREATEST(0, used - '.(int) ($fromQuota + $overage).')'),
            'overage' => DB::raw('GREATEST(0, overage - '.(int) $overage.')'),
            'overage_charge_paise' => DB::raw('GREATEST(0, overage_charge_paise - '.(int) $chargePaise.')'),
        ]);
    }

    /** A Super Admin's free credits, added to this month. */
    public function grant(Organization $organization, string $quota, int $amount): UsageBalance
    {
        $balance = $this->current($organization, $quota);

        UsageBalance::query()->whereKey($balance->id)->update([
            'granted' => DB::raw('granted + '.(int) $amount),
        ]);

        return $balance->refresh();
    }

    /** A bought credit pack, added to this month. */
    public function purchase(Organization $organization, string $quota, int $amount): UsageBalance
    {
        $balance = $this->current($organization, $quota);

        UsageBalance::query()->whereKey($balance->id)->update([
            'purchased' => DB::raw('purchased + '.(int) $amount),
        ]);

        return $balance->refresh();
    }

    /**
     * Re-read the allowance after the subscription changed — a new plan, an
     * upgrade, a renewal — so this month's included amount is the new one.
     * Spent units and bought/granted credits are kept.
     */
    public function refreshIncluded(Organization $organization): void
    {
        [$start] = $this->period($organization);
        $this->facts->forget($organization);

        foreach (Entitlements::QUOTAS as $quota) {
            UsageBalance::query()
                ->where('organization_id', $organization->getKey())
                ->where('quota_key', $quota)
                ->where('period_start', $start)
                ->update([
                    'included' => $this->facts->limit($organization, $quota),
                    'subscription_id' => $this->facts->subscription($organization)?->id,
                ]);
        }
    }

    /**
     * This month for every quota, as the screens show it.
     *
     * @return array<string, array<string, mixed>>
     */
    public function summary(Organization $organization): array
    {
        $rows = [];

        foreach (Entitlements::QUOTAS as $quota) {
            $balance = $this->current($organization, $quota);

            $rows[$quota] = [
                'key' => $quota,
                'label' => Entitlements::label($quota),
                'unit' => Entitlements::UNITS[$quota] ?? 'units',
                'unlimited' => $balance->isUnlimited(),
                'included' => $balance->included,
                'purchased' => $balance->purchased,
                'granted' => $balance->granted,
                'allowance' => $balance->allowance(),
                'used' => $balance->used,
                'remaining' => $balance->remaining(),
                'overage' => $balance->overage,
                'overage_charge' => $balance->overage_charge_paise,
                'period_start' => $balance->period_start->toIso8601String(),
                'period_end' => $balance->period_end->toIso8601String(),
            ];
        }

        return $rows;
    }

    /**
     * The calendar month containing `$at`, in the organization's timezone,
     * as instants in the application's.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function period(Organization $organization, ?CarbonInterface $at = null): array
    {
        $timezone = $organization->timezone ?: config('app.timezone');
        $local = Carbon::instance($at ?? Carbon::now())->setTimezone($timezone);

        return [
            $local->copy()->startOfMonth()->setTimezone(config('app.timezone')),
            $local->copy()->endOfMonth()->setTimezone(config('app.timezone')),
        ];
    }
}
