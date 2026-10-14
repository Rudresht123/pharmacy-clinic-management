<?php

namespace App\Http\Controllers\Api\V1\Platform\Billing;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Platform\Addon;
use App\Models\Platform\Plan;
use App\Services\Subscriptions\PricingCatalog;
use App\Support\Subscriptions\Entitlements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Add-ons: extra branches, doctors or employees, credit packs, and features.
 *
 * What an add-on targets is constrained by its kind — capacity targets a
 * counted resource, credits a monthly quota, a feature a feature key — so an
 * add-on can never grant something nothing enforces.
 *
 * @group Platform > Billing
 */
class AddonController extends BaseApiController
{
    public function __construct(
        private readonly PricingCatalog $catalog,
    ) {}

    public function index(): JsonResponse
    {
        return $this->ok(Addon::query()->orderBy('sort_order')->orderBy('id')->get()->map(fn (Addon $a) => $this->addon($a)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $addon = Addon::query()->create([...$data, 'status' => Plan::DRAFT]);
        $this->catalog->bumpVersion();

        return $this->created($this->addon($addon), 'Add-on created as a draft.');
    }

    public function update(Request $request, Addon $addon): JsonResponse
    {
        $data = $this->validated($request, $addon);

        $addon->update($data);
        $this->catalog->bumpVersion();

        return $this->ok($this->addon($addon), 'Add-on saved. Running subscriptions keep the price they bought it at until renewal.');
    }

    public function status(Request $request, Addon $addon): JsonResponse
    {
        $status = $request->validate(['status' => ['required', Rule::in(Plan::STATUSES)]])['status'];

        $addon->update(['status' => $status]);
        $this->catalog->bumpVersion();

        return $this->ok($this->addon($addon), "Add-on {$status}.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Addon $addon = null): array
    {
        $data = $request->validate([
            'key' => [
                $addon ? 'sometimes' : 'required',
                'string', 'max:60', 'regex:/^[a-z0-9_]+$/',
                Rule::unique(Addon::class, 'key')->ignore($addon?->id),
            ],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'kind' => ['required', Rule::in(Addon::KINDS)],
            'target' => ['required', 'string'],
            'unit_quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'billing' => ['required', Rule::in([Addon::RECURRING, Addon::ONE_TIME])],
            'currency' => ['string', 'size:3'],
            'monthly_price' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'annual_price' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'provider_cost' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'is_public' => ['boolean'],
            'sort_order' => ['integer', 'min:0', 'max:999'],
        ]);

        $valid = match ($data['kind']) {
            Addon::RESOURCE => Entitlements::isResource($data['target']),
            Addon::CREDITS => Entitlements::isQuota($data['target']),
            Addon::FEATURE => Entitlements::isFeature($data['target']),
            default => false,
        };

        if (! $valid) {
            throw ValidationException::withMessages(['target' => 'That target does not fit this kind of add-on.']);
        }

        if ($addon !== null && isset($data['key']) && $data['key'] !== $addon->key && $addon->status !== Plan::DRAFT) {
            throw ValidationException::withMessages(['key' => 'The key of an add-on that has been on sale cannot change.']);
        }

        if ($data['kind'] === Addon::FEATURE) {
            $data['unit_quantity'] = 1;
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function addon(Addon $addon): array
    {
        return [
            'id' => $addon->id,
            'key' => $addon->key,
            'name' => $addon->name,
            'description' => $addon->description,
            'kind' => $addon->kind,
            'target' => $addon->target,
            'target_label' => Entitlements::label($addon->target),
            'unit_quantity' => $addon->unit_quantity,
            'billing' => $addon->billing,
            'currency' => $addon->currency,
            'monthly_price' => $addon->monthly_price,
            'annual_price' => $addon->annual_price,
            'provider_cost' => $addon->provider_cost,
            'status' => $addon->status,
            'is_public' => $addon->is_public,
            'sort_order' => $addon->sort_order,
        ];
    }
}
