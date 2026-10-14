<?php

namespace App\Http\Controllers\Api\V1\Platform\Billing;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Resources\Platform\Billing\SubscriptionEventResource;
use App\Http\Resources\Platform\Billing\SubscriptionResource;
use App\Models\Platform\Organization;
use App\Models\Platform\Subscription;
use App\Models\Platform\SubscriptionEvent;
use App\Models\Platform\SubscriptionInvoice;
use App\Models\Platform\SubscriptionPayment;
use App\Models\Platform\SubscriptionReminderDelivery;
use App\Models\Platform\UsageRecord;
use App\Services\Subscriptions\SubscriptionClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The platform's subscriptions at a glance, and the list to work from:
 * expiring soon, in grace, locked, on trial.
 *
 * Statuses in the list are the EFFECTIVE ones — what the clock says now — so
 * a subscription whose grace ended an hour ago reads as locked here even
 * before the scheduler has written that down.
 *
 * @group Platform > Billing
 */
class SubscriptionController extends BaseApiController
{
    public function __construct(
        private readonly SubscriptionClock $clock,
    ) {}

    /**
     * Filters: `status` (effective: trial, active, past_due, grace, expired,
     * suspended, cancelled, locked), `expiring_within` (days), `plan_id`,
     * `search` (organization name or code).
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', 'string'],
            'expiring_within' => ['nullable', 'integer', 'min:0', 'max:365'],
            'plan_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $subscriptions = Subscription::query()
            ->where('is_current', true)
            ->with(['organization', 'pendingPlan'])
            ->when($request->query('plan_id'), fn ($q, $id) => $q->where('plan_id', $id))
            ->when($request->query('search'), fn ($q, $term) => $q->whereHas('organization', fn ($o) => $o
                ->where('organization_name', 'ilike', "%{$term}%")
                ->orWhere('organization_code', 'ilike', "%{$term}%")))
            ->when($request->query('expiring_within') !== null, fn ($q) => $q
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now()->addDays((int) $request->query('expiring_within'))))
            ->orderByRaw('expires_at IS NULL, expires_at')
            ->get();

        // Effective status is computed, so it is filtered after the query.
        if ($status = $request->query('status')) {
            $subscriptions = $subscriptions->filter(fn (Subscription $s) => $status === 'locked'
                ? $this->clock->isLocked($s)
                : $this->clock->status($s) === $status)->values();
        }

        $perPage = (int) $request->query('per_page', 25);
        $page = max(1, (int) $request->query('page', 1));

        return $this->ok(
            SubscriptionResource::collection($subscriptions->forPage($page, $perPage)->values()),
            null,
            ['total' => $subscriptions->count(), 'per_page' => $perPage, 'current_page' => $page],
        );
    }

    /**
     * The platform overview: counts by effective status, expiring this week,
     * monthly recurring revenue, this month's revenue, usage cost and margin.
     * Figures come from the subscriptions, invoices, payments and usage
     * ledger — never a sample.
     */
    public function overview(): JsonResponse
    {
        $current = Subscription::query()->where('is_current', true)->get();
        $counts = $current->groupBy(fn (Subscription $s) => $this->clock->status($s))->map->count();

        $mrr = $current
            ->filter(fn (Subscription $s) => ! $s->isComplimentary() && ! $this->clock->isLocked($s)
                && $this->clock->status($s) !== Subscription::TRIAL)
            ->sum(fn (Subscription $s) => $s->billing_cycle === Subscription::ANNUAL
                ? intdiv($s->snapshotPrice(), 12)
                : $s->snapshotPrice());

        $monthStart = Carbon::now()->startOfMonth();

        $collected = SubscriptionPayment::query()
            ->where('status', SubscriptionPayment::CAPTURED)
            ->where('verified_at', '>=', $monthStart)
            ->sum('amount');

        $usage = UsageRecord::query()->where('occurred_at', '>=', $monthStart)->get(['status', 'provider_cost_e4', 'charge_paise']);
        $sign = fn (UsageRecord $r) => $r->status === UsageRecord::REVERSED ? -1 : 1;
        $providerCost = intdiv($usage->sum(fn ($r) => $sign($r) * $r->provider_cost_e4) + 50, 100);
        $usageRevenue = $usage->sum(fn ($r) => $sign($r) * $r->charge_paise);

        return $this->ok([
            'counts' => [
                'organizations' => Organization::query()->count(),
                'without_subscription' => Organization::query()->whereDoesntHave('subscriptions', fn ($q) => $q->where('is_current', true))->count(),
                'by_status' => $counts,
                'locked' => $current->filter(fn (Subscription $s) => $this->clock->isLocked($s))->count(),
                'complimentary' => $current->filter->isComplimentary()->count(),
                'expiring_7_days' => $current->filter(fn (Subscription $s) => $s->expires_at !== null
                    && $s->expires_at->isFuture() && $s->expires_at <= now()->addDays(7))->count(),
            ],
            'revenue' => [
                'mrr' => $mrr,
                'collected_this_month' => (int) $collected,
                'outstanding' => (int) SubscriptionInvoice::query()->where('status', SubscriptionInvoice::ISSUED)->sum('total'),
                'usage_revenue_this_month' => (int) $usageRevenue,
                'usage_provider_cost_this_month' => $providerCost,
                'usage_gross_margin_this_month' => (int) $usageRevenue - $providerCost,
            ],
            'reminders' => [
                'failed_last_7_days' => SubscriptionReminderDelivery::query()
                    ->where('status', SubscriptionReminderDelivery::FAILED)
                    ->where('created_at', '>=', now()->subDays(7))->count(),
            ],
            'recent_events' => SubscriptionEventResource::collection(
                SubscriptionEvent::query()->with('organization:id,organization_name')->latest('occurred_at')->limit(15)->get()
            ),
            'currency' => 'INR',
        ]);
    }
}
