<?php

namespace App\Http\Requests\Api\V1\Tenant\Portal;

use App\Services\Portal\PatientOtp;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Sign in with a code.
 *
 * `name` is only needed when the clinic has no patient on this number yet —
 * the controller asks for it then, and the code stays valid while they type.
 */
class VerifyCodeRequest extends FormRequest
{
    use NormalizesPhone {
        messages as phoneMessages;
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone' => $this->phoneRules(),
            'code' => ['required', 'string', 'digits:'.PatientOtp::LENGTH],
            'name' => ['nullable', 'string', 'min:2', 'max:120'],
            'device_name' => ['nullable', 'string', 'max:60'],
        ];
    }

    public function messages(): array
    {
        return [
            ...$this->phoneMessages(),
            'code.required' => 'Enter the code we sent you.',
            'code.digits' => 'The code is '.PatientOtp::LENGTH.' digits.',
        ];
    }
}
