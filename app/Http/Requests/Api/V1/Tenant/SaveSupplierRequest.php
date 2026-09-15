<?php

namespace App\Http\Requests\Api\V1\Tenant;

use App\Models\Tenant\Supplier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Adding or editing a supplier.
 *
 * Codes and GSTINs are compared in capitals, the way they are printed, so
 * "abc-01" and "ABC-01" are one code — the same as the unique indexes.
 */
class SaveSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    private function supplier(): ?Supplier
    {
        $supplier = $this->route('supplier');

        return $supplier instanceof Supplier ? $supplier : null;
    }

    protected function prepareForValidation(): void
    {
        foreach (['code', 'gstin'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => strtoupper(trim($this->input($key)))]);
            }
        }

        if ($this->input('is_active') === null && ($this->has('is_active') || ! $this->supplier())) {
            $this->merge(['is_active' => $this->supplier()?->is_active ?? true]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $id = $this->supplier()?->id;
        $required = $id ? ['sometimes', 'required'] : ['required'];

        return [
            'name' => [...$required, 'string', 'max:191'],
            'code' => [
                'nullable', 'string', 'max:30', 'regex:/^[A-Z0-9][A-Z0-9._-]*$/',
                Rule::unique(Supplier::class, 'code')->ignore($id)->whereNull('deleted_at'),
            ],
            // The fifteen-character GST number: state, PAN, entity, Z, check.
            'gstin' => [
                'nullable', 'string', 'size:15', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/',
                Rule::unique(Supplier::class, 'gstin')->ignore($id)->whereNull('deleted_at'),
            ],
            'drug_license_no' => ['nullable', 'string', 'max:60'],
            'drug_license_expiry_date' => ['nullable', 'date', 'required_with:drug_license_no'],
            'contact_person' => ['nullable', 'string', 'max:191'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:191'],
            'address' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.unique' => 'Another supplier already uses this code.',
            'gstin.unique' => 'Another supplier is already registered under this GSTIN.',
            'gstin.regex' => 'That is not a GSTIN. It has 15 characters, like 27AAPFU0939F1ZV.',
            'code.regex' => 'Use letters, numbers, dots, dashes and underscores.',
            'drug_license_expiry_date.required_with' => 'Give the licence its expiry date.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'gstin' => 'GSTIN',
            'drug_license_no' => 'drug licence number',
            'drug_license_expiry_date' => 'licence expiry date',
            'contact_person' => 'contact person',
        ];
    }
}
