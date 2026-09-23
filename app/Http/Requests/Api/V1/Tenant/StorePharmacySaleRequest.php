<?php

namespace App\Http\Requests\Api\V1\Tenant;

use App\Models\Tenant\Customer;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Medicine;
use App\Models\Tenant\MedicineBatch;
use App\Models\Tenant\PharmacySalePayment;
use App\Models\Tenant\Prescription;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A sale, as the counter rings it up.
 *
 * Quantities are in base units — tablets, not strips — because that is what
 * the shelf holds and what the ledger counts. A line may name its batch, or
 * leave it out and be filled first-expiry-first-out.
 *
 * What this request checks is shape: that the numbers are numbers and the
 * ids exist. Whether there is enough stock, whether the batch has expired
 * and whether the bill may be left unpaid are the service's, because only
 * the service holds the lock that makes those answers true.
 *
 * The Idempotency-Key header is required: a double-tap at a busy counter
 * must not sell the same strip twice.
 */
class StorePharmacySaleRequest extends FormRequest
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
        $live = fn (string $model) => Rule::exists($model, 'id')->whereNull('deleted_at');

        return [
            'idempotency_key' => ['required', 'string', 'max:100'],

            // Registered, or nobody: a store sells to whoever walks in.
            'customer_id' => ['nullable', 'integer', $live(Customer::class)],
            'walk_in_name' => ['nullable', 'string', 'max:120'],
            'walk_in_phone' => ['nullable', 'string', 'max:20'],

            // The clinic integration, when there is one. Optional, always.
            'prescription_id' => ['nullable', 'integer', $live(Prescription::class)],
            'doctor_id' => ['nullable', 'integer', $live(Doctor::class)],

            'notes' => ['nullable', 'string', 'max:2000'],

            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.medicine_id' => [
                'required', 'integer',
                Rule::exists(Medicine::class, 'id')
                    ->whereNull('deleted_at')
                    ->where(fn ($query) => $query->where('is_active', true)),
            ],
            // Left out, the counter fills the line first-expiry-first-out.
            'items.*.medicine_batch_id' => ['nullable', 'integer', $live(MedicineBatch::class)],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            // Never above the batch's price; the service caps it too.
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'items.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],

            'payments' => ['nullable', 'array', 'max:5'],
            'payments.*.method' => ['required', 'string', Rule::in(PharmacySalePayment::METHODS)],
            'payments.*.amount' => ['required', 'numeric', 'min:0.01', 'max:9999999.99'],
            'payments.*.reference' => ['nullable', 'string', 'max:60'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'idempotency_key.required' => 'This sale was sent without an Idempotency-Key header.',
            'items.required' => 'A bill needs at least one item.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'customer_id' => 'customer',
            'walk_in_name' => 'customer name',
            'items.*.medicine_id' => 'item',
            'items.*.medicine_batch_id' => 'batch',
            'items.*.quantity' => 'quantity',
            'items.*.unit_price' => 'price',
            'items.*.discount_percent' => 'discount',
        ];
    }
}
