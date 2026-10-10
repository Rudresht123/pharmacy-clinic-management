<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Tenant\BillingSetting;
use App\Models\Tenant\InvoicePayment;
use App\Models\Tenant\PharmacySetting;
use App\Services\Permissions\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * How the organisation bills.
 *
 * Reading is `billing.view`; writing is `billing.manage_settings` — an
 * organisation-scoped capability, so branch roles never hold it. Changing
 * these settings does not rewrite an already-drawn invoice: a bill keeps the
 * trigger, currency and tax rule it was drawn under, and its own totals.
 *
 * THREE LAYERS, ONE ROW:
 *
 *   organisation defaults  payment methods and policy, editing, currency,
 *                          tax, printed wording — shared by every department
 *   department timing      `default_trigger` (OPD) and `lab_trigger` (lab);
 *                          the pharmacy bills at its own counter, so it has
 *                          no timing here — its options are summarised
 *                          read-only from its own settings
 *   per branch             numbering (BillingNumberingController) and prices
 *                          (billable services, the lab's price list)
 *
 * @group Tenant > Billing
 */
class BillingSettingController extends BaseApiController
{
    public function __construct(
        private readonly Permission $permission,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $settings = BillingSetting::current();

        return $this->ok([
            /*
             * The OPD timing as it is APPLIED — a stored value that is no
             * longer an OPD timing reads as the default — with what is
             * actually stored alongside, so the screen can say so.
             */
            'default_trigger' => $settings->opdTrigger(),
            'stored_default_trigger' => $settings->default_trigger,
            'lab_trigger' => $settings->labTrigger(),
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

            'triggers' => BillingSetting::OPD_TRIGGERS,
            'lab_triggers' => BillingSetting::LAB_TRIGGERS,
            'payment_behaviours' => BillingSetting::PAYMENT_BEHAVIOURS,
            'available_payment_methods' => InvoicePayment::METHODS,

            'pharmacy' => $this->pharmacy($request),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'default_trigger' => ['required', 'string', Rule::in(BillingSetting::OPD_TRIGGERS)],
            // Optional so an older screen that does not know about it still saves.
            'lab_trigger' => ['sometimes', 'string', Rule::in(BillingSetting::LAB_TRIGGERS)],
            'payment_methods' => ['required', 'array', 'min:1'],
            'payment_methods.*' => ['string', 'distinct', Rule::in(InvoicePayment::METHODS)],
            'payment_behaviour' => ['required', 'string', Rule::in(BillingSetting::PAYMENT_BEHAVIOURS)],
            'prices_include_tax' => ['required', 'boolean'],
            'default_tax_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'allow_edit_before_payment' => ['required', 'boolean'],
            'invoice_prefix' => ['required', 'string', 'max:12'],
            'currency_code' => ['required', 'string', 'size:3'],
            'currency_symbol' => ['required', 'string', 'max:4'],
            'terms' => ['nullable', 'string', 'max:2000'],
            'footer' => ['nullable', 'string', 'max:500'],
        ], [
            'default_trigger.in' => 'Choose when the OPD makes a bill: at check-in, after the consultation, or by hand.',
            'payment_methods.*.in' => 'That is not a payment method the software knows.',
        ]);

        $validated['currency_code'] = strtoupper($validated['currency_code']);

        $settings = BillingSetting::current();
        $settings->fill($validated)->save();

        return $this->ok(null, 'Billing settings saved');
    }

    /**
     * The pharmacy's own billing options, for reading beside the rest.
     *
     * Read-only here: they belong to the pharmacy's settings, and the screen
     * links there. Null when the pharmacy does not run for this person.
     *
     * @return array<string, mixed>|null
     */
    private function pharmacy(Request $request): ?array
    {
        $organization = $request->attributes->get('tenant.organization');

        if (! $organization || ! $this->permission->hasModule($organization, 'pharmacy')) {
            return null;
        }

        $pharmacy = PharmacySetting::current();

        return [
            'credit_sales_enabled' => (bool) $pharmacy->credit_sales_enabled,
            'allow_walk_in' => (bool) $pharmacy->allow_walk_in,
            'prices_include_tax' => (bool) $pharmacy->prices_include_tax,
            'price_basis' => $pharmacy->price_basis,
            'round_off_enabled' => (bool) $pharmacy->round_off_enabled,
        ];
    }
}
