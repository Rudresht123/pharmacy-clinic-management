<?php

namespace App\Services\Subscriptions;

use App\Models\Platform\Addon;
use App\Models\Platform\Module;
use App\Models\Platform\Organization;
use App\Models\Platform\Plan;
use App\Models\Platform\Subscription;
use App\Models\Platform\SubscriptionEvent;
use App\Models\Platform\SubscriptionItem;
use App\Support\Subscriptions\Entitlements;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only thing that changes a subscription.
 *
 * Every transition happens here, inside a transaction that holds the row
 * (`lockForUpdate`), writes an event saying who did it and why, and bumps
 * `entitlements_version` so every cached answer about the organization is
 * stale the moment the change commits. A controller, a webhook and the
 * scheduler all come through these methods — none of them saves a
 * Subscription itself.
 *
 * WHAT IT NEVER TOUCHES: `organizations.status`. That is the platform's
 * security/administrative lock, placed and lifted by a person through
 * OrganizationRepository::changeStatus(). Renewing, unlocking or extending a
 * subscription cannot lift it, because nothing here writes it.
 */
class SubscriptionLifecycle
{
    public function __construct(
        private readonly SubscriptionClock $clock,
        private readonly SnapshotFactory $snapshots,
        private readonly SubscriptionFacts $facts,
        private readonly UsageBalances $balances,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Starting a contract
    |--------------------------------------------------------------------------
    */

    /**
     * Put an organization on a plan — a new contract, replacing the current
     * one. What a Super Admin's "Assign plan" does, and what onboarding does
     * for a new organization.
     *
     * Options:
     *   trial          bool       start the plan's trial instead of a paid term
     *   starts_at      Carbon     when the term starts (default now)
     *   expires_at     Carbon     a custom end for a negotiated deal
     *   payment_status string     none | pending | paid | waived (default pending
     *                             for a paid term, waived for complimentary)
     *   price          int        an agreed price that is not the list price
     *
     * Live add-ons and overrides on the old contract are carried across: a
     * clinic that bought an extra branch does not lose it by changing plan.
     *
     * @param  array<string, mixed>  $options
     */
    public function assign(
        Organization $organization,
        Plan $plan,
        string $cycle,
        Actor $actor,
        ?string $reason = null,
        array $options = [],
    ): Subscription {
        if (! in_array($cycle, Subscription::CYCLES, true)) {
            throw ValidationException::withMessages(['billing_cycle' => 'Choose monthly, annual or complimentary.']);
        }

        if ($plan->status === Plan::ARCHIVED) {
            throw ValidationException::withMessages(['plan' => 'An archived plan cannot be assigned.']);
        }

        $trial = (bool) ($options['trial'] ?? false);

        if ($trial && $plan->trial_days < 1) {
            throw ValidationException::withMessages(['trial' => "The {$plan->name} plan has no trial."]);
        }

        $timezone = $this->timezone($organization);
        $start = Carbon::instance($options['starts_at'] ?? Carbon::now());

        $subscription = DB::transaction(function () use ($organization, $plan, $cycle, $actor, $reason, $options, $trial, $timezone, $start) {
            /** @var Subscription|null $current */
            $current = Subscription::query()
                ->where('organization_id', $organization->getKey())
                ->where('is_current', true)
                ->lockForUpdate()
                ->first();

            $attributes = [
                'organization_id' => $organization->getKey(),
                'plan_id' => $plan->getKey(),
                'billing_cycle' => $cycle,
                'is_current' => true,
                'started_at' => $start,
                'current_period_start' => $start,
                'snapshot' => $this->snapshots->make($plan, $cycle, $options['price'] ?? null),
                'entitlements_version' => 1,
            ];

            if ($trial) {
                $ends = $this->clock->endOfDayAfter($start, $plan->trial_days - 1, $timezone);

                $attributes += [
                    'status' => Subscription::TRIAL,
                    'trial_starts_at' => $start,
                    'trial_ends_at' => $ends,
                    'expires_at' => $ends,
                    'grace_ends_at' => $ends,
                    'current_period_end' => $ends,
                    'payment_status' => Subscription::PAYMENT_NONE,
                ];
            } elseif ($cycle === Subscription::COMPLIMENTARY) {
                $attributes += [
                    'status' => Subscription::ACTIVE,
                    'payment_status' => Subscription::PAYMENT_WAIVED,
                ];
            } else {
                $expires = isset($options['expires_at'])
                    ? Carbon::instance($options['expires_at'])->setTimezone($timezone)->endOfDay()->setTimezone(config('app.timezone'))
                    : $this->clock->termEnd($start, $cycle, $timezone);

                $attributes += [
                    'status' => Subscription::ACTIVE,
                    'expires_at' => $expires,
                    'grace_ends_at' => $this->graceEnd($expires, $plan->grace_days, $timezone),
                    'current_period_end' => $expires,
                    'next_renewal_at' => $expires,
                    'payment_status' => $options['payment_status'] ?? Subscription::PAYMENT_PENDING,
                ];
            }

            $current?->forceFill([
                'is_current' => false,
                'status' => $current->status === Subscription::EXPIRED ? Subscription::EXPIRED : Subscription::CANCELLED,
                'cancelled_at' => $current->cancelled_at ?? Carbon::now(),
            ])->save();

            $subscription = Subscription::query()->create($attributes);

            if ($current !== null) {
                $current->forceFill(['replaced_by_id' => $subscription->id])->save();
                $this->carryItems($current, $subscription);
            }

            $organization->forceFill(['plan_id' => $plan->getKey()])->save();

            $this->event(
                $subscription,
                $current === null ? SubscriptionEvent::CREATED : SubscriptionEvent::PLAN_CHANGED,
                $actor,
                $reason,
                from: $current?->status,
                before: $current ? $this->summary($current) : null,
                after: $this->summary($subscription),
            );

            if ($trial) {
                $this->event($subscription, SubscriptionEvent::TRIAL_STARTED, $actor, $reason, after: [
                    'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
                ]);
            }

            return $subscription;
        });

        $this->settled($organization);

        return $subscription;
    }

    /** A plan's trial, from today. */
    public function startTrial(
        Organization $organization,
        Plan $plan,
        Actor $actor,
        ?string $reason = null,
        string $cycle = Subscription::MONTHLY,
    ): Subscription {
        return $this->assign($organization, $plan, $cycle, $actor, $reason ?? 'Trial started.', ['trial' => true]);
    }

    /*
    |--------------------------------------------------------------------------
    | Renewing
    |--------------------------------------------------------------------------
    */

    /**
     * The next term, paid for — by a verified payment (PaymentConfirmation)
     * or by a Super Admin recording one.
     *
     * Renewing early adds the new term after the current one, so no day that
     * was paid for is lost; renewing a lapsed or trial subscription starts
     * the new term now. A queued plan change (a downgrade) takes effect here.
     *
     * Clears the EXPIRY lock only. A billing suspension a Super Admin placed
     * stays until they lift it, and the organization-level security
     * suspension is never touched.
     */
    public function renew(
        Subscription $subscription,
        Actor $actor,
        ?string $reason = null,
        ?Plan $plan = null,
        ?string $cycle = null,
        ?int $price = null,
        string $paymentStatus = Subscription::PAYMENT_PAID,
    ): Subscription {
        $organization = $subscription->organization;

        DB::transaction(function () use ($subscription, $actor, $reason, $plan, $cycle, $price, $paymentStatus, $organization) {
            $subscription = $this->lock($subscription);
            $before = $this->summary($subscription);
            $wasLocked = $this->clock->isLocked($subscription);
            $fromStatus = $this->clock->status($subscription);

            $plan ??= $subscription->pendingPlan ?? $subscription->plan;
            $cycle ??= $subscription->pending_billing_cycle ?? $subscription->billing_cycle;

            if (! in_array($cycle, [Subscription::MONTHLY, Subscription::ANNUAL], true)) {
                throw ValidationException::withMessages(['billing_cycle' => 'Choose monthly or annual billing to renew.']);
            }

            $timezone = $this->timezone($organization);
            $now = Carbon::now();

            $continues = $fromStatus === Subscription::ACTIVE || $fromStatus === Subscription::PAST_DUE;
            $start = $continues && $subscription->expires_at !== null && $subscription->expires_at->isFuture()
                ? $subscription->expires_at->copy()->addSecond()
                : $now;

            $expires = $this->clock->termEnd($start, $cycle, $timezone);

            $snapshot = $plan->isPurchasable() || $plan->is($subscription->plan) === false
                ? $this->snapshots->make($plan, $cycle, $price)
                : array_replace($subscription->snapshot ?? [], [
                    'billing_cycle' => $cycle,
                    'price' => $price ?? $subscription->snapshotPrice(),
                ]);

            $subscription->forceFill([
                'plan_id' => $plan->getKey(),
                'billing_cycle' => $cycle,
                'status' => Subscription::ACTIVE,
                'current_period_start' => $start,
                'current_period_end' => $expires,
                'expires_at' => $expires,
                'grace_ends_at' => $this->graceEnd($expires, (int) ($snapshot['grace_days'] ?? $plan->grace_days), $timezone),
                'next_renewal_at' => $expires,
                'cancelled_at' => null,
                'payment_status' => $paymentStatus,
                'snapshot' => $snapshot,
                'pending_plan_id' => null,
                'pending_billing_cycle' => null,
                'entitlements_version' => $subscription->entitlements_version + 1,
            ]);

            // Only the lock that paying resolves.
            if ($subscription->lock_reason === Subscription::LOCK_EXPIRED || $subscription->suspended_at === null) {
                $subscription->forceFill(['locked_at' => null, 'lock_reason' => null]);
            }

            if ($subscription->suspended_at !== null) {
                $subscription->status = Subscription::SUSPENDED;
            }

            $subscription->save();

            $organization->forceFill(['plan_id' => $plan->getKey()])->save();

            $this->event(
                $subscription,
                $fromStatus === Subscription::TRIAL ? SubscriptionEvent::ACTIVATED : SubscriptionEvent::RENEWED,
                $actor,
                $reason,
                from: $fromStatus,
                before: $before,
                after: $this->summary($subscription),
            );

            if ($wasLocked && ! $this->clock->isLocked($subscription)) {
                $this->event($subscription, SubscriptionEvent::UNLOCKED, $actor, 'Renewed: the term is paid for again.', from: $fromStatus);
            }
        });

        $this->settled($organization);

        return $subscription->refresh();
    }

    /*
    |--------------------------------------------------------------------------
    | Changing the plan within a term
    |--------------------------------------------------------------------------
    */

    /**
     * Move to another plan.
     *
     * Immediately (an upgrade, or a Super Admin's correction): the snapshot
     * becomes the new plan's, the term's dates stay. At the end of the term
     * (a downgrade): queued, and applied by the next renewal — a clinic keeps
     * what it paid for until the term it paid for is over.
     */
    public function changePlan(
        Subscription $subscription,
        Plan $plan,
        string $cycle,
        Actor $actor,
        ?string $reason = null,
        bool $immediately = true,
        ?int $price = null,
    ): Subscription {
        if ($plan->status === Plan::ARCHIVED) {
            throw ValidationException::withMessages(['plan' => 'An archived plan cannot be assigned.']);
        }

        if (! in_array($cycle, [Subscription::MONTHLY, Subscription::ANNUAL], true)) {
            throw ValidationException::withMessages(['billing_cycle' => 'Choose monthly or annual billing.']);
        }

        if ($subscription->isComplimentary()) {
            // Nothing to carry: a complimentary contract becomes a real one.
            return $this->assign($subscription->organization, $plan, $cycle, $actor, $reason);
        }

        $organization = $subscription->organization;

        DB::transaction(function () use ($subscription, $plan, $cycle, $actor, $reason, $immediately, $price, $organization) {
            $subscription = $this->lock($subscription);
            $before = $this->summary($subscription);

            if (! $immediately) {
                $subscription->forceFill([
                    'pending_plan_id' => $plan->getKey(),
                    'pending_billing_cycle' => $cycle,
                ])->save();

                $this->event($subscription, SubscriptionEvent::PLAN_CHANGE_SCHEDULED, $actor, $reason, before: $before, after: [
                    'plan' => $plan->name,
                    'billing_cycle' => $cycle,
                    'from' => $subscription->expires_at?->toIso8601String(),
                ]);

                return;
            }

            $subscription->forceFill([
                'plan_id' => $plan->getKey(),
                'billing_cycle' => $cycle,
                'snapshot' => $this->snapshots->make($plan, $cycle, $price),
                'pending_plan_id' => null,
                'pending_billing_cycle' => null,
                'entitlements_version' => $subscription->entitlements_version + 1,
            ])->save();

            $organization->forceFill(['plan_id' => $plan->getKey()])->save();

            $this->event($subscription, SubscriptionEvent::PLAN_CHANGED, $actor, $reason, before: $before, after: $this->summary($subscription));
        });

        $this->settled($organization);

        return $subscription->refresh();
    }

    /*
    |--------------------------------------------------------------------------
    | Super Admin's levers — each one needs a reason
    |--------------------------------------------------------------------------
    */

    /** Move the end of the term (or the trial) to the end of `$until`. */
    public function extend(Subscription $subscription, CarbonInterface $until, Actor $actor, string $reason): Subscription
    {
        if ($subscription->expires_at === null) {
            throw ValidationException::withMessages(['until' => 'This subscription has no end date to extend.']);
        }

        $organization = $subscription->organization;
        $timezone = $this->timezone($organization);
        $expires = Carbon::instance($until)->setTimezone($timezone)->endOfDay()->setTimezone(config('app.timezone'));

        if ($expires->isPast()) {
            throw ValidationException::withMessages(['until' => 'Choose a date that has not passed.']);
        }

        DB::transaction(function () use ($subscription, $expires, $actor, $reason, $timezone) {
            $subscription = $this->lock($subscription);
            $before = $this->summary($subscription);
            $fromStatus = $this->clock->status($subscription);
            $wasLocked = $this->clock->isLocked($subscription);
            $onTrial = $fromStatus === Subscription::TRIAL
                || ($subscription->trial_ends_at !== null && $subscription->expires_at?->equalTo($subscription->trial_ends_at));

            $subscription->forceFill([
                'expires_at' => $expires,
                'current_period_end' => $expires,
                'grace_ends_at' => $onTrial ? $expires : $this->graceEnd($expires, $subscription->graceDays(), $timezone),
                'next_renewal_at' => $subscription->next_renewal_at === null && $subscription->cancelled_at !== null ? null : $expires,
                'trial_ends_at' => $onTrial ? $expires : $subscription->trial_ends_at,
            ]);

            if ($subscription->lock_reason === Subscription::LOCK_EXPIRED) {
                $subscription->forceFill(['locked_at' => null, 'lock_reason' => null]);
            }

            $subscription->status = $this->clock->status($subscription);
            $subscription->save();

            $this->event($subscription, SubscriptionEvent::EXTENDED, $actor, $reason, from: $fromStatus, before: $before, after: $this->summary($subscription));

            if ($wasLocked && ! $this->clock->isLocked($subscription)) {
                $this->event($subscription, SubscriptionEvent::UNLOCKED, $actor, $reason, from: $fromStatus);
            }
        });

        $this->settled($organization);

        return $subscription->refresh();
    }

    /** A billing suspension — a dispute, a bounced payment. Locks at once. */
    public function suspend(Subscription $subscription, Actor $actor, string $reason): Subscription
    {
        DB::transaction(function () use ($subscription, $actor, $reason) {
            $subscription = $this->lock($subscription);

            if ($subscription->suspended_at !== null) {
                throw ValidationException::withMessages(['reason' => 'This subscription is already suspended.']);
            }

            $from = $this->clock->status($subscription);

            $subscription->forceFill([
                'status' => Subscription::SUSPENDED,
                'suspended_at' => Carbon::now(),
                'suspension_reason' => mb_substr($reason, 0, 255),
                'locked_at' => Carbon::now(),
                'lock_reason' => Subscription::LOCK_BILLING_SUSPENDED,
            ])->save();

            $this->event($subscription, SubscriptionEvent::SUSPENDED, $actor, $reason, from: $from);
            $this->event($subscription, SubscriptionEvent::LOCKED, $actor, $reason, from: $from);
        });

        $this->settled($subscription->organization);

        return $subscription->refresh();
    }

    /**
     * Lift a billing suspension. If the term has also run out, the
     * organization stays locked for THAT reason — extend or renew to lift it.
     */
    public function unlock(Subscription $subscription, Actor $actor, string $reason): Subscription
    {
        DB::transaction(function () use ($subscription, $actor, $reason) {
            $subscription = $this->lock($subscription);

            if ($subscription->suspended_at === null) {
                throw ValidationException::withMessages(['reason' => $this->clock->isLocked($subscription)
                    ? 'This subscription is locked because its term ended. Extend it or record a payment to unlock it.'
                    : 'This subscription is not suspended.']);
            }

            $subscription->forceFill([
                'suspended_at' => null,
                'suspension_reason' => null,
                'locked_at' => null,
                'lock_reason' => null,
            ]);

            $status = $this->clock->status($subscription);
            $subscription->status = $status;

            if ($this->clock->isLocked($subscription)) {
                $subscription->forceFill([
                    'locked_at' => $this->clock->locksAt($subscription),
                    'lock_reason' => Subscription::LOCK_EXPIRED,
                ]);
            }

            $subscription->save();

            $this->event($subscription, SubscriptionEvent::UNLOCKED, $actor, $reason, from: Subscription::SUSPENDED, after: ['status' => $status]);
        });

        $this->settled($subscription->organization);

        return $subscription->refresh();
    }

    /**
     * Cancel: at the end of the term (no renewal invoice, access until it
     * runs out and its grace ends), or immediately (locked now).
     */
    public function cancel(Subscription $subscription, Actor $actor, string $reason, bool $immediately = false): Subscription
    {
        DB::transaction(function () use ($subscription, $actor, $reason, $immediately) {
            $subscription = $this->lock($subscription);
            $from = $this->clock->status($subscription);
            $now = Carbon::now();

            if ($immediately || $subscription->expires_at === null) {
                $subscription->forceFill([
                    'cancelled_at' => $now,
                    'expires_at' => $subscription->expires_at !== null && $subscription->expires_at < $now ? $subscription->expires_at : $now,
                    'grace_ends_at' => $now,
                    'next_renewal_at' => null,
                    'pending_plan_id' => null,
                    'status' => Subscription::CANCELLED,
                    'locked_at' => $now,
                    'lock_reason' => Subscription::LOCK_EXPIRED,
                ])->save();

                $this->event($subscription, SubscriptionEvent::CANCELLED, $actor, $reason, from: $from);
                $this->event($subscription, SubscriptionEvent::LOCKED, $actor, $reason, from: $from);

                return;
            }

            $subscription->forceFill([
                'cancelled_at' => $now,
                'next_renewal_at' => null,
                'pending_plan_id' => null,
            ])->save();

            $this->event($subscription, SubscriptionEvent::CANCELLED, $actor, $reason, from: $from, after: [
                'access_until' => $this->clock->locksAt($subscription)?->toIso8601String(),
            ]);
        });

        $this->settled($subscription->organization);

        return $subscription->refresh();
    }

    /**
     * Raise (or lower) one limit for this organization, regardless of plan.
     * Null is unlimited. Lasts until `$until`, or until removed.
     */
    public function overrideLimit(
        Subscription $subscription,
        string $key,
        ?int $value,
        Actor $actor,
        string $reason,
        ?CarbonInterface $until = null,
    ): SubscriptionItem {
        if (! in_array($key, Entitlements::limitKeys(), true)) {
            throw ValidationException::withMessages(['key' => 'Unknown limit.']);
        }

        return $this->addItem($subscription, SubscriptionItem::OVERRIDE, "limit:{$key}", $actor, $reason, [
            'target' => $key,
            'grants' => $value,
            'billing' => 'free',
            'ends_at' => $until,
        ], SubscriptionEvent::LIMIT_OVERRIDDEN, before: ['limit' => $this->facts->limit($subscription->organization, $key)]);
    }

    /** Switch on a feature (video, the app) at no charge. */
    public function grantFeature(Subscription $subscription, string $feature, Actor $actor, string $reason, ?CarbonInterface $until = null): SubscriptionItem
    {
        if (! Entitlements::isFeature($feature)) {
            throw ValidationException::withMessages(['feature' => 'Unknown feature.']);
        }

        return $this->addItem($subscription, SubscriptionItem::OVERRIDE, "feature:{$feature}", $actor, $reason, [
            'target' => $feature,
            'billing' => 'free',
            'ends_at' => $until,
        ], SubscriptionEvent::FEATURE_GRANTED);
    }

    /** Free credits for this month. */
    public function grantCredits(Subscription $subscription, string $quota, int $amount, Actor $actor, string $reason): void
    {
        if (! Entitlements::isQuota($quota) || $amount < 1) {
            throw ValidationException::withMessages(['amount' => 'Choose a quota and a positive amount.']);
        }

        $organization = $subscription->organization;
        $balance = $this->balances->current($organization, $quota);
        $before = $balance->granted;

        DB::transaction(function () use ($subscription, $organization, $quota, $amount, $actor, $reason, $before) {
            $this->balances->grant($organization, $quota, $amount);

            $this->event($subscription, SubscriptionEvent::CREDITS_GRANTED, $actor, $reason,
                before: ['quota' => $quota, 'granted' => $before],
                after: ['quota' => $quota, 'granted' => $before + $amount],
            );
        });
    }

    /**
     * Put a bought add-on (or a module add-on) on the subscription.
     *
     * Called by PaymentConfirmation once the money is verified, or by a
     * Super Admin enabling a permitted add-on. A credit pack's units go
     * straight into this month's balance.
     */
    public function addAddon(
        Subscription $subscription,
        string $type,
        string $ref,
        int $quantity,
        int $unitPrice,
        Actor $actor,
        ?string $reason = null,
    ): SubscriptionItem {
        if ($type === SubscriptionItem::MODULE) {
            if (! Module::query()->available()->bindable()->where('key', $ref)->exists()) {
                throw ValidationException::withMessages(['ref' => 'Unknown module.']);
            }

            // Recurring: runs with the subscription, renewed (and charged) with
            // it each term, until somebody removes it.
            return $this->addItem($subscription, SubscriptionItem::MODULE, $ref, $actor, $reason, [
                'target' => $ref,
                'quantity' => 1,
                'unit_price' => $unitPrice,
                'billing' => Addon::RECURRING,
                'ends_at' => null,
            ], SubscriptionEvent::ADDON_ADDED);
        }

        $addon = Addon::query()->where('key', $ref)->first();

        if ($addon === null) {
            throw ValidationException::withMessages(['ref' => 'Unknown add-on.']);
        }

        $grants = $addon->unit_quantity * max(1, $quantity);

        $item = $this->addItem($subscription, SubscriptionItem::ADDON, $addon->key, $actor, $reason, [
            'target' => $addon->target,
            'quantity' => max(1, $quantity),
            'grants' => $grants,
            'unit_price' => $unitPrice,
            'billing' => $addon->billing,
            // A one-time pack is this month's; a recurring add-on runs with the
            // subscription and renews with it.
            'ends_at' => $addon->billing === Addon::ONE_TIME
                ? $this->balances->period($subscription->organization)[1]
                : null,
        ], SubscriptionEvent::ADDON_ADDED);

        if ($addon->kind === Addon::CREDITS && Entitlements::isQuota($addon->target)) {
            $this->balances->purchase($subscription->organization, $addon->target, $grants);
        }

        return $item;
    }

    /** End an add-on or override now. */
    public function removeItem(SubscriptionItem $item, Actor $actor, string $reason): void
    {
        $subscription = $item->subscription;

        DB::transaction(function () use ($item, $subscription, $actor, $reason) {
            $subscription = $this->lock($subscription);

            $item->forceFill(['status' => SubscriptionItem::ENDED, 'ends_at' => Carbon::now()])->save();

            $subscription->forceFill(['entitlements_version' => $subscription->entitlements_version + 1])->save();

            $this->event($subscription, SubscriptionEvent::ADDON_REMOVED, $actor, $reason, before: [
                'type' => $item->type,
                'ref' => $item->ref,
                'grants' => $item->grants,
            ]);
        });

        $this->settled($subscription->organization);
    }

    /*
    |--------------------------------------------------------------------------
    | The scheduler's half
    |--------------------------------------------------------------------------
    */

    /**
     * Write down what the clock already says.
     *
     * Access never waits for this — SubscriptionClock decides that from the
     * dates on every request. This persists the status, stamps the lock and
     * writes the event, once: the event's dedupe key names the transition
     * and the term it happened to, so running this every hour (or twice at
     * once on two servers) records each transition exactly once.
     *
     * Returns the status it moved to, or null when nothing changed.
     */
    public function tick(Subscription $subscription, ?CarbonInterface $now = null): ?string
    {
        $now ??= Carbon::now();
        $effective = $this->clock->status($subscription, $now);

        if ($effective === $subscription->status && $this->lockStampConsistent($subscription, $now)) {
            return null;
        }

        $moved = DB::transaction(function () use ($subscription, $now) {
            $subscription = $this->lock($subscription);
            $effective = $this->clock->status($subscription, $now);
            $from = $subscription->status;

            if ($effective === $from && $this->lockStampConsistent($subscription, $now)) {
                return null;
            }

            $locked = in_array($effective, SubscriptionClock::LOCKED_STATUSES, true);

            $subscription->status = $effective;

            if ($locked && $subscription->locked_at === null) {
                $subscription->forceFill([
                    'locked_at' => $this->clock->locksAt($subscription) ?? $now,
                    'lock_reason' => $subscription->suspended_at !== null
                        ? Subscription::LOCK_BILLING_SUSPENDED
                        : Subscription::LOCK_EXPIRED,
                ]);
            }

            if (! $locked && $subscription->lock_reason === Subscription::LOCK_EXPIRED) {
                $subscription->forceFill(['locked_at' => null, 'lock_reason' => null]);
            }

            $subscription->save();

            $term = $subscription->expires_at?->getTimestamp() ?? 0;
            $system = Actor::system('Scheduler');

            $type = match ($effective) {
                Subscription::GRACE => SubscriptionEvent::GRACE_STARTED,
                Subscription::EXPIRED => SubscriptionEvent::EXPIRED,
                Subscription::CANCELLED => SubscriptionEvent::CANCELLED,
                default => null,
            };

            if ($type !== null) {
                $this->event($subscription, $type, $system, null, from: $from, dedupe: "{$type}:{$subscription->id}:{$term}");
            }

            if ($locked) {
                $this->event($subscription, SubscriptionEvent::LOCKED, $system, $this->lockSentence($subscription), from: $from, dedupe: "locked:{$subscription->id}:{$term}");
            }

            return $effective;
        });

        if ($moved !== null) {
            $this->settled($subscription->organization);
        }

        return $moved;
    }

    /*
    |--------------------------------------------------------------------------
    | Plumbing
    |--------------------------------------------------------------------------
    */

    /**
     * Write one event. With a dedupe key, a second write of the same key is
     * ignored at the database (ON CONFLICT DO NOTHING) — a plain insert would
     * abort the surrounding transaction on PostgreSQL.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function event(
        Subscription $subscription,
        string $type,
        Actor $actor,
        ?string $reason = null,
        ?string $from = null,
        ?array $before = null,
        ?array $after = null,
        ?string $dedupe = null,
    ): void {
        $row = [
            'subscription_id' => $subscription->id,
            'organization_id' => $subscription->organization_id,
            'type' => $type,
            'from_status' => $from,
            'to_status' => $subscription->status,
            'actor_type' => $actor->type,
            'actor_id' => $actor->id,
            'actor_name' => $actor->name !== null ? mb_substr($actor->name, 0, 150) : null,
            'reason' => $reason !== null ? mb_substr($reason, 0, 500) : null,
            'before' => $before !== null ? json_encode($before) : null,
            'after' => $after !== null ? json_encode($after) : null,
            'dedupe_key' => $dedupe,
            'occurred_at' => Carbon::now(),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ];

        SubscriptionEvent::query()->insertOrIgnore($row);
    }

    /** @return array<string, mixed> */
    public function summary(Subscription $subscription): array
    {
        return [
            'plan' => $subscription->snapshot['plan']['name'] ?? null,
            'billing_cycle' => $subscription->billing_cycle,
            'status' => $subscription->status,
            'price' => $subscription->snapshotPrice(),
            'expires_at' => $subscription->expires_at?->toIso8601String(),
            'grace_ends_at' => $subscription->grace_ends_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|null  $before
     */
    private function addItem(
        Subscription $subscription,
        string $type,
        string $ref,
        Actor $actor,
        ?string $reason,
        array $attributes,
        string $eventType,
        ?array $before = null,
    ): SubscriptionItem {
        $item = DB::transaction(function () use ($subscription, $type, $ref, $actor, $reason, $attributes, $eventType, $before) {
            $subscription = $this->lock($subscription);

            $item = SubscriptionItem::query()->create([
                'subscription_id' => $subscription->id,
                'type' => $type,
                'ref' => $ref,
                'starts_at' => Carbon::now(),
                'status' => SubscriptionItem::ACTIVE,
                'note' => $reason !== null ? mb_substr($reason, 0, 255) : null,
                'granted_by' => $actor->platformUserId(),
                ...$attributes,
            ]);

            $subscription->forceFill(['entitlements_version' => $subscription->entitlements_version + 1])->save();

            $this->event($subscription, $eventType, $actor, $reason, before: $before, after: [
                'type' => $type,
                'ref' => $ref,
                'target' => $item->target,
                'grants' => $item->grants,
                'until' => $item->ends_at?->toIso8601String(),
            ]);

            return $item;
        });

        $this->settled($subscription->organization);

        return $item;
    }

    /** Copy the old contract's live add-ons and overrides onto the new one. */
    private function carryItems(Subscription $from, Subscription $to): void
    {
        foreach ($from->items()->get() as $item) {
            if (! $item->isLive()) {
                continue;
            }

            $copy = $item->replicate(['subscription_id']);
            $copy->subscription_id = $to->id;
            $copy->save();
        }
    }

    private function lock(Subscription $subscription): Subscription
    {
        return Subscription::query()->whereKey($subscription->getKey())->lockForUpdate()->firstOrFail();
    }

    /** After any change: forget memos and re-read this month's allowances. */
    private function settled(Organization $organization): void
    {
        $this->facts->forget($organization);
        $this->balances->refreshIncluded($organization);
    }

    private function graceEnd(?CarbonInterface $expires, int $days, string $timezone): ?Carbon
    {
        if ($expires === null) {
            return null;
        }

        return $days > 0 ? $this->clock->endOfDayAfter($expires, $days, $timezone) : Carbon::instance($expires);
    }

    private function timezone(Organization $organization): string
    {
        return $organization->timezone ?: (string) config('app.timezone');
    }

    private function lockStampConsistent(Subscription $subscription, CarbonInterface $now): bool
    {
        $locked = $this->clock->isLocked($subscription, $now);

        return $locked === ($subscription->locked_at !== null);
    }

    private function lockSentence(Subscription $subscription): string
    {
        return $subscription->suspended_at !== null
            ? 'Locked: billing suspended.'
            : 'Locked: the subscription and its grace period have ended.';
    }
}
