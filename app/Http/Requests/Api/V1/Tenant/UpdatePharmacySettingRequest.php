<?php

namespace App\Http\Requests\Api\V1\Tenant;

use App\Models\Tenant\PharmacySetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Changing how the pharmacy bills, prices and warns.
 *
 * Every field is optional: the screen saves the whole form, but a client
 * that sends one setting changes only that one. Authorization is the route's
 * (`pharmacy.stores`, the organisation-wide setup capability).
 */
class UpdatePharmacySettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('invoice_prefix')) {
            // It goes on every bill, so it is stored the way it will be printed.
            $this->merge(['invoice_prefix' => mb_strtoupper(trim((string) $this->input('invoice_prefix')))]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Letters only: the number after it is the sequence's.
            'invoice_prefix' => ['sometimes', 'string', 'max:6', 'regex:/^[A-Z]{2,6}$/'],
            'round_off_enabled' => ['sometimes', 'boolean'],

            'price_basis' => ['sometimes', 'string', Rule::in(PharmacySetting::PRICE_BASES)],
            'prices_include_tax' => ['sometimes', 'boolean'],

            'expiry_warning_days' => ['sometimes', 'integer', 'min:1', 'max:365'],

            'allow_walk_in' => ['sometimes', 'boolean'],
            'credit_sales_enabled' => ['sometimes', 'boolean'],
            'require_prescription' => ['sometimes', 'boolean'],

            'default_payment_method' => ['sometimes', 'string', Rule::in(PharmacySetting::PAYMENT_METHODS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'invoice_prefix.regex' => 'Two to six letters, such as INV or BILL.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'invoice_prefix' => 'invoice prefix',
            'price_basis' => 'price basis',
            'expiry_warning_days' => 'expiry warning',
            'default_payment_method' => 'default payment method',
        ];
    }
}
