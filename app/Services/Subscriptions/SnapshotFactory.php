<?php

namespace App\Services\Subscriptions;

use App\Models\Platform\Plan;
use App\Models\Platform\Subscription;
use App\Support\Subscriptions\Entitlements;

/**
 * The agreement a subscription is held to: the plan as it stood when it was
 * bought or renewed.
 *
 * Stored on the subscription so that a Super Admin repricing or reshaping the
 * plan afterwards changes the next sale, and the next renewal, but never the
 * term a clinic has already paid for.
 */
class SnapshotFactory
{
    /** @return array<string, mixed> */
    public function make(Plan $plan, string $cycle, ?int $price = null): array
    {
        $plan->loadMissing(['limits', 'modules']);
        $limits = $plan->limitMap();

        return [
            'plan' => ['id' => $plan->id, 'slug' => $plan->slug, 'name' => $plan->name],
            'billing_cycle' => $cycle,
            'currency' => $plan->currency,
            'price' => $cycle === Subscription::COMPLIMENTARY ? 0 : ($price ?? $plan->priceFor($cycle)),
            'modules' => $plan->moduleKeys(),
            'limits' => array_intersect_key($limits, array_flip(Entitlements::RESOURCES)),
            'quotas' => array_intersect_key($limits, array_flip(Entitlements::QUOTAS)),
            'features' => array_values(array_intersect((array) $plan->features, Entitlements::FEATURES)),
            'grace_days' => (int) $plan->grace_days,
            'captured_at' => now()->toIso8601String(),
        ];
    }
}
