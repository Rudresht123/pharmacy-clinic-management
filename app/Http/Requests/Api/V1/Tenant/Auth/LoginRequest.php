<?php

namespace App\Http\Requests\Api\V1\Tenant\Auth;

use App\Http\Requests\Api\V1\Tenant\Auth\Concerns\ResolvesOrganization;
use App\Models\Platform\Organization;
use App\Models\Tenant\User as TenantUser;
use App\Services\Tenancy\TenantConnectionService;
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
    use ResolvesOrganization;

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

            // Only the token endpoint sends this: what to call the token in a
            // list of signed-in devices.
            'device_name' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * Signs in on the `web` guard, which puts the session in place.
     *
     * @throws ValidationException
     */
    public function authenticate(): Organization
    {
        $organization = $this->resolveOrganization();

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
     * Checks the same credentials without starting a session.
     *
     * The token endpoint cannot use [authenticate]: `web` is a session guard,
     * and a phone is not a stateful client — `EnsureFrontendRequestsAreStateful`
     * only makes a request stateful when it comes from a configured domain, so
     * there is no session for the guard to write into. Asking the user provider
     * directly does the identical credential check with nothing to write.
     *
     * @return array{0: Organization, 1: TenantUser}
     *
     * @throws ValidationException
     */
    public function authenticateStateless(): array
    {
        $organization = $this->resolveOrganization();

        $provider = Auth::createUserProvider('users');

        $user = $provider?->retrieveByCredentials($this->only('email'));

        // One failure for "no such address" and "wrong password", and the
        // password is verified even when the address is unknown, so the two
        // cannot be told apart by how long the answer takes.
        if (! $user || ! $provider->validateCredentials($user, $this->only('password'))) {
            $this->fail();
        }

        if (! $user->is_active) {
            $this->fail('This account has been deactivated.');
        }

        RateLimiter::clear($this->throttleKey());

        return [$organization, $user];
    }

    /**
     * Finds the organization, checks it may be signed in to, and points the
     * shared connection at its database — the lookup itself is
     * ResolvesOrganization, shared with the forgot/reset-password requests.
     *
     * Wrapped here (rather than called directly) so that EVERY way this can
     * fail — including "no such subdomain", which the trait raises on its
     * own — still counts against the login rate limit. Forgot/reset-password
     * have no such counter to feed.
     *
     * @throws ValidationException
     */
    private function resolveOrganization(): Organization
    {
        $this->ensureIsNotRateLimited();

        try {
            return $this->resolveTenantOrganization($this->tenants);
        } catch (ValidationException $e) {
            RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);

            throw $e;
        }
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

    /** Keyed on address + IP, and namespaced so it cannot collide with platform logins. */
    protected function throttleKey(): string
    {
        return 'tenant|'.Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
