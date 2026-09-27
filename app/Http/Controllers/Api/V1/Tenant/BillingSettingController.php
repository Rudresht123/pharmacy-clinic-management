<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Tenant\BillingSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * How the organisation bills.
 *
 * Reading is `billing.view`; writing is `billing.manage_settings` — an
 * organisation-scoped capability, so branch roles never hold it. Changing
 * these settings does not rewrite an already-drawn invoice; a bill keeps the
 * trigger it was drawn under, and its own totals.
 */
class BillingSettingController extends BaseApiController
{
    public function show(): JsonResponse
    {
        $settings = BillingSetting::current();

        return $this->ok([
            'default_trigger' => $settings->default_trigger,
            'payment_methods' => $settings->payment_methods,
            'payment_behaviour' => $settings->payment_behaviour,
            'prices_include_tax' => (bool) $settings->prices_include_tax,
            'default_tax_percent' => (float) $settings->default_tax_percent,
            'allow_edit_before_payment' => (bool) $settings->allow_edit_before_payment,
            'invoice_prefix' => $settings->invoice_prefix,
            'currency_code' => $settings->currency_code,
            'currency_symbol' => $settings->currency_symbol,
            'terms' => $settings->terms,
            'footer' => $settings->footer,
            'triggers' => BillingSetting::TRIGGERS,
            'payment_behaviours' => BillingSetting::PAYMENT_BEHAVIOURS,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'default_trigger' => ['required', 'string', Rule::in(BillingSetting::TRIGGERS)],
            'payment_methods' => ['required', 'array', 'min:1'],
            'payment_methods.*' => ['string', 'max:20'],
            'payment_behaviour' => ['required', 'string', Rule::in(BillingSetting::PAYMENT_BEHAVIOURS)],
            'prices_include_tax' => ['required', 'boolean'],
            'default_tax_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'allow_edit_before_payment' => ['required', 'boolean'],
            'invoice_prefix' => ['required', 'string', 'max:12'],
            'currency_code' => ['required', 'string', 'size:3'],
            'currency_symbol' => ['required', 'string', 'max:4'],
            'terms' => ['nullable', 'string', 'max:2000'],
            'footer' => ['nullable', 'string', 'max:500'],
        ]);

        $settings = BillingSetting::current();
        $settings->fill($validated)->save();

        return $this->ok(null, 'Billing settings saved');
    }
}
