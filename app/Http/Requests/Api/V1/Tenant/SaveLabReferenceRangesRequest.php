<?php

namespace App\Http\Requests\Api\V1\Tenant;

use App\Models\Tenant\LabReferenceRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A parameter's reference ranges, replaced as a set.
 *
 * The shape is checked here; whether the set makes sense for this parameter
 * (numbers only on a numeric answer, critical limits outside the normal ones,
 * one range per age within a sex) is ReferenceRanges'. The route holds
 * `laboratory.ranges_manage` — a clinical decision, apart from editing tests.
 */
class SaveLabReferenceRangesRequest extends FormRequest
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
        $limit = ['nullable', 'numeric', 'between:-999999999999,999999999999'];

        return [
            'ranges' => ['present', 'array', 'max:50'],
            'ranges.*.id' => ['nullable', 'integer'],
            'ranges.*.sex' => ['required', Rule::in(LabReferenceRange::SEXES)],
            'ranges.*.age_min' => ['nullable', 'integer', 'between:0,1200'],
            'ranges.*.age_max' => ['nullable', 'integer', 'between:1,1200'],
            'ranges.*.age_unit' => ['nullable', Rule::in(LabReferenceRange::AGE_UNITS)],
            'ranges.*.low' => $limit,
            'ranges.*.high' => $limit,
            'ranges.*.critical_low' => $limit,
            'ranges.*.critical_high' => $limit,
            'ranges.*.normal_text' => ['nullable', 'string', 'max:120'],
            'ranges.*.display_text' => ['nullable', 'string', 'max:120'],
            'ranges.*.notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** Blank inputs from the form are "not given", not zero. */
    protected function prepareForValidation(): void
    {
        if (! is_array($this->input('ranges'))) {
            return;
        }

        $this->merge([
            'ranges' => array_map(function ($row) {
                if (! is_array($row)) {
                    return $row;
                }

                foreach ($row as $field => $value) {
                    if (is_string($value)) {
                        $value = trim($value);
                        $row[$field] = $value === '' ? null : $value;
                    }
                }

                return $row;
            }, $this->input('ranges')),
        ]);
    }
}
