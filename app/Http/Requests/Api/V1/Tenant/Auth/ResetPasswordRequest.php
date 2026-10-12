<?php

namespace App\Http\Requests\Api\V1\Tenant\Auth;

use App\Http\Requests\Api\V1\Tenant\Auth\Concerns\ResolvesOrganization;
use App\Models\Platform\Organization;
use App\Services\Tenancy\TenantConnectionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Redeeming a reset link. Resolves the organization the same way
 * ForgotPasswordRequest sent it — the emailed link carries `subdomain` as a
 * fallback for whenever the Host header alone cannot say which tenant this
 * is (see Notifications\Tenant\ResetPasswordNotification).
 */
class ResetPasswordRequest extends FormRequest
{
    use ResolvesOrganization;

    public function __construct(
        private readonly TenantConnectionService $tenants,
    ) {
        parent::__construct();
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'subdomain' => ['nullable', 'string'],
            'token' => ['required'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }

    /** @throws ValidationException */
    public function resolveOrganization(): Organization
    {
        return $this->resolveTenantOrganization($this->tenants);
    }
}
