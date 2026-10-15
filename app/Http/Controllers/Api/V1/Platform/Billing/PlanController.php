<?php

namespace App\Http\Controllers\Api\V1\Platform\Billing;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Platform\Billing\SavePlanRequest;
use App\Http\Resources\Platform\Billing\PlanResource;
use App\Models\Platform\Module;
use App\Models\Platform\Plan;
use App\Services\Subscriptions\BillingAnalytics;
use App\Services\Subscriptions\BillingSettings;
use App\Services\Subscriptions\PricingCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The plans on the price list — created, priced, published and retired by
 * Super Admin, with no code change.
 *
 * Editing a plan changes what is sold from now on, and what each subscription
 * on it renews at. It never changes a running term: that lives in the
 * subscription's own snapshot. Every change is on the audit log
 * (RecordsHistory), field by field.
 *
 * @group Platform > Billing
 */
class PlanController extends BaseApiController
{
    public function __construct(
        private readonly PricingCatalog $catalog,
    ) {}

    /** Every plan, with how many organizations are on each now. */
    public function index(Request $request): JsonResponse
    {
        $plans = Plan::query()
            ->with(['limits', 'modules'])
            ->withCount(['subscriptions' => fn ($q) => $q->where('is_current', true)])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderBy('is_system', 'desc')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $this->ok(PlanResource::collection($plans));
    }

    /** Totals for the plans screen: plans, active subscriptions, MRR, average value, most popular. */
    public function summary(BillingAnalytics $analytics): JsonResponse
    {
        return $this->ok($analytics->plans());
    }

    /** Revenue against renewals already due, and revenue by module — for the pricing screen. */
    public function pricing(BillingAnalytics $analytics): JsonResponse
    {
        return $this->ok($analytics->pricing());
    }

    public function show(Plan $plan): JsonResponse
    {
        $plan->load(['limits', 'modules'])->loadCount(['subscriptions' => fn ($q) => $q->where('is_current', true)]);

        return $this->ok(PlanResource::make($plan));
    }

    /** A new plan starts as a draft — nobody can buy it until it is published. */
    public function store(SavePlanRequest $request): JsonResponse
    {
        $plan = DB::transaction(function () use ($request) {
            $plan = Plan::query()->create([
                ...$this->attributes($request),
                'slug' => $request->validated('slug'),
                'status' => Plan::DRAFT,
            ]);

            $this->syncContents($plan, $request);

            return $plan;
        });

        $this->catalog->bumpVersion();

        return $this->created(PlanResource::make($plan->load(['limits', 'modules'])), 'Plan created as a draft.');
    }

    public function update(SavePlanRequest $request, Plan $plan): JsonResponse
    {
        $this->refuseSystem($plan);

        DB::transaction(function () use ($request, $plan) {
            $plan->update([
                ...$this->attributes($request),
                ...($request->filled('slug') ? ['slug' => $request->validated('slug')] : []),
            ]);

            $this->syncContents($plan, $request);
        });

        $this->catalog->bumpVersion();

        return $this->ok(
            PlanResource::make($plan->refresh()->load(['limits', 'modules'])),
            $plan->status === Plan::PUBLISHED
                ? 'Plan saved. New sales and renewals use it; running terms keep what they agreed.'
                : 'Plan saved.',
        );
    }

    /**
     * Publish, unpublish, archive, or return to draft.
     *
     * Archiving removes a plan from sale for good; organizations already on
     * it keep their term, and renew at their snapshot's price.
     */
    public function status(Request $request, Plan $plan, BillingSettings $settings): JsonResponse
    {
        $this->refuseSystem($plan);

        $to = $request->validate([
            'status' => ['required', Rule::in(Plan::STATUSES)],
        ])['status'];

        $allowed = [
            Plan::DRAFT => [Plan::PUBLISHED, Plan::ARCHIVED],
            Plan::PUBLISHED => [Plan::UNPUBLISHED, Plan::ARCHIVED],
            Plan::UNPUBLISHED => [Plan::PUBLISHED, Plan::ARCHIVED, Plan::DRAFT],
            Plan::ARCHIVED => [Plan::UNPUBLISHED],
        ];

        if (! in_array($to, $allowed[$plan->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => "A {$plan->status} plan cannot become {$to}."]);
        }

        if ($to !== Plan::PUBLISHED && (int) $settings->get('default_trial_plan_id') === $plan->id) {
            throw ValidationException::withMessages(['status' => 'This is the default trial plan for new organizations. Choose another in Billing Settings first.']);
        }

        $plan->update(['status' => $to]);
        $this->catalog->bumpVersion();

        return $this->ok(PlanResource::make($plan->load(['limits', 'modules'])), "Plan {$to}.");
    }

    /** Only a draft nobody has ever been subscribed to can be deleted. */
    public function destroy(Plan $plan): JsonResponse
    {
        $this->refuseSystem($plan);

        if ($plan->status !== Plan::DRAFT || $plan->subscriptions()->exists()) {
            throw ValidationException::withMessages(['plan' => 'Only an unused draft can be deleted. Archive this plan instead.']);
        }

        $plan->delete();
        $this->catalog->bumpVersion();

        return $this->ok(null, 'Draft plan deleted.');
    }

    /** @return array<string, mixed> */
    private function attributes(SavePlanRequest $request): array
    {
        return [
            'name' => $request->validated('name'),
            'description' => $request->validated('description'),
            'is_public' => (bool) $request->validated('is_public', false),
            'sort_order' => (int) $request->validated('sort_order', 0),
            'currency' => strtoupper((string) $request->validated('currency', 'INR')),
            'monthly_price' => (int) $request->validated('monthly_price'),
            'annual_price' => (int) $request->validated('annual_price'),
            'trial_days' => (int) $request->validated('trial_days'),
            'grace_days' => (int) $request->validated('grace_days'),
            'features' => array_values(array_unique((array) $request->validated('features', []))),
            'highlights' => array_values((array) $request->validated('highlights', [])),
        ];
    }

    /** Limits and modules, written through their models so each is audited. */
    private function syncContents(Plan $plan, SavePlanRequest $request): void
    {
        foreach ((array) $request->validated('limits') as $key => $value) {
            $plan->limits()->updateOrCreate(['key' => $key], ['value' => $value === null ? null : (int) $value]);
        }

        $before = $plan->modules()->pluck('key')->sort()->values()->all();

        $plan->modules()->sync(
            Module::query()->whereIn('key', (array) $request->validated('modules', []))->pluck('id')
        );

        // A pivot raises no model events, so the change to the set is written
        // against the plan itself — "who took Pharmacy out of Growth".
        $after = $plan->modules()->pluck('key')->sort()->values()->all();

        if ($before !== $after) {
            $plan->writeHistoryFor('modules', $before, $after);
        }
    }

    private function refuseSystem(Plan $plan): void
    {
        if ($plan->is_system) {
            throw ValidationException::withMessages(['plan' => 'The Complimentary plan is managed by the platform and cannot be changed.']);
        }
    }
}
