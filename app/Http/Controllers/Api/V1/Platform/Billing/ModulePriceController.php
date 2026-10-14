<?php

namespace App\Http\Controllers\Api\V1\Platform\Billing;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Platform\Module;
use App\Models\Platform\ModulePrice;
use App\Models\Platform\Plan;
use App\Services\Subscriptions\PricingCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What each module costs as an add-on — and its price history.
 *
 * A price is never edited once set. Saving a new one closes the current row
 * at the moment the new one takes effect, so every price a module has ever
 * had is still readable, with who set it (the audit log) and when.
 *
 * @group Platform > Billing
 */
class ModulePriceController extends BaseApiController
{
    public function __construct(
        private readonly PricingCatalog $catalog,
    ) {}

    /** Every bindable module, its current price, its history, and the plans that include it. */
    public function index(): JsonResponse
    {
        $prices = ModulePrice::query()->orderByDesc('effective_from')->get()->groupBy('module_id');
        $plans = Plan::query()->with('modules:id')->where('status', '!=', Plan::ARCHIVED)->get();

        $rows = Module::query()->bindable()->orderBy('sort_order')->get()->map(function (Module $module) use ($prices, $plans) {
            $history = $prices->get($module->id, collect());
            $current = $history->first(fn (ModulePrice $p) => $p->effective_from <= now()
                && ($p->effective_to === null || $p->effective_to > now()));

            return [
                'module' => [
                    'id' => $module->id,
                    'key' => $module->key,
                    'name' => $module->name,
                    'description' => $module->description,
                    'is_active' => $module->is_active,
                ],
                'current' => $current ? $this->price($current) : null,
                'scheduled' => $history->filter(fn (ModulePrice $p) => $p->effective_from > now())->map(fn ($p) => $this->price($p))->values(),
                'history' => $history->map(fn ($p) => $this->price($p))->values(),
                'included_in' => $plans->filter(fn (Plan $plan) => $plan->modules->contains('id', $module->id))
                    ->map(fn (Plan $plan) => ['id' => $plan->id, 'name' => $plan->name])->values(),
            ];
        });

        return $this->ok($rows);
    }

    /**
     * Set a module's price from a date (default now). The price in force at
     * that moment is closed there; anything already scheduled after it is
     * replaced.
     */
    public function store(Request $request, Module $module): JsonResponse
    {
        $data = $request->validate([
            'monthly_price' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'annual_price' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'provider_cost' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'is_addon_eligible' => ['boolean'],
            'trial_available' => ['boolean'],
            'is_public' => ['boolean'],
            'effective_from' => ['nullable', 'date'],
        ]);

        abort_if($module->is_core, 422, 'Core modules come with every organization and have no price of their own.');

        $from = isset($data['effective_from']) ? Carbon::parse($data['effective_from']) : now();

        $price = DB::transaction(function () use ($module, $data, $from) {
            // Anything scheduled at or after the new date is superseded.
            ModulePrice::query()->where('module_id', $module->id)->where('effective_from', '>=', $from)->get()
                ->each(fn (ModulePrice $p) => $p->delete());

            ModulePrice::query()->where('module_id', $module->id)
                ->where('effective_from', '<', $from)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $from))
                ->get()
                ->each(fn (ModulePrice $p) => $p->update(['effective_to' => $from]));

            return ModulePrice::query()->create([
                'module_id' => $module->id,
                'monthly_price' => $data['monthly_price'],
                'annual_price' => $data['annual_price'],
                'provider_cost' => $data['provider_cost'] ?? null,
                'is_addon_eligible' => $data['is_addon_eligible'] ?? true,
                'trial_available' => $data['trial_available'] ?? false,
                'is_public' => $data['is_public'] ?? true,
                'effective_from' => $from,
            ]);
        });

        $this->catalog->bumpVersion();

        return $this->created($this->price($price), $from->isFuture()
            ? 'New price scheduled from '.$from->toDayDateTimeString().'.'
            : 'Price updated. New add-on purchases and renewals use it.');
    }

    /** @return array<string, mixed> */
    private function price(ModulePrice $price): array
    {
        return [
            'id' => $price->id,
            'monthly_price' => $price->monthly_price,
            'annual_price' => $price->annual_price,
            'provider_cost' => $price->provider_cost,
            'is_addon_eligible' => $price->is_addon_eligible,
            'trial_available' => $price->trial_available,
            'is_public' => $price->is_public,
            'effective_from' => $price->effective_from?->toIso8601String(),
            'effective_to' => $price->effective_to?->toIso8601String(),
        ];
    }
}
