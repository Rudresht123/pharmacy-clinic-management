<?php

namespace App\Http\Requests\Api\V1\Platform;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateOrganizationTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Ignoring the current row is what the old web request was missing,
        // which made every edit fail the unique check.
        $id = $this->route('organizationType')?->id;

        return [
            'name' => [
                'required', 'string', 'max:191',
                Rule::unique('organization_types', 'name')->ignore($id)->whereNull('deleted_at'),
            ],
            'slug' => [
                'required', 'string', 'max:191',
                Rule::unique('organization_types', 'slug')->ignore($id)->whereNull('deleted_at'),
            ],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Organization type name is required.',
            'name.unique' => 'This organization type already exists.',
            'slug.unique' => 'This slug is already in use.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'slug' => Str::slug((string) ($this->slug ?: $this->name)),
        ]);
    }
}
