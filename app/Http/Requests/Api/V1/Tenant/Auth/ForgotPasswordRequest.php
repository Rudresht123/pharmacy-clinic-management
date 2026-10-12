<?php

namespace App\Http\Requests\Api\V1\Tenant\Auth;

use App\Http\Requests\Api\V1\Tenant\Auth\Concerns\ResolvesOrganization;
use App\Models\Platform\Organization;
use App\Services\Tenancy\TenantConnectionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * Who asked for a reset link, and which organization they belong to.
 *
 * Mirrors LoginRequest's own resolution of the organization — same Host
 * header, same `subdomain` fallback — because a reset link has to be sent
 * from (and point back at) the same tenant database a sign-in would use.
 */
class ForgotPasswordRequest extends FormRequest
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
            'email' => ['required', 'string', 'email'],
        ];
    }

    /** @throws ValidationException */
    public function resolveOrganization(): Organization
    {
        return $this->resolveTenantOrganization($this->tenants);
    }
}
