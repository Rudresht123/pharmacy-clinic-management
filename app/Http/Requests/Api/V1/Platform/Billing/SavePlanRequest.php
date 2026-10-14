<?php

namespace App\Http\Requests\Api\V1\Platform\Billing;

use App\Models\Platform\Module;
use App\Models\Platform\Plan;
use App\Support\Subscriptions\Entitlements;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A plan, created or edited by Super Admin.
 *
 * Amounts are PAISE, as integers — ₹1,499 is 149900. The screen converts; the
 * API never parses a decimal, so no rounding ever happens to a price.
 *
 * `limits` is every limit and monthly quota key, each a whole number or null
 * for unlimited. `modules` is the bindable module keys the plan includes.
 */
class SavePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var Plan|null $plan */
        $plan = $this->route('plan');

        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => [
                $plan ? 'sometimes' : 'required',
                'string',
                'max:60',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique(Plan::class, 'slug')->ignore($plan?->id),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_public' => ['boolean'],
            'sort_order' => ['integer', 'min:0', 'max:999'],
            'currency' => ['string', 'size:3'],
            'monthly_price' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'annual_price' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'trial_days' => ['required', 'integer', 'min:0', 'max:365'],
            'grace_days' => ['required', 'integer', 'min:0', 'max:90'],
            'features' => ['array'],
            'features.*' => [Rule::in(Entitlements::FEATURES)],
            'highlights' => ['array', 'max:12'],
            'highlights.*' => ['string', 'max:160'],
            'limits' => ['required', 'array'],
            'limits.*' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'modules' => ['array'],
            'modules.*' => [
                'string',
                Rule::exists(Module::class, 'key')->where(fn ($q) => $q->where('is_core', false)->where('is_active', true)),
            ],
        ];
    }

    public function after(): array
    {
        return [
            function ($validator) {
                $unknown = array_diff(array_keys((array) $this->input('limits', [])), Entitlements::limitKeys());

                if ($unknown !== []) {
                    $validator->errors()->add('limits', 'Unknown limit: '.implode(', ', $unknown).'.');
                }

                $missing = array_diff(Entitlements::limitKeys(), array_keys((array) $this->input('limits', [])));

                if ($missing !== []) {
                    $validator->errors()->add('limits', 'Set every limit (blank for unlimited): '.implode(', ', $missing).'.');
                }

                /** @var Plan|null $plan */
                $plan = $this->route('plan');

                if ($plan !== null && $this->filled('slug') && $this->input('slug') !== $plan->slug && $plan->status !== Plan::DRAFT) {
                    $validator->errors()->add('slug', 'The address of a plan that has been on sale cannot change: the website links to it.');
                }
            },
        ];
    }
}
