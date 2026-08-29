<?php

namespace App\Http\Controllers\Api\V1\Platform\Auth;

use App\Http\Controllers\Api\V1\BaseApiController;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Password handling for platform administrators.
 *
 * Uses the `platform_users` broker throughout, so tokens are issued and
 * redeemed against platform_password_resets and can never be crossed with a
 * tenant account that happens to share an email address.
 */
class PasswordController extends BaseApiController
{
    private const BROKER = 'platform_users';

    /**
     * Email a reset link. The response is identical whether or not the
     * address exists, so this cannot be used to enumerate administrators.
     */
    public function forgot(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        PasswordBroker::broker(self::BROKER)->sendResetLink($request->only('email'));

        return $this->noContent(
            'If that email address belongs to an administrator, a reset link is on its way.'
        );
    }

    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $status = PasswordBroker::broker(self::BROKER)->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($admin) use ($request) {
                $admin->forceFill([
                    'password' => Hash::make($request->password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($admin));
            }
        );

        if ($status !== PasswordBroker::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return $this->noContent('Password reset successfully. You can now sign in.');
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password:platform'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $request->user()->update([
            'password' => Hash::make($request->password),
        ]);

        return $this->noContent('Password updated successfully.');
    }

    /**
     * Re-confirm the current password — used by the lock screen and, later,
     * before destructive actions in the Danger Zone.
     */
    public function confirm(Request $request): JsonResponse
    {
        if (! Hash::check($request->input('password'), $request->user()->password)) {
            throw ValidationException::withMessages([
                'password' => __('auth.password'),
            ]);
        }

        $request->session()->put('auth.password_confirmed_at', time());

        return $this->noContent('Password confirmed.');
    }
}
