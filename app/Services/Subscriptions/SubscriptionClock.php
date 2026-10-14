<?php

namespace App\Services\Subscriptions;

use App\Models\Platform\Subscription;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * What a subscription's dates say, at a given instant.
 *
 * PURE: it reads the row and a clock, and changes nothing. Every access
 * decision asks this — the middleware, the session payload, the dashboard
 * card — so a lapsed term locks at the exact moment its grace ends, whether
 * or not `subscriptions:process` has run. The scheduler only writes down what
 * this already says, and sends the reminders.
 *
 * The rules, in order:
 *
 *   1. A billing suspension placed by a Super Admin is `suspended`, whatever
 *      the dates say.
 *   2. No expiry date (Complimentary) never lapses.
 *   3. Up to `expires_at`: `trial` while the trial runs, otherwise the stored
 *      `active` / `past_due`.
 *   4. After `expires_at`, up to `grace_ends_at`: `grace`. A trial has no
 *      grace — its `grace_ends_at` is its end.
 *   5. After that: `cancelled` if it was cancelled, otherwise `expired`.
 *
 * Locked means 1 or 5.
 */
class SubscriptionClock
{
    /** The statuses that shut the workspace. */
    public const LOCKED_STATUSES = [
        Subscription::EXPIRED,
        Subscription::SUSPENDED,
        Subscription::CANCELLED,
    ];

    public function status(Subscription $subscription, ?CarbonInterface $now = null): string
    {
        $now ??= Carbon::now();

        if ($subscription->suspended_at !== null) {
            return Subscription::SUSPENDED;
        }

        $expires = $subscription->expires_at;

        if ($expires === null) {
            // Complimentary, or an open-ended deal: nothing can lapse it. A
            // cancellation with no end date ends it now.
            return $subscription->cancelled_at !== null && $subscription->cancelled_at <= $now
                ? Subscription::CANCELLED
                : $this->running($subscription, $now);
        }

        if ($now <= $expires) {
            return $this->running($subscription, $now);
        }

        $graceEnds = $this->graceEnds($subscription);

        if ($graceEnds !== null && $now <= $graceEnds) {
            return Subscription::GRACE;
        }

        return $subscription->cancelled_at !== null ? Subscription::CANCELLED : Subscription::EXPIRED;
    }

    public function isLocked(Subscription $subscription, ?CarbonInterface $now = null): bool
    {
        return in_array($this->status($subscription, $now), self::LOCKED_STATUSES, true);
    }

    /** When it locks (or locked), if it ever will. */
    public function locksAt(Subscription $subscription): ?CarbonInterface
    {
        if ($subscription->suspended_at !== null) {
            return $subscription->suspended_at;
        }

        if ($subscription->expires_at === null) {
            return null;
        }

        return $this->graceEnds($subscription) ?? $subscription->expires_at;
    }

    /**
     * Whole calendar days left on the term, in the organization's timezone.
     *
     * Calendar days, not elapsed time: a term that ends at 23:59 tonight has
     * 0 days left all of today — "expires today" — and one that ends tomorrow
     * night has 1, from the first minute of today to the last. The same rule
     * on the card, in the API and in the reminder emails. Never negative;
     * null when there is no end.
     */
    public function daysRemaining(Subscription $subscription, string $timezone, ?CarbonInterface $now = null): ?int
    {
        return $this->calendarDaysUntil($subscription->expires_at, $timezone, $now);
    }

    /** The same count, to the end of the grace period. */
    public function graceDaysRemaining(Subscription $subscription, string $timezone, ?CarbonInterface $now = null): ?int
    {
        return $this->calendarDaysUntil($this->graceEnds($subscription), $timezone, $now);
    }

    public function calendarDaysUntil(?CarbonInterface $end, string $timezone, ?CarbonInterface $now = null): ?int
    {
        if ($end === null) {
            return null;
        }

        $today = Carbon::instance($now ?? Carbon::now())->setTimezone($timezone)->startOfDay();
        $last = Carbon::instance($end)->setTimezone($timezone)->startOfDay();

        return max(0, (int) $today->diffInDays($last, false));
    }

    /**
     * The end of the grace period: the stored date, or the term's end when it
     * has none (no grace). A trial never has grace.
     */
    public function graceEnds(Subscription $subscription): ?CarbonInterface
    {
        if ($subscription->expires_at === null) {
            return null;
        }

        $graceEnds = $subscription->grace_ends_at;

        if ($graceEnds === null || $graceEnds < $subscription->expires_at || $this->isTrialTerm($subscription)) {
            return $subscription->expires_at;
        }

        return $graceEnds;
    }

    /**
     * The end of a term that runs `$cycle` from `$start`: the last moment of
     * the day before the same date next month/year, in the organization's
     * timezone. A month from 15 October runs to 14 November, 23:59:59.
     */
    public function termEnd(CarbonInterface $start, string $cycle, string $timezone): ?Carbon
    {
        $local = Carbon::instance($start)->setTimezone($timezone);

        $next = match ($cycle) {
            Subscription::MONTHLY => $local->copy()->addMonthNoOverflow(),
            Subscription::ANNUAL => $local->copy()->addYearNoOverflow(),
            default => null,
        };

        return $next?->subDay()->endOfDay()->setTimezone(config('app.timezone'));
    }

    /** The last moment of the day `$days` from `$start`, in the org's timezone. */
    public function endOfDayAfter(CarbonInterface $start, int $days, string $timezone): Carbon
    {
        return Carbon::instance($start)->setTimezone($timezone)
            ->addDays($days)->endOfDay()
            ->setTimezone(config('app.timezone'));
    }

    private function running(Subscription $subscription, CarbonInterface $now): string
    {
        if ($this->isTrialTerm($subscription)
            && ($subscription->trial_ends_at === null || $now <= $subscription->trial_ends_at)) {
            return Subscription::TRIAL;
        }

        return $subscription->status === Subscription::PAST_DUE ? Subscription::PAST_DUE : Subscription::ACTIVE;
    }

    /** Still on the trial it started with: never paid for a term. */
    private function isTrialTerm(Subscription $subscription): bool
    {
        return $subscription->trial_ends_at !== null
            && $subscription->expires_at !== null
            && $subscription->expires_at->equalTo($subscription->trial_ends_at)
            && $subscription->payment_status !== Subscription::PAYMENT_PAID;
    }
}
