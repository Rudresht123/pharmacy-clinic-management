<?php

namespace App\Http\Controllers\Api\V1\Platform\Billing;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Platform\UsageRate;
use App\Services\Subscriptions\PricingCatalog;
use App\Services\Subscriptions\UsageMeter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Communication and video rates: what a unit costs the platform (the
 * provider's charge, entered as configured with that provider — never
 * invented here) and what it is sold for, past each plan's included quota.
 *
 * Versioned: a published rate is never edited. Saving a new one for the same
 * channel, provider, category and destination closes the old one from the
 * new one's start, and every usage record keeps the rate it was charged at.
 *
 * Amounts are ten-thousandths of a rupee (`_e4`): ₹0.1150 is 1150.
 *
 * @group Platform > Billing
 */
class UsageRateController extends BaseApiController
{
    public function __construct(
        private readonly PricingCatalog $catalog,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $rates = UsageRate::query()
            ->when($request->query('channel'), fn ($q, $channel) => $q->where('channel', $channel))
            ->orderBy('channel')->orderBy('provider')->orderBy('category')->orderByDesc('effective_from')
            ->get();

        return $this->ok([
            'rates' => $rates->map(fn (UsageRate $rate) => $this->rate($rate)),

            // Which channels actually bill today. SMS shows its rates but
            // stays dormant until an SMS provider is integrated.
            'channels' => collect(UsageRate::CHANNELS)->mapWithKeys(fn (string $channel) => [$channel => [
                'metered' => UsageMeter::isMetered($channel),
                'note' => UsageMeter::isMetered($channel) ? null : 'Inactive: no SMS provider is integrated, so nothing is metered or billed.',
            ]]),
        ]);
    }

    /** A new rate version — draft, or published straight away. */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $from = isset($data['effective_from']) ? Carbon::parse($data['effective_from']) : now();

        $rate = DB::transaction(function () use ($data, $from) {
            if (($data['status'] ?? UsageRate::DRAFT) === UsageRate::PUBLISHED) {
                $this->closeSiblings($data, $from);
            }

            return UsageRate::query()->create([...$data, 'effective_from' => $from]);
        });

        $this->catalog->bumpVersion();

        return $this->created($this->rate($rate), 'Rate saved.');
    }

    /** Only a draft can be edited; a published rate is replaced, not changed. */
    public function update(Request $request, UsageRate $rate): JsonResponse
    {
        if ($rate->status !== UsageRate::DRAFT) {
            throw ValidationException::withMessages(['rate' => 'A published rate cannot be edited. Save a new rate instead — the old one is closed from the new one\'s start.']);
        }

        $rate->update($this->validated($request));
        $this->catalog->bumpVersion();

        return $this->ok($this->rate($rate), 'Rate saved.');
    }

    public function publish(UsageRate $rate): JsonResponse
    {
        DB::transaction(function () use ($rate) {
            $this->closeSiblings($rate->only(['channel', 'provider', 'category', 'destination']), $rate->effective_from, $rate->id);
            $rate->update(['status' => UsageRate::PUBLISHED]);
        });

        $this->catalog->bumpVersion();

        return $this->ok($this->rate($rate), 'Rate published.');
    }

    public function archive(UsageRate $rate): JsonResponse
    {
        $rate->update(['status' => UsageRate::ARCHIVED, 'effective_to' => $rate->effective_to ?? now()]);
        $this->catalog->bumpVersion();

        return $this->ok($this->rate($rate), 'Rate archived.');
    }

    /** @param  array<string, mixed>  $dimensions */
    private function closeSiblings(array $dimensions, Carbon $from, ?int $except = null): void
    {
        UsageRate::query()
            ->where('channel', $dimensions['channel'])
            ->where('provider', $dimensions['provider'] ?? '*')
            ->where('destination', strtoupper((string) ($dimensions['destination'] ?? '*')))
            ->when($dimensions['category'] ?? null, fn ($q, $c) => $q->where('category', $c), fn ($q) => $q->whereNull('category'))
            ->where('status', UsageRate::PUBLISHED)
            ->when($except, fn ($q) => $q->where('id', '!=', $except))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $from))
            ->get()
            ->each(fn (UsageRate $old) => $old->update(['effective_to' => $from]));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'channel' => ['required', Rule::in(UsageRate::CHANNELS)],
            'provider' => ['nullable', 'string', 'max:40'],
            'category' => ['nullable', Rule::in(['marketing', 'utility', 'authentication', 'service', 'transactional', 'otp', 'promotional'])],
            'destination' => ['nullable', 'string', 'max:8'],
            'billing_unit' => ['required', Rule::in(UsageRate::UNITS)],
            'currency' => ['string', 'size:3'],
            'provider_cost_e4' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'price_e4' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'overage_policy' => ['required', Rule::in([UsageRate::BLOCK, UsageRate::ALLOW])],
            'charge_failed' => ['boolean'],
            'tiers' => ['nullable', 'array', 'max:20'],
            'tiers.*.from' => ['required_with:tiers', 'integer', 'min:0'],
            'tiers.*.price_e4' => ['required_with:tiers', 'integer', 'min:0'],
            'status' => [Rule::in([UsageRate::DRAFT, UsageRate::PUBLISHED])],
            'notes' => ['nullable', 'string', 'max:1000'],
            'effective_from' => ['nullable', 'date'],
        ]);

        $data['provider'] = $data['provider'] ?? '*';
        $data['destination'] = strtoupper((string) ($data['destination'] ?? '*'));

        return $data;
    }

    /** @return array<string, mixed> */
    private function rate(UsageRate $rate): array
    {
        return [
            'id' => $rate->id,
            'channel' => $rate->channel,
            'provider' => $rate->provider,
            'category' => $rate->category,
            'destination' => $rate->destination,
            'billing_unit' => $rate->billing_unit,
            'currency' => $rate->currency,
            'provider_cost_e4' => $rate->provider_cost_e4,
            'price_e4' => $rate->price_e4,
            'margin_e4' => $rate->price_e4 - $rate->provider_cost_e4,
            'overage_policy' => $rate->overage_policy,
            'charge_failed' => $rate->charge_failed,
            'tiers' => $rate->tiers ?? [],
            'status' => $rate->status,
            'notes' => $rate->notes,
            'effective_from' => $rate->effective_from?->toIso8601String(),
            'effective_to' => $rate->effective_to?->toIso8601String(),
        ];
    }
}
