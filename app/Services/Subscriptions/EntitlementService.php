<?php

namespace App\Services\Subscriptions;

use App\Models\Platform\Addon;
use App\Models\Platform\Module;
use App\Models\Platform\Organization;
use App\Models\Platform\Plan;
use App\Models\Platform\Subscription;
use App\Services\Modules\ModuleAccess;
use App\Support\Subscriptions\Entitlements;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * What an organization may do, commercially — the one place that answers it.
 *
 * Every gate asks this: the `subscription` middleware (may this request run at
 * all), the resource limiter (may one more doctor be added), the usage meter
 * (may one more message go), the session payload and the dashboard card
 * (what to tell the clinic). None of them re-derive an answer from the rows,
 * which is how a rule ends up enforced in one place and forgotten in another.
 *
 * Two halves, kept apart on purpose:
 *
 *   WHETHER it is running — SubscriptionClock, from the dates, every time.
 *     Never cached: an expiry has to bite at its exact moment.
 *   WHAT it grants — SubscriptionFacts, cached under `entitlements_version`,
 *     which the lifecycle bumps on every change, so a renewal or an override
 *     is visible on the next request.
 */
class EntitlementService
{
    public function __construct(
        private readonly SubscriptionFacts $facts,
        private readonly SubscriptionClock $clock,
        private readonly BillingSettings $settings,
        private readonly ?ModuleAccess $modules = null,
        private readonly ?ResourceCounter $counter = null,
        private readonly ?UsageBalances $balances = null,
    ) {}

    public function subscription(Organization $organization): ?Subscription
    {
        return $this->facts->subscription($organization);
    }

    /*
    |--------------------------------------------------------------------------
    | Is it running?
    |--------------------------------------------------------------------------
    */

    /** trial | active | past_due | grace | expired | suspended | cancelled */
    public function getSubscriptionStatus(Organization $organization, ?CarbonInterface $now = null): string
    {
        $subscription = $this->subscription($organization);

        // No subscription is never a lock — see SubscriptionFacts.
        return $subscription === null ? Subscription::ACTIVE : $this->clock->status($subscription, $now);
    }

    public function isLocked(Organization $organization, ?CarbonInterface $now = null): bool
    {
        return in_array($this->getSubscriptionStatus($organization, $now), SubscriptionClock::LOCKED_STATUSES, true);
    }

    public function canAccessApplication(Organization $organization, ?CarbonInterface $now = null): bool
    {
        return ! $this->isLocked($organization, $now);
    }

    /**
     * Whether the organization may CHANGE anything. False when locked, and
     * during grace when the Super Admin has set grace to read-only.
     */
    public function canWrite(Organization $organization, ?CarbonInterface $now = null): bool
    {
        $status = $this->getSubscriptionStatus($organization, $now);

        if (in_array($status, SubscriptionClock::LOCKED_STATUSES, true)) {
            return false;
        }

        return ! ($status === Subscription::GRACE && $this->settings->graceAccess() === BillingSettings::GRACE_READ_ONLY);
    }

    /** Calendar days left on the term; null when it has no end. */
    public function getDaysRemaining(Organization $organization, ?CarbonInterface $now = null): ?int
    {
        $subscription = $this->subscription($organization);

        return $subscription === null
            ? null
            : $this->clock->daysRemaining($subscription, $this->timezone($organization), $now);
    }

    /*
    |--------------------------------------------------------------------------
    | What it grants
    |--------------------------------------------------------------------------
    */

    public function canUseModule(Organization $organization, string $module): bool
    {
        return $this->canAccessApplication($organization) && $this->moduleAccess()->has($organization, $module);
    }

    public function hasFeature(Organization $organization, string $feature): bool
    {
        return $this->facts->hasFeature($organization, $feature);
    }

    /** null is unlimited. */
    public function getResourceLimit(Organization $organization, string $resource): ?int
    {
        return $this->facts->limit($organization, $resource);
    }

    public function getResourceUsage(Organization $organization, string $resource): int
    {
        return $this->counter()->count($organization, $resource);
    }

    /**
     * Whether `$adding` more of a resource fit under the limit.
     *
     * A read, for the screens. The write path asks ResourceLimiter, which
     * asks the same question under a lock so two requests cannot both take
     * the last place.
     */
    public function canCreateResource(Organization $organization, string $resource, int $adding = 1): bool
    {
        if (! $this->canWrite($organization)) {
            return false;
        }

        $limit = $this->getResourceLimit($organization, $resource);

        return $limit === null || $this->getResourceUsage($organization, $resource) + $adding <= $limit;
    }

    /** What is left of a quota this month; null is unlimited. */
    public function getRemainingQuota(Organization $organization, string $quota): ?int
    {
        return $this->balances()->current($organization, $quota)->remaining();
    }

    /**
     * What the organization pays for one thing, as agreed: its plan's price
     * from the subscription's own snapshot, or a module / add-on at the
     * current list price for its cycle.
     *
     * @param  string  $item  'plan', 'module:{key}' or 'addon:{key}'
     */
    public function getEffectivePrice(Organization $organization, string $item): ?int
    {
        $subscription = $this->subscription($organization);

        if ($item === 'plan') {
            return $subscription?->snapshotPrice() ?? 0;
        }

        $cycle = $subscription && ! $subscription->isComplimentary() ? $subscription->billing_cycle : Subscription::MONTHLY;
        [$kind, $key] = array_pad(explode(':', $item, 2), 2, null);

        return match ($kind) {
            'module' => app(PricingCatalog::class)->currentModulePrice(
                (int) Module::query()->where('key', $key)->value('id')
            )?->priceFor($cycle),
            'addon' => Addon::query()->where('key', $key)->first()?->priceFor($cycle),
            default => null,
        };
    }

    /**
     * Whether a billable action may go ahead now — running, the feature on
     * (video), and either quota left or the rate allowing overage.
     */
    public function canPerformBillableAction(Organization $organization, string $channel, int $quantity = 1): bool
    {
        return app(UsageMeter::class)->authorize($organization, $channel, $quantity)->allowed;
    }

    /*
    |--------------------------------------------------------------------------
    | What the clinic is told
    |--------------------------------------------------------------------------
    */

    /**
     * The subscription as the session, the dashboard card and the
     * subscription page show it. Every value here comes from the records —
     * nothing is a default dressed up as a fact.
     *
     * @return array<string, mixed>
     */
    public function status(Organization $organization, ?CarbonInterface $now = null): array
    {
        $now ??= Carbon::now();
        $subscription = $this->subscription($organization);
        $timezone = $this->timezone($organization);

        if ($subscription === null) {
            return [
                'status' => Subscription::ACTIVE,
                'plan' => ['name' => 'Complimentary', 'slug' => Plan::COMPLIMENTARY],
                'billing_cycle' => Subscription::COMPLIMENTARY,
                'is_complimentary' => true,
                'locked' => false,
                'read_only' => false,
                'warning' => false,
                'days_remaining' => null,
                'timezone' => $timezone,
            ];
        }

        $status = $this->clock->status($subscription, $now);
        $locked = in_array($status, SubscriptionClock::LOCKED_STATUSES, true);
        $days = $this->clock->daysRemaining($subscription, $timezone, $now);
        $graceEnds = $this->clock->graceEnds($subscription);
        $warningDays = (int) $this->settings->get('warning_days');

        return [
            'status' => $status,
            'plan' => [
                'name' => $subscription->snapshot['plan']['name'] ?? $subscription->plan?->name,
                'slug' => $subscription->snapshot['plan']['slug'] ?? $subscription->plan?->slug,
            ],
            'billing_cycle' => $subscription->billing_cycle,
            'is_complimentary' => $subscription->isComplimentary(),
            'price' => $subscription->snapshotPrice(),
            'currency' => $subscription->snapshot['currency'] ?? 'INR',
            'started_at' => $subscription->started_at?->toIso8601String(),
            'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
            'current_period_start' => $subscription->current_period_start?->toIso8601String(),
            'expires_at' => $subscription->expires_at?->toIso8601String(),
            'grace_ends_at' => $graceEnds !== null && $subscription->expires_at !== null && $graceEnds > $subscription->expires_at
                ? $graceEnds->toIso8601String()
                : null,
            'next_renewal_at' => $subscription->next_renewal_at?->toIso8601String(),
            'cancelled_at' => $subscription->cancelled_at?->toIso8601String(),
            'days_remaining' => $days,
            'grace_days_remaining' => $status === Subscription::GRACE
                ? $this->clock->graceDaysRemaining($subscription, $timezone, $now)
                : null,
            'locked' => $locked,
            'locked_at' => $locked ? ($subscription->locked_at ?? $this->clock->locksAt($subscription))?->toIso8601String() : null,
            'lock_reason' => $locked
                ? ($subscription->suspended_at !== null ? Subscription::LOCK_BILLING_SUSPENDED : Subscription::LOCK_EXPIRED)
                : null,
            'read_only' => ! $locked && $status === Subscription::GRACE
                && $this->settings->graceAccess() === BillingSettings::GRACE_READ_ONLY,
            'grace_access' => $this->settings->graceAccess(),
            'warning' => ! $locked && ($status === Subscription::GRACE
                || ($days !== null && $days <= $warningDays && in_array($status, [Subscription::ACTIVE, Subscription::TRIAL, Subscription::PAST_DUE], true))),
            'payment_status' => $subscription->payment_status,
            'pending_plan' => $subscription->pendingPlan?->name,
            'timezone' => $timezone,
            'countdown' => 'calendar_days',
        ];
    }

    /** Usage of each limited resource against its limit. @return array<string, array<string, mixed>> */
    public function resources(Organization $organization): array
    {
        $counts = $this->counter()->all($organization);
        $rows = [];

        foreach (Entitlements::RESOURCES as $resource) {
            $limit = $this->getResourceLimit($organization, $resource);
            $used = $counts[$resource] ?? 0;

            $rows[$resource] = [
                'key' => $resource,
                'label' => Entitlements::label($resource),
                'used' => $used,
                'limit' => $limit,
                'unlimited' => $limit === null,
                'remaining' => $limit === null ? null : max(0, $limit - $used),
                // Near: 80% or more used. Over: a plan change left more than
                // the new limit allows — existing records stay; adding is
                // refused until they are under it.
                'near_limit' => $limit !== null && $limit > 0 && $used / $limit >= 0.8,
                'over_limit' => $limit !== null && $used > $limit,
            ];
        }

        return $rows;
    }

    private function timezone(Organization $organization): string
    {
        return $organization->timezone ?: (string) config('app.timezone');
    }

    private function moduleAccess(): ModuleAccess
    {
        return $this->modules ?? app(ModuleAccess::class);
    }

    private function counter(): ResourceCounter
    {
        return $this->counter ?? app(ResourceCounter::class);
    }

    private function balances(): UsageBalances
    {
        return $this->balances ?? app(UsageBalances::class);
    }
}
