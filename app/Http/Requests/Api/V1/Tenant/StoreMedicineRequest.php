<?php

namespace App\Http\Requests\Api\V1\Tenant;

use App\Http\Requests\Api\V1\Tenant\Concerns\MergesFieldSettings;
use App\Models\Tenant\EntityFieldSetting;
use App\Models\Tenant\Medicine;
use App\Repositories\Tenant\Contracts\MedicineRepositoryInterface;
use App\Support\Fields\MedicineFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Adding a medicine to the catalogue.
 *
 * UpdateMedicineRequest extends this: the rules are the same, and the only
 * difference — which medicine is being edited — is the medicine() hook.
 */
class StoreMedicineRequest extends FormRequest
{
    use MergesFieldSettings;

    public function authorize(): bool
    {
        return true;
    }

    /** The medicine being edited; none when adding one. */
    protected function medicine(): ?Medicine
    {
        return null;
    }

    /**
     * Columns that cannot be null take their default when left blank — on
     * an edit, the value the medicine already has.
     */
    protected function prepareForValidation(): void
    {
        $current = $this->medicine();

        $defaults = [
            'pack_size' => $current?->pack_size ?? 1,
            'prescription_required' => $current?->prescription_required ?? true,
            'is_active' => $current?->is_active ?? true,
        ];

        foreach ($defaults as $key => $value) {
            $given = $this->input($key);

            if (($given === null || $given === '') && ($this->has($key) || $current === null)) {
                $this->merge([$key => $value]);
            }
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        // On an edit a field left out keeps its value; on a create it is needed.
        $required = $this->medicine() ? ['sometimes', 'required'] : ['required'];

        $rules = [
            'medicine_code' => ['nullable', 'string', 'max:40'],

            'generic_name' => [...$required, 'string', 'max:191'],
            'brand_name' => ['nullable', 'string', 'max:191'],
            'strength' => ['nullable', 'string', 'max:60'],
            'dosage_form' => [...$required, 'string', Rule::in(Medicine::DOSAGE_FORMS)],
            'route' => ['nullable', 'string', Rule::in(Medicine::ROUTES)],

            'base_unit' => [...$required, 'string', Rule::in(Medicine::BASE_UNITS)],
            'pack_size' => ['integer', 'min:1', 'max:100000'],

            'manufacturer' => ['nullable', 'string', 'max:191'],
            'category' => ['nullable', 'string', 'max:100'],

            'schedule' => ['nullable', 'string', Rule::in(Medicine::SCHEDULES)],
            'prescription_required' => ['boolean'],

            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ];

        return $this->withFieldSettings(
            $rules,
            EntityFieldSetting::ENTITY_MEDICINE,
            MedicineFields::all(),
        );
    }

    /**
     * Duplicates, checked once every field is individually valid.
     *
     * Two rules the database also enforces, answered here as a sentence
     * naming the medicine that is already there — the index would only say
     * "unique violation". The identity check also catches what the index
     * cannot: "500mg" against "500 mg", "Dolo-650" against "Dolo 650".
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $medicines = app(MedicineRepositoryInterface::class);
                $current = $this->medicine();

                $identity = [];

                foreach (Medicine::IDENTITY as $key) {
                    $identity[$key] = $this->has($key) ? $this->input($key) : $current?->{$key};
                }

                if ($duplicate = $medicines->findDuplicate($identity, $current?->id)) {
                    $validator->errors()->add(
                        'generic_name',
                        "This medicine is already in the catalogue as {$duplicate->displayName()}. Edit that one instead."
                    );
                }

                $code = $this->input('medicine_code');

                if (filled($code) && ($taken = $medicines->findByCode((string) $code, $current?->id))) {
                    $validator->errors()->add(
                        'medicine_code',
                        "{$taken->displayName()} already uses this code."
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'generic_name' => 'generic name',
            'brand_name' => 'brand name',
            'dosage_form' => 'dosage form',
            'base_unit' => 'base unit',
            'pack_size' => 'units per pack',
            'medicine_code' => 'code',
            'prescription_required' => 'prescription required',
        ];
    }
}
