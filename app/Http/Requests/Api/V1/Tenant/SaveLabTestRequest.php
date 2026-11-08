<?php

namespace App\Http\Requests\Api\V1\Tenant;

use App\Models\Tenant\LabContainer;
use App\Models\Tenant\LabDepartment;
use App\Models\Tenant\LabMethod;
use App\Models\Tenant\LabOutsourceLab;
use App\Models\Tenant\LabSampleType;
use App\Models\Tenant\LabTest;
use App\Models\Tenant\LabTestCategory;
use App\Models\Tenant\LabTestParameter;
use App\Models\Tenant\LabUnit;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A test and its parameters, as the Test page saves them — one request.
 *
 * The shape is checked here. Whether the parameters make sense together (at
 * least one, a ranged parameter keeping its kind of answer) is LabCatalog's,
 * which sees what is already stored. The route holds `laboratory.catalog_manage`.
 */
class SaveLabTestRequest extends FormRequest
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
        $test = $this->route('test');
        $creating = ! $test instanceof LabTest;
        $required = $creating ? 'required' : 'sometimes';
        $live = fn (string $model) => Rule::exists($model, 'id')->whereNull('deleted_at');
        $money = ['numeric', 'min:0', 'max:9999999.99'];

        return [
            'code' => [$required, 'string', 'max:24', 'regex:/^[A-Za-z0-9._-]+$/', $this->uniqueCode($test)],
            'name' => [$required, 'string', 'filled', 'max:191'],
            'short_name' => ['nullable', 'string', 'max:60'],

            'department_id' => [$required, 'integer', $live(LabDepartment::class)],
            'category_id' => ['nullable', 'integer', $live(LabTestCategory::class)],
            'sample_type_id' => ['nullable', 'integer', $live(LabSampleType::class)],
            'container_id' => ['nullable', 'integer', $live(LabContainer::class)],
            'method_id' => ['nullable', 'integer', $live(LabMethod::class)],

            'sample_volume' => ['nullable', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:5000'],
            'collection_instructions' => ['nullable', 'string', 'max:5000'],
            'processing_instructions' => ['nullable', 'string', 'max:5000'],

            'result_layout' => ['sometimes', Rule::in(LabTest::LAYOUTS)],
            'report_template' => ['nullable', 'string', 'max:20000'],
            'tat_hours' => ['nullable', 'integer', 'between:1,2160'],

            'price' => [$required, ...$money],
            'tax_percent' => ['sometimes', 'numeric', 'between:0,100'],
            'hsn_code' => ['nullable', 'string', 'max:16'],

            'is_outsourced' => ['sometimes', 'boolean'],
            'outsource_lab_id' => ['nullable', 'integer', $live(LabOutsourceLab::class)],
            'outsource_cost' => ['nullable', ...$money],

            'is_active' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:99999'],

            'parameters' => ['sometimes', 'array', 'max:100'],
            'parameters.*.id' => ['nullable', 'integer'],
            'parameters.*.name' => ['required', 'string', 'filled', 'max:120'],
            'parameters.*.code' => ['nullable', 'string', 'max:24', 'regex:/^[A-Za-z0-9._-]+$/', 'distinct:ignore_case'],
            'parameters.*.section' => ['nullable', 'string', 'max:60'],
            'parameters.*.result_type' => ['required', Rule::in(LabTestParameter::RESULT_TYPES)],
            'parameters.*.unit_id' => ['nullable', 'integer', $live(LabUnit::class)],
            'parameters.*.decimals' => ['nullable', 'integer', 'between:0,6'],
            'parameters.*.choices' => ['nullable', 'required_if:parameters.*.result_type,'.LabTestParameter::CHOICE, 'array', 'min:2', 'max:30'],
            'parameters.*.choices.*.value' => ['required', 'string', 'filled', 'max:60'],
            'parameters.*.choices.*.abnormal' => ['sometimes', 'boolean'],
            'parameters.*.normal_value' => ['nullable', 'string', 'max:60'],
            'parameters.*.method_id' => ['nullable', 'integer', $live(LabMethod::class)],
            'parameters.*.is_required' => ['sometimes', 'boolean'],
            'parameters.*.is_printed' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'parameters.*.name.required' => 'Every parameter needs a name.',
            'parameters.*.code.distinct' => 'Two parameters share this code.',
            'parameters.*.choices.required_if' => 'A dropdown needs its answers.',
            'parameters.*.choices.min' => 'A dropdown needs at least two answers.',
            'department_id.exists' => 'That department has been removed. Choose another.',
        ];
    }

    /** Codes are unique among live tests, ignoring case. */
    private function uniqueCode(mixed $test): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($test): void {
            if (! is_string($value) || trim($value) === '') {
                return;
            }

            $taken = LabTest::query()
                ->whereRaw('lower(code) = ?', [mb_strtolower($value)])
                ->when($test instanceof LabTest, fn ($query) => $query->whereKeyNot($test->id))
                ->exists();

            if ($taken) {
                $fail("Another test already has the code {$value}.");
            }
        };
    }

    /** Strings trimmed, blanks made null, codes kept upper-case. */
    protected function prepareForValidation(): void
    {
        $clean = function (mixed $value) {
            if (! is_string($value)) {
                return $value;
            }

            $value = trim($value);

            return $value === '' ? null : $value;
        };

        $merged = [];

        foreach (['code', 'name', 'short_name', 'sample_volume', 'hsn_code'] as $field) {
            if ($this->has($field)) {
                $merged[$field] = $clean($this->input($field));
            }
        }

        if (isset($merged['code'])) {
            $merged['code'] = mb_strtoupper($merged['code']);
        }

        if (is_array($this->input('parameters'))) {
            $merged['parameters'] = array_map(function ($line) use ($clean) {
                if (! is_array($line)) {
                    return $line;
                }

                foreach (['name', 'code', 'section', 'normal_value'] as $field) {
                    if (array_key_exists($field, $line)) {
                        $line[$field] = $clean($line[$field]);
                    }
                }

                if (isset($line['code'])) {
                    $line['code'] = mb_strtoupper($line['code']);
                }

                return $line;
            }, $this->input('parameters'));
        }

        $this->merge($merged);
    }
}
