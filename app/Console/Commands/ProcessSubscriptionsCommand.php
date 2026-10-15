<?php

namespace App\Console\Commands;

use App\Models\Platform\Subscription;
use App\Services\Subscriptions\BillingSettings;
use App\Services\Subscriptions\InvoiceService;
use App\Services\Subscriptions\SubscriptionClock;
use App\Services\Subscriptions\SubscriptionLifecycle;
use App\Services\Subscriptions\SubscriptionNotifier;
use App\Services\Subscriptions\UsageBalances;
use Illuminate\Console\Command;
use Throwable;

/**
 * Writes down what each subscription's dates already say, and sends the
 * reminders that are due.
 *
 * ACCESS DOES NOT WAIT FOR THIS. A lapsed subscription is refused on its very
 * next request by EnsureSubscriptionAllowsAccess, from the dates. This
 * command persists the status, stamps the lock, writes the event, and emails
 * the owner — and every one of those is keyed so that running it hourly, or
 * twice at once, does each exactly once.
 *
 * Reminders: of the configured "days before" offsets, only the nearest one
 * that has been reached is sent — a subscription first seen with two days
 * left gets the 3-day reminder, not the 7-day and the 3-day together. A run
 * that is missed simply skips that reminder; the next one still goes.
 *
 * Needs the scheduler running (`php artisan schedule:run` every minute).
 */
class ProcessSubscriptionsCommand extends Command
{
    protected $signature = 'subscriptions:process
        {--dry-run : Show what would change and be sent, change nothing}
        {--org= : One organization, by uuid}';

    protected $description = 'Persist subscription expiry/grace/lock transitions and send due reminders';

    public function handle(
        SubscriptionLifecycle $lifecycle,
        SubscriptionClock $clock,
        SubscriptionNotifier $notifier,
        BillingSettings $settings,
        InvoiceService $invoices,
        UsageBalances $balances,
    ): int {
        $dry = (bool) $this->option('dry-run');
        $failed = 0;
        $moved = 0;
        $reminded = 0;

        $query = Subscription::query()
            ->where('is_current', true)
            ->with('organization')
            ->orderBy('id');

        if ($uuid = $this->option('org')) {
            $query->whereHas('organization', fn ($q) => $q->where('uuid', $uuid));
        }

        $query->chunkById(200, function ($subscriptions) use ($lifecycle, $clock, $notifier, $settings, $invoices, $balances, $dry, &$failed, &$moved, &$reminded) {
            foreach ($subscriptions as $subscription) {
                /** @var Subscription $subscription */
                $name = $subscription->organization?->organization_name ?? "#{$subscription->organization_id}";

                try {
                    $effective = $clock->status($subscription);
                    $kind = $this->reminderDue($subscription, $effective, $clock, $settings);

                    if ($dry) {
                        if ($effective !== $subscription->status) {
                            $this->line("{$name}: {$subscription->status} → {$effective}");
                            $moved++;
                        }

                        if ($kind !== null) {
                            $this->line("{$name}: would send '{$kind}'");
                        }

                        continue;
                    }

                    if (($to = $lifecycle->tick($subscription)) !== null) {
                        $this->line("{$name}: now {$to}");
                        $moved++;
                    }

                    if ($kind !== null) {
                        $reminded += $notifier->remind($subscription->refresh(), $kind);
                    }

                    $this->invoiceWhatIsDue($subscription->refresh(), $invoices, $balances, $settings);
                } catch (Throwable $e) {
                    report($e);
                    $this->error("{$name}: {$e->getMessage()}");
                    $failed++;
                }
            }
        });

        $this->info(($dry ? '[dry run] ' : '')."{$moved} transition(s), {$reminded} reminder(s) sent, {$failed} failure(s).");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Invoices that are due by the calendar, each issued once:
     *
     *   the renewal invoice, `renewal_invoice_days` before the term ends —
     *     not for complimentary or cancelled subscriptions;
     *   last month's overage, once the month has closed.
     */
    private function invoiceWhatIsDue(Subscription $subscription, InvoiceService $invoices, UsageBalances $balances, BillingSettings $settings): void
    {
        $organization = $subscription->organization;

        if ($organization === null) {
            return;
        }

        $lead = (int) $settings->get('renewal_invoice_days');

        if (! $subscription->isComplimentary()
            && $subscription->cancelled_at === null
            && $subscription->next_renewal_at !== null
            && $subscription->next_renewal_at <= now()->addDays($lead)) {
            $invoices->renewalInvoice($subscription);
        }

        [$thisMonth] = $balances->period($organization);
        $invoices->overageInvoice($organization, $balances->period($organization, $thisMonth->copy()->subDay())[0]);
    }

    /** The one reminder this subscription is due now, if any. */
    private function reminderDue(Subscription $subscription, string $status, SubscriptionClock $clock, BillingSettings $settings): ?string
    {
        if ($subscription->expires_at === null || $subscription->isComplimentary()) {
            return null;
        }

        $timezone = $subscription->organization?->timezone ?: (string) config('app.timezone');

        if (in_array($status, [Subscription::ACTIVE, Subscription::TRIAL, Subscription::PAST_DUE], true)) {
            $days = $clock->daysRemaining($subscription, $timezone);

            return $this->nearest((array) $settings->get('reminder_days'), $days, fn (int $d) => $d === 0 ? 'expires_today' : "before_{$d}");
        }

        if ($status === Subscription::GRACE) {
            $graceDays = $clock->graceDaysRemaining($subscription, $timezone);

            return $this->nearest((array) $settings->get('grace_reminder_days'), $graceDays, fn (int $d) => "grace_{$d}") ?? 'expired';
        }

        if ($status === Subscription::EXPIRED || $status === Subscription::CANCELLED) {
            return 'locked';
        }

        return null;
    }

    /** The smallest configured offset the countdown has reached. */
    private function nearest(array $offsets, ?int $days, \Closure $name): ?string
    {
        if ($days === null) {
            return null;
        }

        $reached = collect($offsets)->map(fn ($d) => (int) $d)->filter(fn (int $d) => $days <= $d)->sort()->first();

        return $reached === null ? null : $name($reached);
    }
}
