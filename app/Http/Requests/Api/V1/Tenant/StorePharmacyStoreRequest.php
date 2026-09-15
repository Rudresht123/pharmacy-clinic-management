<?php

namespace App\Http\Requests\Api\V1\Tenant;

use App\Models\Tenant\Location;
use App\Models\Tenant\PharmacyStore;
use App\Models\Tenant\User;
use App\Repositories\Tenant\Contracts\PharmacyStoreRepositoryInterface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Adding a pharmacy store.
 *
 * UpdatePharmacyStoreRequest extends this; the store() hook is the only
 * difference. Whether the person may add a store at the chosen branch is the
 * Policy's question, asked by the controller once this has passed.
 */
class StorePharmacyStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** The store being edited; none when adding one. */
    protected function store(): ?PharmacyStore
    {
        return null;
    }

    protected function prepareForValidation(): void
    {
        $current = $this->store();

        foreach (['is_active' => $current?->is_active ?? true, 'is_default' => false] as $key => $default) {
            if ($this->input($key) === null && ($this->has($key) || $current === null)) {
                $this->merge([$key => $default]);
            }
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $editing = $this->store() !== null;
        $required = $editing ? ['sometimes', 'required'] : ['required'];

        return [
            /*
             * A store belongs to its branch for life. Moving one — with its
             * stock, from Phase 3 — is a transfer, not an edit.
             */
            'location_id' => $editing
                ? ['prohibited']
                : ['required', 'integer', Rule::exists(Location::class, 'id')->whereNull('deleted_at')],

            'name' => [...$required, 'string', 'max:191'],
            'code' => [...$required, 'string', 'max:30', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
            'store_type' => [...$required, 'string', Rule::in(PharmacyStore::TYPES)],

            'is_default' => ['boolean'],
            'is_active' => ['boolean'],

            'pharmacist_user_id' => [
                'nullable', 'integer',
                Rule::exists(User::class, 'id')->whereNull('deleted_at'),
            ],

            'address' => ['nullable', 'string', 'max:1000'],
            'phone' => ['nullable', 'string', 'max:20'],

            'drug_license_no' => ['nullable', 'string', 'max:60'],
            'drug_license_expiry_date' => ['nullable', 'date', 'required_with:drug_license_no'],
        ];
    }

    /**
     * The two rules a single field cannot express: a case-insensitive code,
     * and a default store that is actually in use.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $current = $this->store();
                $code = $this->input('code', $current?->code);

                if (filled($code)
                    && ($taken = app(PharmacyStoreRepositoryInterface::class)->findByCode((string) $code, $current?->id))) {
                    $validator->errors()->add('code', "{$taken->name} already uses this code.");
                }

                $default = (bool) $this->input('is_default', $current?->is_default);
                $active = (bool) $this->input('is_active', $current?->is_active ?? true);

                if ($default && ! $active) {
                    $validator->errors()->add(
                        'is_active',
                        'The default store is the one doctors see stock from, so it has to be active.'
                    );
                }

                /*
                 * The default moves by making another store the default, never
                 * by switching it off here — that would leave the branch with
                 * none for doctors to see.
                 */
                if ($current?->is_default && $this->has('is_default') && ! $this->boolean('is_default')) {
                    $validator->errors()->add(
                        'is_default',
                        'Make another store at this branch the default instead.'
                    );
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
            'location_id.prohibited' => 'A store stays at its branch. Add a new store at the other branch instead.',
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
            'location_id' => 'branch',
            'store_type' => 'store type',
            'pharmacist_user_id' => 'pharmacist',
            'drug_license_no' => 'drug licence number',
            'drug_license_expiry_date' => 'licence expiry date',
        ];
    }
}
