<?php

namespace App\Http\Requests\Api\V1\Onboarding;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * The invited owner choosing their own name and password.
 *
 * Public and unauthenticated by design — the setup token in the URL is the
 * only credential, and OrganizationSetupController validates it (existence,
 * expiry, not-already-used) before this data is ever applied.
 */
class SetupOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'admin_name' => ['required', 'string', 'max:191'],

            /*
             * Spelled out rather than Password::defaults() (which is only
             * min:8 here): the setup form states "at least 8 characters,
             * with upper and lower case, a number and a symbol" on screen,
             * and the rule has to actually be what the owner was promised.
             */
            'password' => [
                'required',
                'string',
                'confirmed',
                Password::min(8)->mixedCase()->numbers()->symbols(),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'admin_name.required' => 'Your name is required.',
            'password.required' => 'Password is required.',
            'password.confirmed' => 'The two passwords do not match.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'admin_name' => 'administrator name',
        ];
    }
}
