<?php

namespace App\Http\Requests\Api\V1\Tenant\Auth;

use App\Models\Platform\Organization;
use App\Services\Tenancy\TenantConnectionService;
use App\Support\Tenancy\SubdomainResolver;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Tenant sign-in.
 *
 * Separate from App\Http\Requests\Api\V1\Platform\Auth\LoginRequest, which
 * authenticates administrators on the `platform` guard. The two must not
 * share code paths: this one may only ever touch the `web` guard, and it is
 * the one place responsible for resolving *which* tenant database a login
 * belongs to before attempting it.
 *
 * The organization is identified from the request's Host header
 * (`{subdomain}.{main_domain}`) whenever that actually looks like a real
 * tenant subdomain; an explicit `subdomain` field is the fallback for
 * anything hitting the API from a host that isn't one (local API testing,
 * the test suite, or any client not reachable via real subdomain DNS).
 */
class LoginRequest extends FormRequest
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 900;

    public function __construct(
        private readonly TenantConnectionService $tenants,
    ) {
        parent::__construct();
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'subdomain' => ['nullable', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * @throws ValidationException
     */
    public function authenticate(): Organization
    {
        $this->ensureIsNotRateLimited();

        $organization = Organization::where('subdomain', $this->resolveSubdomain())->first();

        // A generic failure when the clinic itself doesn't resolve — same
        // message as a credentials mismatch, so a login form can't be used
        // to enumerate which subdomains exist.
        if (! $organization) {
            $this->fail();
        }

        if (! $organization->canSignIn()) {
            $this->failNotSignInable($organization);
        }

        $this->tenants->connect($organization->database_name);

        if (! Auth::guard('web')->attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            $this->fail();
        }

        if (! Auth::guard('web')->user()->is_active) {
            Auth::guard('web')->logout();

            $this->fail('This account has been deactivated.');
        }

        RateLimiter::clear($this->throttleKey());

        return $organization;
    }

    /**
     * @throws ValidationException
     */
    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * @throws ValidationException
     */
    private function fail(?string $message = null): never
    {
        RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);

        throw ValidationException::withMessages([
            'email' => $message ?? trans('auth.failed'),
        ]);
    }

    /**
     * Surfaces *why* — per the acceptance criterion that a suspended
     * organization's login says so, rather than a generic failure. Unlike a
     * credentials mismatch, an org's lifecycle state is not a secret its own
     * staff would be enumerating.
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

        $this->fail($message);
    }

    /** Keyed on address + IP, and namespaced so it cannot collide with platform logins. */
    protected function throttleKey(): string
    {
        return 'tenant|'.Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
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
