<?php

namespace App\Http\Controllers\Api\V1\Website;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Platform\Addon;
use App\Models\Platform\Plan;
use App\Models\Platform\Subscription;
use App\Services\Subscriptions\BillingSettings;
use App\Services\Subscriptions\PricingCatalog;
use App\Support\Modules\ModuleRegistry;
use App\Support\Subscriptions\Entitlements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * The price list, for the marketing website.
 *
 * PUBLIC, and reads the MASTER database only — nothing here resolves a
 * tenant or opens a tenant database. What it shows is exactly what Super
 * Admin has published and marked public: never a draft, never a private plan,
 * never a provider cost or a margin.
 *
 * Cached under the pricing version, which every price, plan or add-on change
 * bumps — so the website is fast and never stale.
 *
 * Amounts are paise (integers): ₹1,499 is 149900.
 *
 * @group Website › Pricing
 *
 * @unauthenticated
 */
class PricingController extends BaseApiController
{
    public function __construct(
        private readonly PricingCatalog $catalog,
        private readonly BillingSettings $settings,
    ) {}

    /** Every plan on the website. */
    public function plans(): JsonResponse
    {
        return $this->ok($this->cached('plans', fn () => $this->catalog->websitePlans()->map(fn (Plan $p) => $this->planPayload($p))->values()->all()));
    }

    /** One plan, by its slug. */
    public function plan(string $slug): JsonResponse
    {
        $plan = $this->cached("plan:{$slug}", function () use ($slug) {
            $plan = $this->catalog->websitePlan($slug);

            return $plan ? $this->planPayload($plan) : null;
        });

        abort_if($plan === null, 404, 'No such plan.');

        return $this->ok($plan);
    }

    /** Modules, with an add-on price where one is public. */
    public function modules(): JsonResponse
    {
        return $this->ok($this->cached('modules', fn () => $this->catalog->modulesWithPrices(publicOnly: false)
            ->map(fn (array $row) => [
                'key' => $row['module']->key,
                'name' => $row['module']->name,
                'description' => $row['module']->description,
                'group' => $row['module']->group,
                'requires' => ModuleRegistry::requires($row['module']->key),
                'addon_price' => ($row['price']?->is_public && $row['price']?->is_addon_eligible) ? [
                    'monthly' => $row['price']->monthly_price,
                    'annual' => $row['price']->annual_price,
                ] : null,
            ])->values()->all()));
    }

    public function addons(): JsonResponse
    {
        return $this->ok($this->cached('addons', fn () => $this->catalog->websiteAddons()->map(fn (Addon $a) => $this->addonPayload($a))->values()->all()));
    }

    /** Everything a pricing page needs, in one call. */
    public function pricing(): JsonResponse
    {
        return $this->ok($this->cached('pricing', fn () => [
            'currency' => 'INR',
            'tax' => [
                'mode' => $this->settings->taxMode(),
                'rate_bp' => $this->settings->taxRateBp(),
                'label' => $this->settings->get('tax_label'),
            ],
            'plans' => $this->catalog->websitePlans()->map(fn (Plan $p) => $this->planPayload($p))->values()->all(),
            'addons' => $this->catalog->websiteAddons()->map(fn (Addon $a) => $this->addonPayload($a))->values()->all(),
            'features' => collect(Entitlements::FEATURES)->map(fn ($f) => ['key' => $f, 'name' => Entitlements::label($f)])->all(),
            'limits' => collect(Entitlements::limitKeys())->map(fn ($k) => ['key' => $k, 'name' => Entitlements::label($k), 'unit' => Entitlements::UNITS[$k] ?? null])->all(),
            'version' => $this->catalog->version(),
        ]));
    }

    /**
     * What a choice would cost — computed here, so the website never
     * multiplies a price itself.
     */
    public function quote(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['required', 'string', 'max:60'],
            'billing_cycle' => ['required', Rule::in([Subscription::MONTHLY, Subscription::ANNUAL])],
            'addons' => ['array'],
            'addons.*' => ['integer', 'min:0', 'max:1000'],
            'modules' => ['array'],
            'modules.*' => ['string', 'max:60'],
        ]);

        $plan = $this->catalog->websitePlan($data['plan']);
        abort_if($plan === null, 404, 'No such plan.');

        return $this->ok($this->catalog->quoteSubscription($plan, $data['billing_cycle'], (array) ($data['addons'] ?? []), (array) ($data['modules'] ?? []))->toArray());
    }

    /** @return array<string, mixed> */
    private function planPayload(Plan $plan): array
    {
        $limits = $plan->limitMap();

        return [
            'slug' => $plan->slug,
            'name' => $plan->name,
            'description' => $plan->description,
            'currency' => $plan->currency,
            'monthly_price' => $plan->monthly_price,
            'annual_price' => $plan->annual_price,
            'annual_saving' => max(0, $plan->monthly_price * 12 - $plan->annual_price),
            'trial_days' => $plan->trial_days,
            'limits' => array_intersect_key($limits, array_flip(Entitlements::RESOURCES)),
            'quotas' => array_intersect_key($limits, array_flip(Entitlements::QUOTAS)),
            'modules' => $plan->modules->map(fn ($m) => ['key' => $m->key, 'name' => $m->name])->values()->all(),
            'features' => array_values((array) $plan->features),
            'highlights' => array_values((array) $plan->highlights),
        ];
    }

    /** @return array<string, mixed> */
    private function addonPayload(Addon $addon): array
    {
        return [
            'key' => $addon->key,
            'name' => $addon->name,
            'description' => $addon->description,
            'kind' => $addon->kind,
            'target' => $addon->target,
            'unit_quantity' => $addon->unit_quantity,
            'billing' => $addon->billing,
            'currency' => $addon->currency,
            'monthly_price' => $addon->monthly_price,
            'annual_price' => $addon->annual_price,
        ];
    }

    private function cached(string $key, \Closure $build): mixed
    {
        return Cache::remember("website-pricing:{$this->catalog->version()}:{$key}", now()->addHour(), $build);
    }
}
