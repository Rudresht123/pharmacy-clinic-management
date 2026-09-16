<?php

namespace App\Http\Requests\Api\V1\Tenant;

use App\Models\Tenant\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A department or sub-department, as the tree screen saves it.
 *
 * The shape is checked here; where it may sit in the tree (two levels, not
 * its own parent, unique among its siblings) is DepartmentService's, which
 * sees the rest of the tree. The route is `settings.manage`.
 */
class SaveDepartmentRequest extends FormRequest
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
        $department = $this->route('department');

        return [
            'name' => ['required', 'string', 'max:120'],
            'code' => [
                'nullable', 'string', 'max:30', 'alpha_dash',
                Rule::unique(Department::class, 'code')
                    ->whereNull('deleted_at')
                    ->ignore($department instanceof Department ? $department->id : null),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'parent_id' => [
                'nullable', 'integer',
                Rule::exists(Department::class, 'id')->whereNull('deleted_at'),
            ],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'parent_id.exists' => 'That department has been removed. Choose another.',
            'code.unique' => 'Another department already uses that code.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->name) ? trim($this->name) : $this->name,
            // Codes are compared without regard to case, so they are kept in one.
            'code' => filled($this->code) ? strtoupper(trim((string) $this->code)) : null,
        ]);
    }
}
