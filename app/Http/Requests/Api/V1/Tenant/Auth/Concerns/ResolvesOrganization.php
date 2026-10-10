<?php

namespace App\Http\Requests\Api\V1\Tenant\Auth\Concerns;

use App\Models\Platform\Organization;
use App\Services\Tenancy\TenantConnectionService;
use App\Support\Tenancy\SubdomainResolver;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Finds which tenant database a request belongs to, checks it may be signed
 * in to, and points the shared `organization` connection at it — before
 * anything else in the request touches that connection.
 *
 * Shared by sign-in and the forgot/reset-password requests so the three
 * cannot drift: the organization is identified from the Host header
 * (`{subdomain}.{main_domain}`) whenever that looks like a real tenant
 * subdomain, falling back to an explicit `subdomain` field for anything
 * hitting the API from a host that isn't one (local testing, the test suite).
 *
 * Deliberately does not touch rate limiting — login throttles by email+IP on
 * every failure, forgot-password throttles by IP alone at the route. Each
 * caller keeps its own policy around this.
 */
trait ResolvesOrganization
{
    /** @throws ValidationException */
    protected function resolveTenantOrganization(TenantConnectionService $tenants): Organization
    {
        $organization = Organization::where('subdomain', $this->resolveSubdomain())->first();

        // Same generic message a credentials mismatch would give, so this
        // cannot be used to enumerate which subdomains exist.
        if (! $organization) {
            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        if (! $organization->canSignIn()) {
            $this->failNotSignInable($organization);
        }

        $tenants->connect($organization->database_name);

        return $organization;
    }

    /**
     * Surfaces *why* — an org's lifecycle state is not a secret its own staff
     * would be enumerating, unlike a credentials mismatch.
     *
     * @throws ValidationException
     */
    private function failNotSignInable(Organization $organization): never
    {
        $message = match ($organization->status) {
            Organization::SUSPENDED => 'This organization is suspended'
                .($organization->suspension_reason ? ": {$organization->suspension_reason}" : '.'),
            Organization::CANCELLED => 'This organization\'s subscription has been cancelled.',
            Organization::FAILED => 'This organization\'s setup could not be completed. Contact support.',
            default => 'This organization is not ready to sign in yet.',
        };

        throw ValidationException::withMessages([
            'email' => $message,
        ]);
    }

    /**
     * The Host header wins whenever it actually looks like a real tenant
     * subdomain — it can't be spoofed to a *different* value than whatever
     * DNS/hosts actually routed the browser through, unlike a body field.
     * Falls back to the request field for anything else (API testing, the
     * test suite, any client not reachable via real subdomain DNS).
     */
    private function resolveSubdomain(): string
    {
        return SubdomainResolver::fromHost($this->getHost())
            ?? Str::lower((string) $this->input('subdomain'));
    }
}
