<?php

namespace App\Http\Requests\Api\V1\Tenant;

use App\Models\Tenant\Location;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A test's branch prices, replaced as a set. A branch left out charges the
 * test's own price again. The route holds `laboratory.catalog_manage`; the
 * controller checks each branch is one the writer may act at.
 */
class SaveLabTestPricesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'prices' => ['present', 'array', 'max:500'],
            // Through the model: branches live in the tenant's database.
            'prices.*.location_id' => ['required', 'integer', 'distinct', Rule::exists(Location::class, 'id')],
            'prices.*.price' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            'prices.*.tax_percent' => ['nullable', 'numeric', 'between:0,100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'prices.*.location_id.distinct' => 'A branch can have one price for a test.',
        ];
    }
}
