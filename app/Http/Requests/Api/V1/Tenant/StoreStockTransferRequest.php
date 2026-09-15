<?php

namespace App\Http\Requests\Api\V1\Tenant;

use App\Models\Tenant\MedicineBatch;
use App\Models\Tenant\PharmacyStore;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Moving stock between two stores.
 */
class StoreStockTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $store = Rule::exists(PharmacyStore::class, 'id')->whereNull('deleted_at');

        return [
            'idempotency_key' => ['required', 'string', 'max:100'],
            'from_store_id' => ['required', 'integer', $store],
            'to_store_id' => ['required', 'integer', 'different:from_store_id', $store],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.batch_id' => [
                'required', 'integer', 'distinct',
                Rule::exists(MedicineBatch::class, 'id')->whereNull('deleted_at'),
            ],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'idempotency_key.required' => 'Send an Idempotency-Key header, so a retry cannot transfer twice.',
            'to_store_id.different' => 'Stock moves between two different stores.',
            'items.*.batch_id.distinct' => 'This batch is already on the transfer.',
        ];
    }
}
