<?php

namespace App\Http\Requests\Api\V1\Tenant;

use App\Models\Tenant\MedicineBatch;
use App\Models\Tenant\StockAdjustment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A stock adjustment on one batch, with a required reason.
 */
class StoreStockAdjustmentRequest extends FormRequest
{
    /** Reasons that only ever take stock away. */
    private const DECREASE_ONLY = [StockAdjustment::DAMAGE, StockAdjustment::EXPIRY_WRITEOFF, StockAdjustment::LOSS];

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
            'medicine_batch_id' => [
                'required', 'integer',
                Rule::exists(MedicineBatch::class, 'id')->whereNull('deleted_at'),
            ],
            'direction' => ['required', 'string', Rule::in(StockAdjustment::DIRECTIONS)],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'reason_code' => ['required', 'string', Rule::in(StockAdjustment::REASON_CODES)],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($this->input('direction') === StockAdjustment::INCREASE
                    && in_array($this->input('reason_code'), self::DECREASE_ONLY, true)) {
                    $validator->errors()->add('reason_code', 'Damage, loss and write-offs take stock away. Use a count correction to add it.');
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
            'idempotency_key.required' => 'Send an Idempotency-Key header, so a retry cannot adjust twice.',
        ];
    }
}
