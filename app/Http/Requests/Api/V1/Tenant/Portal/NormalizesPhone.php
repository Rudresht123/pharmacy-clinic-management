<?php

namespace App\Http\Requests\Api\V1\Tenant\Portal;

use App\Support\Phone;

/**
 * The phone number, reduced to its ten digits before any rule sees it — so
 * "+91 98765 43210" and "9876543210" validate, throttle and match as one.
 */
trait NormalizesPhone
{
    protected function prepareForValidation(): void
    {
        $this->merge(['phone' => Phone::digits($this->input('phone'))]);
    }

    /** @return list<mixed> */
    protected function phoneRules(): array
    {
        return ['required', 'string', 'regex:'.Phone::PATTERN];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'phone.required' => 'Enter your mobile number.',
            'phone.regex' => 'Enter a 10-digit mobile number.',
        ];
    }
}
