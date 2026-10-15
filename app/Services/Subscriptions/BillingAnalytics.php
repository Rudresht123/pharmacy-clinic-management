<?php

namespace App\Services\Subscriptions;

use App\Models\Platform\Module;
use App\Models\Platform\Plan;
use App\Models\Platform\PublicRegistration;
use App\Models\Platform\Subscription;
use App\Models\Platform\SubscriptionInvoice;
use App\Models\Platform\SubscriptionPayment;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The figures behind the Super Admin's billing screens — every one counted
 * from subscriptions, invoices and payments. Nothing is sampled or invented:
 * a chart with no data behind it gets an empty series and draws its empty
 * state.
 *
 * Amounts are paise. "Revenue" is money actually received (captured
 * payments); "invoiced" is what was billed (issued, not void).
 */
class BillingAnalytics
{
    public function __construct(
        private readonly SubscriptionClock $clock,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Overview
    |--------------------------------------------------------------------------
    */

    /** @return array<string, mixed> */
    public function overview(CarbonInterface $from, CarbonInterface $to): array
    {
        $length = $from->diffInSeconds($to);
        $prevTo = Carbon::instance($from)->subSecond();
        $prevFrom = $prevTo->copy()->subSeconds((int) $length);

        $revenue = $this->collected($from, $to);
        $prevRevenue = $this->collected($prevFrom, $prevTo);

        $invoices = $this->issuedCount($from, $to);
        $prevInvoices = $this->issuedCount($prevFrom, $prevTo);

        $current = $this->currentSubscriptions();
        $active = $current->filter(fn (Subscription $s) => ! $this->clock->isLocked($s))->count();
        $activeBefore = $this->activeAt($from);

        $open = SubscriptionInvoice::query()->where('status', SubscriptionInvoice::ISSUED)->get(['total', 'due_at']);
        $overdue = $open->filter(fn ($i) => $i->due_at !== null && $i->due_at->isPast());

        return [
            'range' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'kpis' => [
                'total_revenue' => ['value' => $revenue, 'change' => $this->change($revenue, $prevRevenue)],
                'active_subscriptions' => ['value' => $active, 'change' => $this->change($active, $activeBefore)],
                'total_invoices' => ['value' => $invoices, 'change' => $this->change($invoices, $prevInvoices)],
                'pending_payments' => ['value' => (int) $open->sum('total'), 'count' => $open->count(), 'change' => null],
                'overdue_invoices' => ['value' => $overdue->count(), 'amount' => (int) $overdue->sum('total'), 'change' => null],
            ],
            'revenue_trend' => $this->revenueTrend($to, 6),
            'revenue_by_plan' => $this->revenueByPlan($from, $to),
            'subscription_status' => $this->statusBreakdown($current),
            'recent_invoices' => SubscriptionInvoice::query()
                ->with('organization:id,uuid,organization_name')
                ->where('status', '!=', SubscriptionInvoice::DRAFT)
                ->latest('issued_at')->latest('id')->limit(5)->get()
                ->map(fn (SubscriptionInvoice $i) => $this->invoiceRow($i))->all(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Subscriptions
    |--------------------------------------------------------------------------
    */

    /** @return array<string, mixed> */
    public function subscriptions(): array
    {
        $current = $this->currentSubscriptions();
        $status = $this->statusBreakdown($current);

        $months = $this->months(Carbon::now(), 12);

        return [
            'tiles' => [
                'active' => $status['active'] + $status['expiring_soon'],
                'active_change' => $this->change($status['active'] + $status['expiring_soon'], $this->activeAt(Carbon::now()->subDays(30))),
                'expiring_soon' => $status['expiring_soon'],
                'suspended' => $status['suspended'],
                'cancelled' => $status['cancelled'],
                'trial' => $status['trial'],
                'grace' => $status['grace'],
                'expired' => $status['expired'],
            ],
            'growth' => $months->map(fn (array $m) => [
                'key' => $m['key'],
                'label' => $m['label'],
                'title' => $m['title'],
                'value' => $this->activeAt($m['end']),
            ])->values()->all(),
            'plan_distribution' => $current
                ->groupBy(fn (Subscription $s) => $s->snapshot['plan']['name'] ?? 'Unknown')
                ->map(fn (Collection $rows, string $name) => ['label' => $name, 'value' => $rows->count()])
                ->values()->all(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Plans
    |--------------------------------------------------------------------------
    */

    /** @return array<string, mixed> */
    public function plans(): array
    {
        $current = $this->currentSubscriptions();
        $paying = $current->filter(fn (Subscription $s) => ! $s->isComplimentary() && ! $this->clock->isLocked($s)
            && $this->clock->status($s) !== Subscription::TRIAL);

        $mrr = (int) $paying->sum(fn (Subscription $s) => $this->monthly($s));

        return [
            'total_plans' => Plan::query()->where('status', '!=', Plan::ARCHIVED)->where('is_system', false)->count(),
            'active_subscriptions' => $current->filter(fn (Subscription $s) => ! $this->clock->isLocked($s))->count(),
            'monthly_revenue' => $mrr,
            'average_plan_value' => $paying->isEmpty() ? 0 : intdiv($mrr, $paying->count()),
            'subscribers' => $current->groupBy('plan_id')->map->count()->all(),
            'most_popular_plan_id' => $current->filter(fn (Subscription $s) => ! $s->isComplimentary())
                ->groupBy('plan_id')->map->count()->sortDesc()->keys()->first(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Pricing
    |--------------------------------------------------------------------------
    */

    /**
     * Received per month, and what renewals already due would bring — the
     * last three months, this one, and the next two.
     *
     * @return array<string, mixed>
     */
    public function pricing(): array
    {
        $months = $this->months(Carbon::now()->addMonthsNoOverflow(2), 6);
        $current = $this->currentSubscriptions()->filter(fn (Subscription $s) => ! $s->isComplimentary() && $s->cancelled_at === null);

        $points = $months->map(function (array $m) use ($current) {
            $projected = $m['end']->isPast() ? 0 : (int) $current
                ->filter(fn (Subscription $s) => $s->next_renewal_at !== null && $s->next_renewal_at->between($m['start'], $m['end']))
                ->sum(fn (Subscription $s) => $s->snapshotPrice());

            return [
                'key' => $m['key'],
                'label' => $m['label'],
                'title' => $m['title'],
                'values' => [$m['start']->isFuture() ? 0 : $this->collected($m['start'], $m['end']), $projected],
            ];
        })->values()->all();

        return [
            'trend' => $points,
            'revenue_by_module' => $this->revenueByLine(Carbon::now()->subMonthsNoOverflow(11)->startOfMonth(), Carbon::now()),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Invoices
    |--------------------------------------------------------------------------
    */

    /** @return array<string, mixed> */
    public function invoices(): array
    {
        $all = SubscriptionInvoice::query()->where('status', '!=', SubscriptionInvoice::DRAFT)->get(['status', 'total', 'issued_at', 'due_at', 'paid_at']);

        $state = fn (SubscriptionInvoice $i) => match (true) {
            $i->status === SubscriptionInvoice::PAID => 'paid',
            $i->status === SubscriptionInvoice::VOID => 'void',
            $i->due_at !== null && $i->due_at->isPast() => 'overdue',
            default => 'pending',
        };

        $grouped = $all->groupBy($state);

        $trend = $this->months(Carbon::now(), 6)->map(fn (array $m) => [
            'key' => $m['key'],
            'label' => $m['label'],
            'title' => $m['title'],
            'values' => collect(['paid', 'pending', 'overdue'])->map(fn (string $s) => (int) ($grouped->get($s, collect())
                ->filter(fn ($i) => $i->issued_at !== null && $i->issued_at->between($m['start'], $m['end']))
                ->sum('total')))->all(),
        ])->values()->all();

        $methods = SubscriptionPayment::query()
            ->where('status', SubscriptionPayment::CAPTURED)
            ->get(['method', 'amount', 'payload'])
            ->groupBy(fn (SubscriptionPayment $p) => $p->method === SubscriptionPayment::MANUAL
                ? 'Bank transfer / manual'
                : match ($p->payload['method'] ?? null) {
                    'upi' => 'UPI',
                    'card' => 'Card',
                    'netbanking' => 'Net banking',
                    'wallet' => 'Wallet',
                    default => 'Online',
                })
            ->map(fn (Collection $rows, string $label) => ['label' => $label, 'value' => (int) $rows->sum('amount')])
            ->values()->all();

        return [
            'tiles' => [
                'total_invoices' => $all->where('status', '!=', SubscriptionInvoice::VOID)->count(),
                'paid_amount' => (int) $grouped->get('paid', collect())->sum('total'),
                'pending_amount' => (int) $grouped->get('pending', collect())->sum('total'),
                'overdue_amount' => (int) $grouped->get('overdue', collect())->sum('total'),
            ],
            'status' => collect(['paid' => 'Paid', 'pending' => 'Pending', 'overdue' => 'Overdue', 'void' => 'Void'])
                ->map(fn (string $label, string $key) => ['label' => $label, 'value' => $grouped->get($key, collect())->count()])
                ->values()->all(),
            'trend' => $trend,
            'payment_methods' => $methods,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Pieces
    |--------------------------------------------------------------------------
    */

    /** @return Collection<int, Subscription> */
    private function currentSubscriptions(): Collection
    {
        return Subscription::query()->where('is_current', true)->get();
    }

    /** Counts by effective status, with "expiring soon" (30 days) split from active. @return array<string, int> */
    private function statusBreakdown(Collection $current): array
    {
        $counts = array_fill_keys(['active', 'expiring_soon', 'trial', 'grace', 'expired', 'suspended', 'cancelled', 'complimentary'], 0);

        foreach ($current as $subscription) {
            $status = $this->clock->status($subscription);

            if ($subscription->isComplimentary() && $status === Subscription::ACTIVE) {
                $counts['complimentary']++;

                continue;
            }

            if (in_array($status, [Subscription::ACTIVE, Subscription::PAST_DUE], true)
                && $subscription->expires_at !== null
                && $subscription->expires_at <= Carbon::now()->addDays(30)) {
                $counts['expiring_soon']++;

                continue;
            }

            $key = $status === Subscription::PAST_DUE ? 'active' : $status;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    /** Subscriptions running at an instant, from their own dates. */
    private function activeAt(CarbonInterface $at): int
    {
        return Subscription::query()
            ->where('started_at', '<=', $at)
            ->where(fn ($q) => $q->whereNull('cancelled_at')->orWhere('cancelled_at', '>', $at))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('grace_ends_at', '>', $at)->orWhere('expires_at', '>', $at))
            ->count();
    }

    private function collected(CarbonInterface $from, CarbonInterface $to): int
    {
        return (int) SubscriptionPayment::query()
            ->where('status', SubscriptionPayment::CAPTURED)
            ->whereBetween('verified_at', [$from, $to])
            ->sum('amount');
    }

    private function issuedCount(CarbonInterface $from, CarbonInterface $to): int
    {
        return SubscriptionInvoice::query()
            ->whereIn('status', [SubscriptionInvoice::ISSUED, SubscriptionInvoice::PAID])
            ->whereBetween('issued_at', [$from, $to])
            ->count();
    }

    /** @return list<array<string, mixed>> */
    private function revenueTrend(CarbonInterface $end, int $count): array
    {
        return $this->months($end, $count)->map(fn (array $m) => [
            'key' => $m['key'],
            'label' => $m['label'],
            'title' => $m['title'],
            'values' => [
                (int) SubscriptionInvoice::query()
                    ->whereIn('status', [SubscriptionInvoice::ISSUED, SubscriptionInvoice::PAID])
                    ->whereBetween('issued_at', [$m['start'], $m['end']])
                    ->sum('total'),
                $this->collected($m['start'], $m['end']),
            ],
        ])->values()->all();
    }

    /** Money received in the range, by the plan it paid for. @return list<array{label: string, value: int}> */
    private function revenueByPlan(CarbonInterface $from, CarbonInterface $to): array
    {
        $plans = Plan::query()->pluck('name', 'id');

        return SubscriptionPayment::query()
            ->where('status', SubscriptionPayment::CAPTURED)
            ->whereBetween('verified_at', [$from, $to])
            ->with('invoice.subscription:id,plan_id', 'invoice:id,subscription_id,public_registration_id,kind')
            ->get()
            ->groupBy(function (SubscriptionPayment $payment) use ($plans) {
                $invoice = $payment->invoice;

                if ($invoice?->kind === SubscriptionInvoice::KIND_OVERAGE) {
                    return 'Usage';
                }

                $planId = $invoice?->subscription?->plan_id
                    ?? ($invoice?->public_registration_id
                        ? PublicRegistration::query()->whereKey($invoice->public_registration_id)->value('plan_id')
                        : null);

                return $plans[$planId] ?? 'Other';
            })
            ->map(fn (Collection $rows, string $label) => ['label' => $label, 'value' => (int) $rows->sum('amount')])
            ->values()->all();
    }

    /**
     * Paid invoice lines, by what they were for: each module add-on by name,
     * plan subscriptions together, other add-ons, and usage.
     *
     * @return list<array{label: string, value: int}>
     */
    private function revenueByLine(CarbonInterface $from, CarbonInterface $to): array
    {
        $modules = Module::query()->pluck('name', 'key');
        $buckets = [];

        SubscriptionInvoice::query()
            ->where('status', SubscriptionInvoice::PAID)
            ->whereBetween('paid_at', [$from, $to])
            ->get(['lines'])
            ->each(function (SubscriptionInvoice $invoice) use ($modules, &$buckets) {
                foreach ((array) $invoice->lines as $line) {
                    $label = match ($line['type'] ?? null) {
                        'module' => ($modules[$line['ref'] ?? ''] ?? ($line['ref'] ?? 'Module')),
                        'plan', 'upgrade' => 'Plan subscriptions',
                        'overage' => 'Usage',
                        default => 'Add-ons',
                    };

                    $buckets[$label] = ($buckets[$label] ?? 0) + (int) ($line['amount'] ?? 0);
                }
            });

        return collect($buckets)->map(fn (int $value, string $label) => ['label' => $label, 'value' => $value])->values()->all();
    }

    /** What a subscription is worth per month. */
    private function monthly(Subscription $subscription): int
    {
        return $subscription->billing_cycle === Subscription::ANNUAL
            ? intdiv($subscription->snapshotPrice(), 12)
            : $subscription->snapshotPrice();
    }

    /** Percentage change, one decimal; null when there is nothing to compare with. */
    private function change(int $now, int $before): ?float
    {
        if ($before === 0) {
            return null;
        }

        return round(($now - $before) / $before * 100, 1);
    }

    /**
     * The `$count` calendar months ending with the one containing `$end`.
     *
     * @return Collection<int, array{key: string, label: string, title: string, start: Carbon, end: Carbon}>
     */
    private function months(CarbonInterface $end, int $count): Collection
    {
        $last = Carbon::instance($end)->startOfMonth();

        return collect(range($count - 1, 0))->map(function (int $back) use ($last) {
            $start = $last->copy()->subMonthsNoOverflow($back);

            return [
                'key' => $start->format('Y-m'),
                'label' => $start->format('M'),
                'title' => $start->format('F Y'),
                'start' => $start,
                'end' => $start->copy()->endOfMonth(),
            ];
        });
    }

    /** @return array<string, mixed> */
    public function invoiceRow(SubscriptionInvoice $invoice): array
    {
        $overdue = $invoice->status === SubscriptionInvoice::ISSUED && $invoice->due_at !== null && $invoice->due_at->isPast();

        return [
            'uuid' => $invoice->uuid,
            'number' => $invoice->number,
            'organization' => $invoice->organization
                ? ['uuid' => $invoice->organization->uuid, 'name' => $invoice->organization->organization_name]
                : null,
            'bill_to' => $invoice->bill_to['name'] ?? null,
            'total' => $invoice->total,
            'currency' => $invoice->currency,
            'issued_at' => $invoice->issued_at?->toIso8601String(),
            'due_at' => $invoice->due_at?->toIso8601String(),
            'status' => $overdue ? 'overdue' : ($invoice->status === SubscriptionInvoice::ISSUED ? 'pending' : $invoice->status),
        ];
    }
}
