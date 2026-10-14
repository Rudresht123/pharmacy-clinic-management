<?php

namespace App\Services\Subscriptions;

use App\Models\Platform\Organization;
use App\Models\Platform\Subscription;
use App\Models\Platform\SubscriptionItem;
use App\Support\Subscriptions\Entitlements;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * What an organization's current subscription grants — modules, features,
 * limits — merged from the plan snapshot, its add-ons and Super Admin
 * overrides.
 *
 * The time-independent half of the entitlement question. Whether the
 * subscription is RUNNING right now is SubscriptionClock's half, and is never
 * cached; what it grants while running only changes when the lifecycle bumps
 * `entitlements_version`, so it is cached under that version and a change is
 * visible on the very next request.
 *
 * NO SUBSCRIPTION IS NEVER A LOCK. An organization with no current
 * subscription — data that should not exist, since every organization is
 * given one when it is created and the rollout gave one to every existing
 * organization — is answered as Complimentary: unlimited, every feature, no
 * plan modules (its `organization_modules` rows still decide its modules).
 * It is logged so somebody notices, and the admin overview lists it.
 */
class SubscriptionFacts
{
    /** @var array<int, Subscription|false> per-request memo, keyed by organization */
    private array $subscriptions = [];

    public function subscription(Organization $organization): ?Subscription
    {
        $id = (int) $organization->getKey();

        if (! array_key_exists($id, $this->subscriptions)) {
            $this->subscriptions[$id] = Subscription::query()
                ->where('organization_id', $id)
                ->where('is_current', true)
                ->first() ?? false;

            if ($this->subscriptions[$id] === false) {
                Log::warning('[subscriptions] organization has no current subscription; treating as complimentary', [
                    'organization_id' => $id,
                ]);
            }
        }

        return $this->subscriptions[$id] ?: null;
    }

    /** Forget the memo — after the lifecycle changes a subscription. */
    public function forget(Organization|int $organization): void
    {
        unset($this->subscriptions[$organization instanceof Organization ? (int) $organization->getKey() : $organization]);
    }

    /**
     * @return array{
     *     subscription_id: int|null,
     *     modules: list<string>,
     *     features: list<string>,
     *     limits: array<string, int|null>,
     *     overrides: array<string, int|null>,
     * }
     */
    public function grants(Organization $organization): array
    {
        $subscription = $this->subscription($organization);

        if ($subscription === null) {
            return self::complimentaryGrants();
        }

        $key = sprintf(
            'subscription-grants:%d:%d:%d',
            $subscription->organization_id,
            $subscription->id,
            $subscription->entitlements_version,
        );

        return Cache::remember($key, now()->addMinutes(30), fn () => $this->compute($subscription));
    }

    /** @return list<string> module keys the subscription itself grants */
    public function modules(Organization $organization): array
    {
        return $this->grants($organization)['modules'];
    }

    public function hasFeature(Organization $organization, string $feature): bool
    {
        return in_array($feature, $this->grants($organization)['features'], true);
    }

    /** The effective limit: null is unlimited. */
    public function limit(Organization $organization, string $key): ?int
    {
        $limits = $this->grants($organization)['limits'];

        return array_key_exists($key, $limits) ? $limits[$key] : 0;
    }

    /**
     * @return array{subscription_id: int, modules: list<string>, features: list<string>, limits: array<string, int|null>, overrides: array<string, int|null>}
     */
    private function compute(Subscription $subscription): array
    {
        // Oldest first, so the latest override for a key is the one that sticks.
        $items = $subscription->items()->orderBy('id')->get()
            ->filter(fn (SubscriptionItem $item) => $item->isLive());

        $modules = $subscription->snapshotModules();
        $features = $subscription->snapshotFeatures();

        $limits = [];

        foreach (Entitlements::limitKeys() as $key) {
            $limits[$key] = $subscription->snapshotLimit($key);
        }

        $overrides = [];

        foreach ($items as $item) {
            /** @var SubscriptionItem $item */
            $target = (string) $item->target;

            if ($item->type === SubscriptionItem::MODULE) {
                $modules[] = $item->ref;

                continue;
            }

            if (Entitlements::isFeature($target)) {
                // A bought feature add-on, or one a Super Admin granted.
                $features[] = $target;

                continue;
            }

            if ($item->type === SubscriptionItem::OVERRIDE) {
                // An override SETS the limit — null is unlimited.
                $overrides[$target] = $item->grants;

                continue;
            }

            /*
             * Bought capacity ADDS to the plan's amount; unlimited stays
             * unlimited. Credit packs are written into the period's usage
             * balance when bought (UsageMeter), which is what spending reads,
             * so they are not added here.
             */
            if ($item->type === SubscriptionItem::ADDON && Entitlements::isResource($target)) {
                $limits[$target] = $limits[$target] === null ? null : $limits[$target] + (int) $item->grants;
            }
        }

        foreach ($overrides as $key => $value) {
            if (array_key_exists($key, $limits)) {
                $limits[$key] = $value === null ? null : (int) $value;
            }
        }

        return [
            'subscription_id' => (int) $subscription->id,
            'modules' => array_values(array_unique($modules)),
            'features' => array_values(array_unique($features)),
            'limits' => $limits,
            'overrides' => $overrides,
        ];
    }

    /** @return array{subscription_id: null, modules: list<string>, features: list<string>, limits: array<string, null>, overrides: array<string, null>} */
    public static function complimentaryGrants(): array
    {
        return [
            'subscription_id' => null,
            'modules' => [],
            'features' => Entitlements::FEATURES,
            'limits' => array_fill_keys(Entitlements::limitKeys(), null),
            'overrides' => [],
        ];
    }
}
