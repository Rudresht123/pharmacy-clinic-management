<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Platform\Addon;
use App\Models\Platform\Module;
use App\Models\Platform\Organization;
use App\Models\Platform\Plan;
use App\Models\Platform\Subscription;
use App\Models\Platform\SubscriptionInvoice;
use App\Models\Platform\SubscriptionItem;
use App\Models\Platform\UsageRecord;
use App\Models\Tenant\User;
use App\Services\Modules\ModuleAccess;
use App\Services\Permissions\Permission;
use App\Services\Subscriptions\EntitlementService;
use App\Services\Subscriptions\InvoiceService;
use App\Services\Subscriptions\Payments\PaymentService;
use App\Services\Subscriptions\Payments\RazorpayGateway;
use App\Services\Subscriptions\PricingCatalog;
use App\Services\Subscriptions\SubscriptionFacts;
use App\Services\Subscriptions\UsageBalances;
use App\Support\Modules\ModuleRegistry;
use App\Support\Subscriptions\Entitlements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The organization's own view of its subscription — and the only way it can
 * change one: by paying for it.
 *
 * Reachable while the organization is LOCKED (EnsureSubscriptionAllowsAccess
 * lets `subscription/*` through), because renewing is how a locked
 * organization gets back in. Only for the owner and holders of
 * `settings.subscription`; everybody else is told to ask them.
 *
 * Nothing here takes a price from the client. A renewal, an upgrade or an
 * add-on is quoted by PricingCatalog, invoiced by InvoiceService, and takes
 * effect only when PaymentConfirmation has verified the money server-side. A
 * clinic cannot set its own plan, limits or dates — those are the Super
 * Admin's — it can only buy.
 *
 * @group Tenant > Subscription
 */
class SubscriptionController extends BaseApiController
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly SubscriptionFacts $facts,
    ) {}

    /** Everything the subscription page shows, from the records. */
    public function show(Request $request, UsageBalances $balances, ModuleAccess $modules, RazorpayGateway $razorpay): JsonResponse
    {
        $organization = $this->authorize($request);
        $subscription = $this->facts->subscription($organization);
        $sources = $modules->sources($organization);

        return $this->ok([
            'status' => $this->entitlements->status($organization),
            'resources' => $this->entitlements->resources($organization),
            'usage' => $balances->summary($organization),
            'modules' => collect(ModuleRegistry::all())
                ->reject(fn (array $module) => $module['is_core'])
                ->map(fn (array $module) => [
                    'key' => $module['key'],
                    'name' => $module['name'],
                    'enabled' => in_array($sources[$module['key']] ?? 'off', ['plan', 'addon', 'manual_grant'], true),
                    'source' => $sources[$module['key']] ?? 'off',
                ])->values(),
            'features' => collect(Entitlements::FEATURES)->map(fn (string $feature) => [
                'key' => $feature,
                'name' => Entitlements::label($feature),
                'enabled' => $this->entitlements->hasFeature($organization, $feature),
            ]),
            'addons' => $subscription ? $subscription->items()->latest('id')->get()
                ->filter(fn (SubscriptionItem $item) => $item->type !== SubscriptionItem::OVERRIDE)
                ->map(fn (SubscriptionItem $item) => [
                    'id' => $item->id,
                    'type' => $item->type,
                    'name' => $item->type === SubscriptionItem::MODULE
                        ? (Module::query()->where('key', $item->ref)->value('name') ?? $item->ref).' module'
                        : (Addon::query()->where('key', $item->ref)->value('name') ?? $item->ref),
                    'quantity' => $item->quantity,
                    'grants' => $item->grants,
                    'target' => $item->target,
                    'billing' => $item->billing,
                    'unit_price' => $item->unit_price,
                    'is_live' => $item->isLive(),
                    'starts_at' => $item->starts_at?->toIso8601String(),
                    'ends_at' => $item->ends_at?->toIso8601String(),
                ])->values() : [],
            'open_invoices' => SubscriptionInvoice::query()
                ->where('organization_id', $organization->id)
                ->where('status', SubscriptionInvoice::ISSUED)
                ->latest('id')->get()->map(fn (SubscriptionInvoice $i) => $this->invoice($i)),
            'payment' => [
                'online' => $razorpay->configured(),
                'key_id' => $razorpay->configured() ? $razorpay->keyId() : null,
            ],
        ]);
    }

    /** What can be bought: published plans, add-ons and module add-ons, at list price. */
    public function catalogue(Request $request, PricingCatalog $catalog): JsonResponse
    {
        $organization = $this->authorize($request);
        $current = $this->facts->subscription($organization);

        return $this->ok([
            'plans' => Plan::query()->purchasable()->with(['limits', 'modules'])->orderBy('sort_order')->get()
                ->map(fn (Plan $plan) => [
                    'id' => $plan->id,
                    'slug' => $plan->slug,
                    'name' => $plan->name,
                    'description' => $plan->description,
                    'monthly_price' => $plan->monthly_price,
                    'annual_price' => $plan->annual_price,
                    'limits' => $plan->limitMap(),
                    'modules' => $plan->moduleKeys(),
                    'features' => $plan->features ?? [],
                    'highlights' => $plan->highlights ?? [],
                    'is_current' => $current?->plan_id === $plan->id,
                ]),
            'addons' => Addon::query()->purchasable()->orderBy('sort_order')->get()->map(fn (Addon $addon) => [
                'key' => $addon->key,
                'name' => $addon->name,
                'description' => $addon->description,
                'kind' => $addon->kind,
                'target' => $addon->target,
                'unit_quantity' => $addon->unit_quantity,
                'billing' => $addon->billing,
                'monthly_price' => $addon->monthly_price,
                'annual_price' => $addon->annual_price,
            ]),
            'modules' => $catalog->modulesWithPrices(publicOnly: false)
                ->filter(fn (array $row) => $row['price']?->is_addon_eligible)
                ->map(fn (array $row) => [
                    'key' => $row['module']->key,
                    'name' => $row['module']->name,
                    'monthly_price' => $row['price']->monthly_price,
                    'annual_price' => $row['price']->annual_price,
                ])->values(),
        ]);
    }

    /** This month's usage, line by line, newest first. */
    public function usage(Request $request, UsageBalances $balances): JsonResponse
    {
        $organization = $this->authorize($request);

        $records = UsageRecord::query()
            ->where('organization_id', $organization->id)
            ->when($request->query('channel'), fn ($q, $channel) => $q->where('channel', $channel))
            ->latest('occurred_at')
            ->paginate(min(100, (int) $request->query('per_page', 25)));

        return $this->ok([
            'balances' => $balances->summary($organization),
            'records' => collect($records->items())->map(fn (UsageRecord $r) => [
                'id' => $r->id,
                'channel' => $r->channel,
                'category' => $r->category,
                'quantity' => $r->quantity,
                'unit' => $r->unit,
                'from_quota' => $r->from_quota,
                'overage' => $r->overage,
                // The clinic sees what it is charged, never the provider's cost.
                'charge' => $r->charge_paise,
                'status' => $r->status,
                'occurred_at' => $r->occurred_at?->toIso8601String(),
            ]),
        ], null, [
            'current_page' => $records->currentPage(),
            'last_page' => $records->lastPage(),
            'per_page' => $records->perPage(),
            'total' => $records->total(),
        ]);
    }

    public function invoices(Request $request): JsonResponse
    {
        $organization = $this->authorize($request);

        return $this->ok(SubscriptionInvoice::query()
            ->where('organization_id', $organization->id)
            ->where('status', '!=', SubscriptionInvoice::DRAFT)
            ->with('payments')
            ->latest('id')->limit(100)->get()
            ->map(fn (SubscriptionInvoice $i) => $this->invoice($i, detailed: true)));
    }

    /** Renew — the same plan, or another one for the next term. Returns the invoice and its checkout. */
    public function renew(Request $request, InvoiceService $invoices, PaymentService $payments): JsonResponse
    {
        $organization = $this->authorize($request);
        $data = $request->validate([
            'plan_id' => ['nullable', 'integer', Rule::exists(Plan::class, 'id')],
            'billing_cycle' => ['nullable', Rule::in([Subscription::MONTHLY, Subscription::ANNUAL])],
        ]);

        $subscription = $this->current($organization);
        $plan = isset($data['plan_id']) ? Plan::query()->findOrFail($data['plan_id']) : null;

        if ($plan !== null && ! $plan->isPurchasable()) {
            throw ValidationException::withMessages(['plan_id' => 'That plan is not on sale.']);
        }

        $invoice = $invoices->renewalInvoice($subscription, $plan, $data['billing_cycle'] ?? null);

        return $this->ok($this->checkoutFor($invoice, $payments), 'Renewal invoice ready.');
    }

    /** Upgrade now: an invoice for the difference over the rest of the term. */
    public function upgrade(Request $request, InvoiceService $invoices, PaymentService $payments, PricingCatalog $catalog): JsonResponse
    {
        $organization = $this->authorize($request);
        $data = $request->validate([
            'plan_id' => ['required', 'integer', Rule::exists(Plan::class, 'id')],
            'billing_cycle' => ['required', Rule::in([Subscription::MONTHLY, Subscription::ANNUAL])],
        ]);

        $subscription = $this->current($organization);
        $plan = Plan::query()->findOrFail($data['plan_id']);

        if (! $plan->isPurchasable()) {
            throw ValidationException::withMessages(['plan_id' => 'That plan is not on sale.']);
        }

        // A clinic on Complimentary or a lapsed term buys a full term instead.
        if ($subscription->isComplimentary() || $this->entitlements->isLocked($organization) || $subscription->expires_at?->isPast()) {
            return $this->renew($request->merge($data), $invoices, $payments);
        }

        if ($plan->priceFor($data['billing_cycle']) <= $subscription->snapshotPrice()) {
            throw ValidationException::withMessages(['plan_id' => 'Moving to a smaller plan happens at renewal. Choose it when you renew, or ask us to schedule it.']);
        }

        $quote = $catalog->quoteUpgrade($subscription, $plan, $data['billing_cycle']);

        $invoice = $invoices->issue($organization, $subscription, SubscriptionInvoice::KIND_UPGRADE, $quote, [
            'action' => 'change_plan',
            'plan_id' => $plan->id,
            'cycle' => $data['billing_cycle'],
        ]);

        return $this->ok($this->checkoutFor($invoice, $payments), 'Upgrade invoice ready.');
    }

    /** What these add-ons would cost now (prorated to the term). */
    public function quoteAddons(Request $request, PricingCatalog $catalog): JsonResponse
    {
        $organization = $this->authorize($request);
        $data = $this->addonInput($request);

        return $this->ok($catalog->quoteAddons($this->current($organization), $data['addons'], $data['modules'])->toArray());
    }

    public function buyAddons(Request $request, PricingCatalog $catalog, InvoiceService $invoices, PaymentService $payments): JsonResponse
    {
        $organization = $this->authorize($request);
        $data = $this->addonInput($request);
        $subscription = $this->current($organization);

        $quote = $catalog->quoteAddons($subscription, $data['addons'], $data['modules']);

        $lines = collect($quote->lines);

        $invoice = $invoices->issue($organization, $subscription, SubscriptionInvoice::KIND_ADDON, $quote, [
            'action' => 'addons',
            'modules' => $lines->where('type', 'module')->map(fn ($l) => ['ref' => $l['ref'], 'unit_price' => $l['unit_price']])->values()->all(),
            'addons' => $lines->where('type', 'addon')->map(fn ($l) => ['ref' => $l['ref'], 'quantity' => $l['quantity'], 'unit_price' => $l['unit_price']])->values()->all(),
        ]);

        return $this->ok($this->checkoutFor($invoice, $payments), 'Add-on invoice ready.');
    }

    /** Pay an invoice that is already open — e.g. the renewal invoice the scheduler issued. */
    public function pay(Request $request, string $invoice, PaymentService $payments): JsonResponse
    {
        $organization = $this->authorize($request);

        $model = SubscriptionInvoice::query()
            ->where('uuid', $invoice)
            ->where('organization_id', $organization->id)
            ->firstOrFail();

        return $this->ok($this->checkoutFor($model, $payments));
    }

    /**
     * The browser's report that checkout succeeded — checked against
     * Razorpay before anything changes.
     */
    public function verifyPayment(Request $request, PaymentService $payments): JsonResponse
    {
        $organization = $this->authorize($request);
        $data = $request->validate([
            'razorpay_order_id' => ['required', 'string', 'max:80'],
            'razorpay_payment_id' => ['required', 'string', 'max:80'],
            'razorpay_signature' => ['required', 'string', 'max:200'],
        ]);

        $invoice = $payments->verifyCheckout(
            $data['razorpay_order_id'],
            $data['razorpay_payment_id'],
            $data['razorpay_signature'],
            $organization->id,
        );

        app()->forgetScopedInstances();

        return $this->ok([
            'invoice' => $this->invoice($invoice->refresh()),
            'status' => app(EntitlementService::class)->status($organization),
        ], 'Payment received. Thank you!');
    }

    /** @return array{addons: array<string, int>, modules: list<string>} */
    private function addonInput(Request $request): array
    {
        $data = $request->validate([
            'addons' => ['array'],
            'addons.*' => ['integer', 'min:0', 'max:1000'],
            'modules' => ['array'],
            'modules.*' => ['string', 'max:60'],
        ]);

        $addons = array_filter((array) ($data['addons'] ?? []), fn ($q) => (int) $q > 0);
        $modules = array_values((array) ($data['modules'] ?? []));

        if ($addons === [] && $modules === []) {
            throw ValidationException::withMessages(['addons' => 'Choose at least one add-on.']);
        }

        return ['addons' => $addons, 'modules' => $modules];
    }

    /** @return array<string, mixed> */
    private function checkoutFor(SubscriptionInvoice $invoice, PaymentService $payments): array
    {
        $online = app(RazorpayGateway::class)->configured();

        return [
            'invoice' => $this->invoice($invoice),
            'checkout' => $online && $invoice->isPayable() ? $payments->checkout($invoice) : null,
            'offline' => $online ? null : 'Online payment is not set up yet. Pay by bank transfer quoting the invoice number; your subscription is renewed as soon as we record it.',
        ];
    }

    /** @return array<string, mixed> */
    private function invoice(SubscriptionInvoice $invoice, bool $detailed = false): array
    {
        return [
            'uuid' => $invoice->uuid,
            'number' => $invoice->number,
            'kind' => $invoice->kind,
            'status' => $invoice->status,
            'currency' => $invoice->currency,
            'subtotal' => $invoice->subtotal,
            'tax_mode' => $invoice->tax_mode,
            'tax_rate_bp' => $invoice->tax_rate_bp,
            'tax' => $invoice->tax,
            'total' => $invoice->total,
            'lines' => $invoice->lines,
            'period_start' => $invoice->period_start?->toIso8601String(),
            'period_end' => $invoice->period_end?->toIso8601String(),
            'issued_at' => $invoice->issued_at?->toIso8601String(),
            'due_at' => $invoice->due_at?->toIso8601String(),
            'paid_at' => $invoice->paid_at?->toIso8601String(),
            'payments' => $detailed ? $invoice->payments->map(fn ($p) => [
                'method' => $p->method,
                'status' => $p->status,
                'amount' => $p->amount,
                'reference' => $p->method === 'manual' ? $p->reference : $p->gateway_payment_id,
                'verified_at' => $p->verified_at?->toIso8601String(),
            ]) : null,
        ];
    }

    /** Owner, or a holder of `settings.subscription`. */
    private function authorize(Request $request): Organization
    {
        $organization = $request->attributes->get('tenant.organization');
        $user = $request->user();

        abort_unless($organization instanceof Organization && $user instanceof User, 403);

        if (! app(Permission::class)->allows($organization, $user, 'settings.subscription')) {
            abort(403, 'Only your organization\'s owner or billing contact can manage the subscription.');
        }

        return $organization;
    }

    private function current(Organization $organization): Subscription
    {
        $subscription = $this->facts->subscription($organization);

        abort_if($subscription === null, 409, 'Your organization has no subscription yet. Contact us to set one up.');

        return $subscription;
    }
}
