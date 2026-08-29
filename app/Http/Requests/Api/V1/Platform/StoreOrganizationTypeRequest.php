<?php

namespace App\Http\Requests\Api\V1\Platform;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreOrganizationTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:191',
                Rule::unique('organization_type', 'name')->whereNull('deleted_at'),
            ],
            'slug' => [
                'required', 'string', 'max:191',
                Rule::unique('organization_type', 'slug')->whereNull('deleted_at'),
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
