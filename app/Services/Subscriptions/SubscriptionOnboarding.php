<?php

namespace App\Services\Subscriptions;

use App\Models\Platform\Organization;
use App\Models\Platform\Plan;
use App\Models\Platform\PlatformUser;
use App\Models\Platform\Subscription;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * The first subscription a new organization gets — in the same transaction
 * that creates it, so no organization ever exists without one.
 *
 * In order:
 *   1. the plan the Super Admin (or the website sign-up) chose — a trial of
 *      it, or a paid term;
 *   2. otherwise the default trial plan from Billing Settings;
 *   3. otherwise Complimentary, so an install with no pricing set up yet
 *      keeps creating working organizations.
 */
class SubscriptionOnboarding
{
    public function __construct(
        private readonly SubscriptionLifecycle $lifecycle,
        private readonly BillingSettings $settings,
    ) {}

    /**
     * @param  array{plan_id?: int|string|null, billing_cycle?: string|null, trial?: bool|null}  $choice
     */
    public function start(Organization $organization, array $choice = [], ?Actor $actor = null): Subscription
    {
        $actor ??= $this->actor();

        if (! empty($choice['plan_id'])) {
            $plan = Plan::query()->find($choice['plan_id']);

            if ($plan === null || $plan->status === Plan::ARCHIVED) {
                throw ValidationException::withMessages(['subscription.plan_id' => 'Choose a plan that is on sale.']);
            }

            $cycle = $choice['billing_cycle'] ?? Subscription::MONTHLY;
            $trial = (bool) ($choice['trial'] ?? false);

            return $trial
                ? $this->lifecycle->startTrial($organization, $plan, $actor, 'Trial chosen when the organization was created.', $cycle === Subscription::COMPLIMENTARY ? Subscription::MONTHLY : $cycle)
                : $this->lifecycle->assign($organization, $plan, $cycle, $actor, 'Plan chosen when the organization was created.', array_filter([
                    // A website sign-up arrives here already paid for.
                    'payment_status' => $choice['payment_status'] ?? null,
                ]));
        }

        $default = $this->settings->get('default_trial_plan_id');
        $plan = $default ? Plan::query()->find($default) : null;

        if ($plan !== null && $plan->isPurchasable() && $plan->trial_days > 0) {
            return $this->lifecycle->startTrial($organization, $plan, $actor, 'Default trial for new organizations.');
        }

        return $this->lifecycle->assign(
            $organization,
            $this->complimentary(),
            Subscription::COMPLIMENTARY,
            $actor,
            'No default trial plan is set, so new organizations start on Complimentary until a plan is assigned.',
        );
    }

    public function complimentary(): Plan
    {
        return Plan::query()->where('slug', Plan::COMPLIMENTARY)->firstOrFail();
    }

    private function actor(): Actor
    {
        $admin = Auth::guard('platform')->user();

        return $admin instanceof PlatformUser ? Actor::platform($admin) : Actor::system('Onboarding');
    }
}
