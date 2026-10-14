<?php

namespace App\Http\Controllers\Api\V1\Platform\Billing;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Resources\Platform\Billing\SubscriptionEventResource;
use App\Http\Resources\Platform\Billing\SubscriptionResource;
use App\Models\Platform\Addon;
use App\Models\Platform\Organization;
use App\Models\Platform\Plan;
use App\Models\Platform\Subscription;
use App\Models\Platform\SubscriptionEvent;
use App\Models\Platform\SubscriptionInvoice;
use App\Models\Platform\SubscriptionItem;
use App\Models\Platform\SubscriptionReminderDelivery;
use App\Services\Modules\ModuleAccess;
use App\Services\Subscriptions\Actor;
use App\Services\Subscriptions\EntitlementService;
use App\Services\Subscriptions\SubscriptionLifecycle;
use App\Services\Subscriptions\SubscriptionOnboarding;
use App\Services\Subscriptions\UsageBalances;
use App\Support\Subscriptions\Entitlements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * One organization's subscription, from the Super Admin's side: everything
 * about it on one screen, and every lever — each needing a written reason,
 * each recorded as an event naming the administrator, with before and after.
 *
 * The levers only ever go through SubscriptionLifecycle. Nothing here writes
 * a subscription row itself, and nothing here touches the organization's own
 * (security) status — that stays on the organization screen.
 *
 * @group Platform > Billing
 */
class OrganizationSubscriptionController extends BaseApiController
{
    public function __construct(
        private readonly SubscriptionLifecycle $lifecycle,
        private readonly EntitlementService $entitlements,
    ) {}

    public function show(Organization $organization, UsageBalances $balances, ModuleAccess $modules): JsonResponse
    {
        $subscription = $this->current($organization, required: false);

        // Live counts come from the tenant database; one that cannot be
        // opened (still provisioning, or down) must not take the screen down.
        try {
            $resources = $this->entitlements->resources($organization);
        } catch (Throwable $e) {
            report($e);
            $resources = null;
        }

        return $this->ok([
            'organization' => [
                'uuid' => $organization->uuid,
                'name' => $organization->organization_name,
                'status' => $organization->status,
                'suspension_reason' => $organization->suspension_reason,
                'timezone' => $organization->timezone,
            ],
            'subscription' => $subscription ? SubscriptionResource::make($subscription->load(['organization', 'pendingPlan'])) : null,
            'status' => $this->entitlements->status($organization),
            'items' => $subscription ? $subscription->items()->latest('id')->get()->map(fn (SubscriptionItem $item) => [
                'id' => $item->id,
                'type' => $item->type,
                'ref' => $item->ref,
                'target' => $item->target,
                'target_label' => $item->target ? Entitlements::label($item->target) : null,
                'quantity' => $item->quantity,
                'grants' => $item->grants,
                'unit_price' => $item->unit_price,
                'billing' => $item->billing,
                'status' => $item->status,
                'is_live' => $item->isLive(),
                'starts_at' => $item->starts_at?->toIso8601String(),
                'ends_at' => $item->ends_at?->toIso8601String(),
                'note' => $item->note,
            ]) : [],
            'resources' => $resources,
            'usage' => $balances->summary($organization),
            'modules' => $modules->overview($organization),
            'events' => SubscriptionEventResource::collection(
                SubscriptionEvent::query()->where('organization_id', $organization->id)
                    ->latest('occurred_at')->latest('id')->limit(50)->get()
            ),
            'history' => $organization->subscriptions()->with('pendingPlan')->limit(10)->get()
                ->map(fn (Subscription $s) => SubscriptionResource::make($s->setRelation('organization', $organization))),
            'invoices' => SubscriptionInvoice::query()->where('organization_id', $organization->id)->latest('id')->limit(20)->get()
                ->map(fn (SubscriptionInvoice $i) => [
                    'uuid' => $i->uuid,
                    'number' => $i->number,
                    'kind' => $i->kind,
                    'status' => $i->status,
                    'total' => $i->total,
                    'currency' => $i->currency,
                    'issued_at' => $i->issued_at?->toIso8601String(),
                    'due_at' => $i->due_at?->toIso8601String(),
                    'paid_at' => $i->paid_at?->toIso8601String(),
                ]),
            'reminders' => SubscriptionReminderDelivery::query()->where('organization_id', $organization->id)->latest('id')->limit(20)->get()
                ->map(fn (SubscriptionReminderDelivery $r) => $r->only(['kind', 'channel', 'status', 'error', 'sent_at', 'created_at'])),
        ]);
    }

    /** Put the organization on a plan — a new contract. */
    public function assign(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer', Rule::exists(Plan::class, 'id')],
            'billing_cycle' => ['required', Rule::in(Subscription::CYCLES)],
            'trial' => ['boolean'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:today'],
            'price' => ['nullable', 'integer', 'min:0'],
            'payment_status' => ['nullable', Rule::in([Subscription::PAYMENT_PENDING, Subscription::PAYMENT_PAID, Subscription::PAYMENT_WAIVED])],
            ...$this->reasonRule(),
        ]);

        $subscription = $this->lifecycle->assign(
            $organization,
            Plan::query()->findOrFail($data['plan_id']),
            $data['billing_cycle'],
            $this->actor($request),
            $data['reason'],
            array_filter([
                'trial' => $data['trial'] ?? false,
                'starts_at' => isset($data['starts_at']) ? Carbon::parse($data['starts_at']) : null,
                'expires_at' => isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null,
                'price' => $data['price'] ?? null,
                'payment_status' => $data['payment_status'] ?? null,
            ], fn ($v) => $v !== null),
        );

        return $this->ok(SubscriptionResource::make($subscription->load('organization')), 'Plan assigned.');
    }

    /** Move to another plan within the term — now, or at its end. */
    public function changePlan(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer', Rule::exists(Plan::class, 'id')],
            'billing_cycle' => ['required', Rule::in([Subscription::MONTHLY, Subscription::ANNUAL])],
            'immediately' => ['boolean'],
            ...$this->reasonRule(),
        ]);

        $subscription = $this->lifecycle->changePlan(
            $this->current($organization),
            Plan::query()->findOrFail($data['plan_id']),
            $data['billing_cycle'],
            $this->actor($request),
            $data['reason'],
            (bool) ($data['immediately'] ?? true),
        );

        return $this->ok(SubscriptionResource::make($subscription->load('organization')), ($data['immediately'] ?? true)
            ? 'Plan changed.'
            : 'Plan change scheduled for the end of the term.');
    }

    /** Give a trial of a plan, now — a new trial contract. */
    public function trial(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer', Rule::exists(Plan::class, 'id')],
            ...$this->reasonRule(),
        ]);

        $subscription = $this->lifecycle->startTrial($organization, Plan::query()->findOrFail($data['plan_id']), $this->actor($request), $data['reason']);

        return $this->ok(SubscriptionResource::make($subscription->load('organization')), 'Trial started.');
    }

    public function extend(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate([
            'until' => ['required_without:days', 'nullable', 'date'],
            'days' => ['required_without:until', 'nullable', 'integer', 'min:1', 'max:3650'],
            ...$this->reasonRule(),
        ]);

        $subscription = $this->current($organization);
        $until = isset($data['until'])
            ? Carbon::parse($data['until'])
            : ($subscription->expires_at && $subscription->expires_at->isFuture() ? $subscription->expires_at->copy() : now())->addDays((int) $data['days']);

        $subscription = $this->lifecycle->extend($subscription, $until, $this->actor($request), $data['reason']);

        return $this->ok(SubscriptionResource::make($subscription->load('organization')), 'Subscription extended.');
    }

    public function suspend(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate($this->reasonRule());

        $subscription = $this->lifecycle->suspend($this->current($organization), $this->actor($request), $data['reason']);

        return $this->ok(SubscriptionResource::make($subscription->load('organization')), 'Subscription suspended. The organization is locked.');
    }

    public function unlock(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate($this->reasonRule());

        $subscription = $this->lifecycle->unlock($this->current($organization), $this->actor($request), $data['reason']);

        return $this->ok(SubscriptionResource::make($subscription->load('organization')), 'Suspension lifted.');
    }

    public function cancel(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate([
            'immediately' => ['boolean'],
            ...$this->reasonRule(),
        ]);

        $subscription = $this->lifecycle->cancel($this->current($organization), $this->actor($request), $data['reason'], (bool) ($data['immediately'] ?? false));

        return $this->ok(SubscriptionResource::make($subscription->load('organization')), ($data['immediately'] ?? false)
            ? 'Subscription cancelled; the organization is locked.'
            : 'Subscription will end at the close of its term.');
    }

    /**
     * Enable an add-on or module add-on. `unit_price` defaults to the list
     * price; 0 enables it free (a goodwill grant), recorded as such.
     */
    public function addAddon(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in([SubscriptionItem::MODULE, SubscriptionItem::ADDON])],
            'ref' => ['required', 'string', 'max:60'],
            'quantity' => ['integer', 'min:1', 'max:1000'],
            'unit_price' => ['nullable', 'integer', 'min:0'],
            ...$this->reasonRule(),
        ]);

        $subscription = $this->current($organization);
        $cycle = $subscription->isComplimentary() ? Subscription::MONTHLY : $subscription->billing_cycle;

        $listPrice = $data['type'] === SubscriptionItem::MODULE
            ? $this->entitlements->getEffectivePrice($organization, "module:{$data['ref']}")
            : Addon::query()->where('key', $data['ref'])->first()?->priceFor($cycle);

        $item = $this->lifecycle->addAddon(
            $subscription,
            $data['type'],
            $data['ref'],
            (int) ($data['quantity'] ?? 1),
            (int) ($data['unit_price'] ?? $listPrice ?? 0),
            $this->actor($request),
            $data['reason'],
        );

        return $this->created(['id' => $item->id], 'Add-on enabled.');
    }

    public function removeItem(Request $request, Organization $organization, SubscriptionItem $item): JsonResponse
    {
        $data = $request->validate($this->reasonRule());

        abort_unless($item->subscription?->organization_id === $organization->id, 404);

        $this->lifecycle->removeItem($item, $this->actor($request), $data['reason']);

        return $this->ok(null, 'Removed.');
    }

    public function grantCredits(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate([
            'quota' => ['required', Rule::in(Entitlements::QUOTAS)],
            'amount' => ['required', 'integer', 'min:1', 'max:10000000'],
            ...$this->reasonRule(),
        ]);

        $this->lifecycle->grantCredits($this->current($organization), $data['quota'], (int) $data['amount'], $this->actor($request), $data['reason']);

        return $this->ok(null, 'Credits granted for this month.');
    }

    public function overrideLimit(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', Rule::in(Entitlements::RESOURCES)],
            'value' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'until' => ['nullable', 'date', 'after:today'],
            ...$this->reasonRule(),
        ]);

        $this->lifecycle->overrideLimit(
            $this->current($organization),
            $data['key'],
            $data['value'] ?? null,
            $this->actor($request),
            $data['reason'],
            isset($data['until']) ? Carbon::parse($data['until'])->endOfDay() : null,
        );

        return $this->ok(null, 'Limit overridden.');
    }

    public function grantFeature(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate([
            'feature' => ['required', Rule::in(Entitlements::FEATURES)],
            'until' => ['nullable', 'date', 'after:today'],
            ...$this->reasonRule(),
        ]);

        $this->lifecycle->grantFeature(
            $this->current($organization),
            $data['feature'],
            $this->actor($request),
            $data['reason'],
            isset($data['until']) ? Carbon::parse($data['until'])->endOfDay() : null,
        );

        return $this->ok(null, 'Feature granted.');
    }

    public function events(Organization $organization): JsonResponse
    {
        return $this->ok(SubscriptionEventResource::collection(
            SubscriptionEvent::query()
                ->where('organization_id', $organization->id)
                ->latest('occurred_at')->latest('id')
                ->limit(200)->get()
        ));
    }

    /** @return array<string, list<string>> */
    private function reasonRule(): array
    {
        return ['reason' => ['required', 'string', 'min:5', 'max:500']];
    }

    private function actor(Request $request): Actor
    {
        return Actor::platform($request->user('platform'));
    }

    private function current(Organization $organization, bool $required = true): ?Subscription
    {
        $subscription = Subscription::query()->where('organization_id', $organization->id)->where('is_current', true)->first();

        if ($subscription === null && $required) {
            // Should not exist — but never block an administrator on it.
            $subscription = app(SubscriptionOnboarding::class)->start($organization, [], $this->actorOrSystem());
        }

        return $subscription;
    }

    private function actorOrSystem(): Actor
    {
        $user = request()->user('platform');

        return $user ? Actor::platform($user) : Actor::system();
    }
}
