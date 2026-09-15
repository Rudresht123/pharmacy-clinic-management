<?php

namespace App\Http\Requests\Api\V1\Tenant;

use App\Models\Tenant\Medicine;
use App\Models\Tenant\Supplier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Levels for some of a store's medicines.
 *
 * Only the rows sent are changed. The levels mirror the table's CHECK:
 * nothing negative, and a maximum, when there is one, at or above the
 * minimum.
 */
class SaveStoreMedicinesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'medicines' => ['required', 'array', 'min:1', 'max:200'],

            // A removed medicine cannot be newly stocked; restore it first.
            'medicines.*.medicine_id' => [
                'required', 'integer', 'distinct',
                Rule::exists(Medicine::class, 'id')->whereNull('deleted_at'),
            ],

            'medicines.*.reorder_level' => ['required', 'integer', 'min:0', 'max:10000000'],
            'medicines.*.minimum_stock_level' => ['required', 'integer', 'min:0', 'max:10000000'],
            'medicines.*.maximum_stock_level' => [
                'nullable', 'integer', 'max:10000000', 'gte:medicines.*.minimum_stock_level',
            ],
            'medicines.*.preferred_supplier_id' => [
                'nullable', 'integer',
                Rule::exists(Supplier::class, 'id')->whereNull('deleted_at'),
            ],
            'medicines.*.is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'medicines.*.medicine_id.exists' => 'That medicine is not in the catalogue.',
            'medicines.*.medicine_id.distinct' => 'This medicine appears twice.',
            'medicines.*.maximum_stock_level.gte' => 'The maximum cannot be below the minimum.',
        ];
    }
}
