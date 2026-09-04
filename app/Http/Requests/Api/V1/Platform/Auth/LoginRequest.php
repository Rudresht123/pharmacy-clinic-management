<?php

namespace App\Http\Requests\Api\V1\Platform\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Admin panel sign-in — Build Spec §18.
 *
 * Separate from App\Http\Requests\Auth\LoginRequest, which authenticates
 * tenant users on the `web` guard. The two must not share code paths: this
 * one may only ever touch the `platform` guard.
 */
class LoginRequest extends FormRequest
{
    /** Spec §18: five attempts per fifteen minutes. */
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 900;

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
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::guard('platform')->attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        /*
         * A deactivated admin has valid credentials but no access. Checking
         * after the attempt rather than folding `is_active` into the
         * credentials lets us say why — worth the disclosure here, because
         * the panel has no self-registration and an admin locked out with
         * "these credentials do not match" generates a support ticket.
         */
        if (! Auth::guard('platform')->user()->isActive()) {
            Auth::guard('platform')->logout();

            RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);

            throw ValidationException::withMessages([
                'email' => 'This administrator account has been deactivated.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
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

    /** Keyed on address + IP, and namespaced so it cannot collide with tenant logins. */
    protected function throttleKey(): string
    {
        return 'platform|'.Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
