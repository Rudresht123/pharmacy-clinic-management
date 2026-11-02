<?php

namespace App\Http\Requests\Api\V1\Tenant\Portal;

use App\Models\Tenant\Customer;
use App\Models\Tenant\FamilyMember;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Who a family member is. No phone or email: see PatientFamily for why they
 * are reached through the patient who adds them.
 */
class SaveFamilyMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:191'],
            'relation' => ['required', Rule::in(FamilyMember::RELATIONS)],
            'gender' => ['required', Rule::in(Customer::GENDERS)],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after:1900-01-01'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Please enter their name.',
            'relation.required' => 'Please choose how they are related to you.',
            'gender.required' => 'Please choose their gender.',
            'date_of_birth.before_or_equal' => 'Their date of birth cannot be in the future.',
        ];
    }
}
