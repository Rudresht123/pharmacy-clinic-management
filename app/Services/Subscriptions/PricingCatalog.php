<?php

namespace App\Services\Subscriptions;

use App\Models\Platform\Addon;
use App\Models\Platform\Module;
use App\Models\Platform\ModulePrice;
use App\Models\Platform\Plan;
use App\Models\Platform\Subscription;
use App\Models\Platform\UsageRate;
use App\Support\Subscriptions\Entitlements;
use App\Support\Subscriptions\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * The price list, and the one place a price is ever worked out.
 *
 * Every amount anybody is shown or charged — the website's pricing page, a
 * renewal invoice, an upgrade, an add-on — is computed here from the master
 * database. Nothing a client sends is taken as a price: a request names a
 * plan, a cycle and add-on keys, and this answers what that costs.
 *
 * Reads the master database only. The website's pricing endpoints call
 * nothing else, so they never need — and never touch — a tenant database.
 */
class PricingCatalog
{
    /** Bumped on every price change; the website's cached answers key on it. */
    private const VERSION_KEY = 'pricing:version';

    public function __construct(
        private readonly BillingSettings $settings,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | The catalogue
    |--------------------------------------------------------------------------
    */

    /** @return Collection<int, Plan> */
    public function websitePlans(): Collection
    {
        return Plan::query()->onWebsite()->with(['limits', 'modules'])->get();
    }

    public function websitePlan(string $slug): ?Plan
    {
        return Plan::query()->onWebsite()->with(['limits', 'modules'])->where('slug', $slug)->first();
    }

    /** @return Collection<int, Addon> */
    public function websiteAddons(): Collection
    {
        return Addon::query()->onWebsite()->get();
    }

    /**
     * Each bindable module with its current add-on price, if it has one.
     *
     * @return Collection<int, array{module: Module, price: ModulePrice|null}>
     */
    public function modulesWithPrices(bool $publicOnly = true): Collection
    {
        $prices = ModulePrice::query()->current()->get()->keyBy('module_id');

        return Module::query()->available()->bindable()->orderBy('sort_order')->get()
            ->map(fn (Module $module) => ['module' => $module, 'price' => $prices->get($module->id)])
            ->filter(fn (array $row) => ! $publicOnly || ($row['price']?->is_public ?? false))
            ->values();
    }

    public function currentModulePrice(Module|int $module): ?ModulePrice
    {
        $id = $module instanceof Module ? $module->getKey() : $module;

        return ModulePrice::query()->current()->where('module_id', $id)->first();
    }

    /**
     * The rate a unit of usage is charged at: the most specific published
     * rate in force — an exact provider beats `*`, an exact category beats
     * any, an exact destination beats `*`.
     */
    public function rateFor(string $channel, ?string $provider = null, ?string $category = null, ?string $destination = null): ?UsageRate
    {
        return UsageRate::query()
            ->inForce()
            ->where('channel', $channel)
            ->whereIn('provider', array_filter(['*', $provider]))
            ->where(fn ($q) => $q->whereNull('category')->when($category, fn ($q) => $q->orWhere('category', $category)))
            ->whereIn('destination', array_filter(['*', $destination ? strtoupper($destination) : null]))
            ->get()
            ->sortByDesc(fn (UsageRate $rate) => ($rate->provider !== '*' ? 4 : 0)
                + ($rate->category !== null ? 2 : 0)
                + ($rate->destination !== '*' ? 1 : 0))
            ->first();
    }

    /*
    |--------------------------------------------------------------------------
    | Quotes — the server's answer to "what would this cost?"
    |--------------------------------------------------------------------------
    */

    /**
     * A new subscription: the plan for one cycle, plus add-ons.
     *
     * @param  array<string, int>  $addons  addon key => quantity
     * @param  list<string>  $modules  module keys bought as add-ons
     */
    public function quoteSubscription(Plan $plan, string $cycle, array $addons = [], array $modules = []): Quote
    {
        if (! in_array($cycle, [Subscription::MONTHLY, Subscription::ANNUAL], true)) {
            throw ValidationException::withMessages(['billing_cycle' => 'Choose monthly or annual billing.']);
        }

        $lines = [[
            'type' => 'plan',
            'ref' => $plan->slug,
            'description' => "{$plan->name} plan — ".($cycle === Subscription::ANNUAL ? 'annual' : 'monthly'),
            'quantity' => 1,
            'unit_price' => $plan->priceFor($cycle),
            'amount' => $plan->priceFor($cycle),
        ]];

        $lines = [...$lines, ...$this->moduleLines($plan, $cycle, $modules), ...$this->addonLines($cycle, $addons)];

        return $this->quote($lines, $plan->currency);
    }

    /**
     * Renewing an existing subscription for its next term.
     *
     * The plan's CURRENT published price, the policy the brief asked to be
     * defined: a running term keeps the price it was bought at (its snapshot),
     * and a price change reaches a clinic at its next renewal — on a renewal
     * invoice issued days ahead, so they see it before they pay it. A plan
     * that is no longer on sale renews at the price in the snapshot.
     *
     * Recurring add-ons and module add-ons renew with it; one-time packs and
     * overrides do not.
     */
    public function quoteRenewal(Subscription $subscription, ?Plan $plan = null, ?string $cycle = null): Quote
    {
        $plan ??= $subscription->pendingPlan ?? $subscription->plan;
        $cycle ??= $subscription->pending_billing_cycle ?? $subscription->billing_cycle;

        if ($cycle === Subscription::COMPLIMENTARY) {
            throw ValidationException::withMessages(['plan' => 'A complimentary subscription has nothing to renew. Assign a plan first.']);
        }

        $price = $plan->isPurchasable() ? $plan->priceFor($cycle) : $subscription->snapshotPrice();

        $lines = [[
            'type' => 'plan',
            'ref' => $plan->slug,
            'description' => "{$plan->name} plan — ".($cycle === Subscription::ANNUAL ? 'annual' : 'monthly').' renewal',
            'quantity' => 1,
            'unit_price' => $price,
            'amount' => $price,
        ]];

        foreach ($subscription->items()->get() as $item) {
            if (! $item->isLive() || $item->billing !== Addon::RECURRING || $item->type === 'override') {
                continue;
            }

            $unit = $item->type === 'module'
                ? ($this->modulePriceByKey($item->ref)?->priceFor($cycle) ?? $item->unit_price)
                : (Addon::query()->where('key', $item->ref)->first()?->priceFor($cycle) ?? $item->unit_price);

            $lines[] = [
                'type' => $item->type,
                'ref' => $item->ref,
                'description' => $this->itemDescription($item->type, $item->ref),
                'quantity' => $item->quantity,
                'unit_price' => $unit,
                'amount' => $unit * $item->quantity,
            ];
        }

        return $this->quote($lines, $plan->currency);
    }

    /**
     * Buying add-ons mid-term: prorated to the days left in the term for
     * recurring ones, full price for one-time packs.
     *
     * @param  array<string, int>  $addons
     * @param  list<string>  $modules
     */
    public function quoteAddons(Subscription $subscription, array $addons, array $modules = []): Quote
    {
        $cycle = $subscription->billing_cycle === Subscription::COMPLIMENTARY ? Subscription::MONTHLY : $subscription->billing_cycle;
        $fraction = $this->remainingFraction($subscription);

        $lines = [];

        foreach ([...$this->moduleLines($subscription->plan, $cycle, $modules), ...$this->addonLines($cycle, $addons)] as $line) {
            if (($line['billing'] ?? Addon::RECURRING) === Addon::RECURRING && $fraction !== null) {
                $line['unit_price'] = intdiv($line['unit_price'] * $fraction[0] + intdiv($fraction[1], 2), $fraction[1]);
                $line['amount'] = $line['unit_price'] * $line['quantity'];
                $line['description'] .= " (prorated, {$fraction[0]} of {$fraction[1]} days)";
            }

            $lines[] = $line;
        }

        return $this->quote($lines, $subscription->plan->currency);
    }

    /**
     * Upgrading mid-term: the difference between the two plans' prices for
     * the days left. Never negative — a downgrade waits for the renewal.
     */
    public function quoteUpgrade(Subscription $subscription, Plan $plan, string $cycle): Quote
    {
        $fraction = $this->remainingFraction($subscription) ?? [1, 1];
        $difference = max(0, $plan->priceFor($cycle) - $subscription->snapshotPrice());
        $amount = intdiv($difference * $fraction[0] + intdiv($fraction[1], 2), $fraction[1]);

        return $this->quote([[
            'type' => 'upgrade',
            'ref' => $plan->slug,
            'description' => "Upgrade to {$plan->name} for the rest of this term ({$fraction[0]} of {$fraction[1]} days)",
            'quantity' => 1,
            'unit_price' => $amount,
            'amount' => $amount,
        ]], $plan->currency);
    }

    /** @param  list<array<string, mixed>>  $lines */
    public function quote(array $lines, string $currency = 'INR'): Quote
    {
        $subtotal = array_sum(array_column($lines, 'amount'));
        $mode = $this->settings->taxMode();
        $rate = $this->settings->taxRateBp();
        $tax = Money::tax($subtotal, $mode, $rate);

        return new Quote(
            lines: array_map(fn (array $line) => array_diff_key($line, ['billing' => true]), $lines),
            currency: $currency,
            subtotal: $subtotal,
            taxMode: $mode,
            taxRateBp: $rate,
            taxLabel: (string) $this->settings->get('tax_label'),
            tax: $tax['tax'],
            total: $tax['total'],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | The website's cache
    |--------------------------------------------------------------------------
    */

    public function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 1);
    }

    /** Called after any price, plan or add-on change. */
    public function bumpVersion(): void
    {
        Cache::forever(self::VERSION_KEY, $this->version() + 1);
    }

    /*
    |--------------------------------------------------------------------------
    | Lines
    |--------------------------------------------------------------------------
    */

    /** @return list<array<string, mixed>> */
    private function moduleLines(?Plan $plan, string $cycle, array $modules): array
    {
        $included = $plan?->moduleKeys() ?? [];
        $lines = [];

        foreach (array_unique($modules) as $key) {
            if (in_array($key, $included, true)) {
                continue;
            }

            $module = Module::query()->available()->bindable()->where('key', $key)->first();
            $price = $module ? $this->currentModulePrice($module) : null;

            if ($module === null || $price === null || ! $price->is_addon_eligible) {
                throw ValidationException::withMessages(['modules' => "The {$key} module cannot be bought as an add-on."]);
            }

            $lines[] = [
                'type' => 'module',
                'ref' => $module->key,
                'description' => "{$module->name} module",
                'quantity' => 1,
                'unit_price' => $price->priceFor($cycle),
                'amount' => $price->priceFor($cycle),
                'billing' => Addon::RECURRING,
            ];
        }

        return $lines;
    }

    /**
     * @param  array<string, int>  $addons
     * @return list<array<string, mixed>>
     */
    private function addonLines(string $cycle, array $addons): array
    {
        $lines = [];

        foreach ($addons as $key => $quantity) {
            $quantity = (int) $quantity;

            if ($quantity < 1) {
                continue;
            }

            $addon = Addon::query()->purchasable()->where('key', $key)->first();

            if ($addon === null) {
                throw ValidationException::withMessages(['addons' => "The {$key} add-on is not available."]);
            }

            if ($quantity > 1000) {
                throw ValidationException::withMessages(['addons' => 'That is more than can be bought at once.']);
            }

            $unit = $addon->priceFor($cycle);
            $units = Entitlements::UNITS[$addon->target] ?? 'units';

            $lines[] = [
                'type' => 'addon',
                'ref' => $addon->key,
                'description' => $addon->name.($addon->unit_quantity > 1 ? " ({$addon->unit_quantity} {$units})" : ''),
                'quantity' => $quantity,
                'unit_price' => $unit,
                'amount' => $unit * $quantity,
                'billing' => $addon->billing,
            ];
        }

        return $lines;
    }

    private function modulePriceByKey(string $key): ?ModulePrice
    {
        $module = Module::query()->where('key', $key)->first();

        return $module ? $this->currentModulePrice($module) : null;
    }

    private function itemDescription(string $type, string $ref): string
    {
        return $type === 'module'
            ? (Module::query()->where('key', $ref)->value('name') ?? $ref).' module'
            : (Addon::query()->where('key', $ref)->value('name') ?? $ref);
    }

    /**
     * Days left in the term over its length, for proration. Null when the
     * term has no end (complimentary).
     *
     * @return array{0: int, 1: int}|null
     */
    private function remainingFraction(Subscription $subscription): ?array
    {
        $start = $subscription->current_period_start;
        $end = $subscription->expires_at;

        if ($start === null || $end === null || $end->isPast()) {
            return null;
        }

        $total = max(1, (int) ceil($start->diffInDays($end)));
        $left = max(1, min($total, (int) ceil(now()->diffInDays($end))));

        return [$left, $total];
    }
}
