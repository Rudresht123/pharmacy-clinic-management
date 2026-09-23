<?php

namespace App\Http\Requests\Api\V1\Tenant\Portal;

use Illuminate\Foundation\Http\FormRequest;

/** Ask for a sign-in code. The clinic comes from the X-Organization header. */
class SendCodeRequest extends FormRequest
{
    use NormalizesPhone;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['phone' => $this->phoneRules()];
    }
}
