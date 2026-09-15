<?php

namespace App\Http\Requests\Api\V1\Tenant;

use App\Models\Tenant\Medicine;
use App\Models\Tenant\StockInward;
use App\Models\Tenant\Supplier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A goods received note, as the supplier's invoice reads.
 *
 * Quantities and prices are per pack unless a line says `in_packs: false`;
 * the service turns them into base units. The Idempotency-Key header is
 * required — receiving goods twice because a button was pressed twice is
 * exactly what it prevents.
 */
class StoreStockInwardRequest extends FormRequest
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
        return [
            'idempotency_key' => ['required', 'string', 'max:100'],

            'inward_type' => ['required', 'string', Rule::in(StockInward::TYPES)],

            // A purchase names who it came from; an opening balance needn't.
            'supplier_id' => [
                'nullable', 'integer', 'required_if:inward_type,'.StockInward::PURCHASE,
                Rule::exists(Supplier::class, 'id')
                    ->whereNull('deleted_at')
                    ->where(fn ($query) => $query->where('is_active', true)),
            ],
            'supplier_invoice_no' => ['nullable', 'string', 'max:60'],
            'supplier_invoice_date' => ['nullable', 'date', 'before_or_equal:today'],
            'received_date' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.medicine_id' => [
                'required', 'integer',
                Rule::exists(Medicine::class, 'id')
                    ->whereNull('deleted_at')
                    ->where(fn ($query) => $query->where('is_active', true)),
            ],
            'items.*.batch_number' => ['required', 'string', 'max:60'],
            // Stock that has already expired is not received; it is refused at the door.
            'items.*.expiry_date' => ['required', 'date', 'after:today'],
            'items.*.manufacture_date' => ['nullable', 'date', 'before_or_equal:today', 'before:items.*.expiry_date'],
            'items.*.in_packs' => ['sometimes', 'boolean'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.free_quantity' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'items.*.purchase_price' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            'items.*.mrp' => ['required', 'numeric', 'min:0.01', 'max:9999999.99'],
            // Selling above MRP is unlawful; blank sells at MRP.
            'items.*.selling_price' => ['nullable', 'numeric', 'min:0', 'lte:items.*.mrp'],
        ];
    }

    /** The same batch twice on one note is one line typed twice. */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $seen = [];

                foreach ((array) $this->input('items', []) as $index => $item) {
                    $key = ($item['medicine_id'] ?? '').'|'.mb_strtolower(trim((string) ($item['batch_number'] ?? '')));

                    if (isset($seen[$key])) {
                        $validator->errors()->add(
                            "items.{$index}.batch_number",
                            'This batch is already on the note. Put its quantities on one line.'
                        );
                    }

                    $seen[$key] = true;
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'idempotency_key.required' => 'Send an Idempotency-Key header, so a retry cannot receive the goods twice.',
            'supplier_id.required_if' => 'Say which supplier the goods came from.',
            'items.*.expiry_date.after' => 'Expired stock cannot be received.',
            'items.*.selling_price.lte' => 'The selling price cannot be above the MRP.',
            'items.*.manufacture_date.before' => 'The manufacture date has to be before the expiry.',
        ];
    }
}
