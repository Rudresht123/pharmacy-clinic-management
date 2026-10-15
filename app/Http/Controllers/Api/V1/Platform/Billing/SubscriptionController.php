<?php

namespace App\Http\Controllers\Api\V1\Platform\Billing;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Resources\Platform\Billing\SubscriptionEventResource;
use App\Http\Resources\Platform\Billing\SubscriptionResource;
use App\Models\Platform\Organization;
use App\Models\Platform\Subscription;
use App\Models\Platform\SubscriptionEvent;
use App\Models\Platform\SubscriptionReminderDelivery;
use App\Services\Subscriptions\BillingAnalytics;
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
     * The platform overview for a date range (default: this month): revenue,
     * active subscriptions, invoices, pending and overdue — each against the
     * previous period of the same length — plus the revenue trend, revenue by
     * plan, subscription status and the latest invoices. All counted from the
     * records; a chart with nothing behind it comes back empty.
     */
    public function overview(Request $request, BillingAnalytics $analytics): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = $request->query('from') ? Carbon::parse($request->query('from'))->startOfDay() : Carbon::now()->startOfMonth();
        $to = $request->query('to') ? Carbon::parse($request->query('to'))->endOfDay() : Carbon::now()->endOfMonth();

        $current = Subscription::query()->where('is_current', true)->get();

        return $this->ok([
            ...$analytics->overview($from, $to),
            'attention' => [
                'locked' => $current->filter(fn (Subscription $s) => $this->clock->isLocked($s))->count(),
                'without_subscription' => Organization::query()->whereDoesntHave('subscriptions', fn ($q) => $q->where('is_current', true))->count(),
                'failed_reminders' => SubscriptionReminderDelivery::query()
                    ->where('status', SubscriptionReminderDelivery::FAILED)
                    ->where('created_at', '>=', now()->subDays(7))->count(),
            ],
            'recent_events' => SubscriptionEventResource::collection(
                SubscriptionEvent::query()->with('organization:id,organization_name')->latest('occurred_at')->limit(10)->get()
            ),
            'currency' => 'INR',
        ]);
    }

    /** Tiles, growth over twelve months, and the spread across plans. */
    public function insights(BillingAnalytics $analytics): JsonResponse
    {
        return $this->ok($analytics->subscriptions());
    }
}
