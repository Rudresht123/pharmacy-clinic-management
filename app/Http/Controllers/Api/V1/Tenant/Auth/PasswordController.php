<?php

namespace App\Http\Controllers\Api\V1\Tenant\Auth;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Tenant\Auth\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\Tenant\Auth\ResetPasswordRequest;
use App\Models\Tenant\User as TenantUser;
use App\Notifications\Tenant\ResetPasswordNotification;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Password handling for a tenant's own staff.
 *
 * Uses the `users` broker, which config/auth.php now points at the shared
 * `organization` connection — the same database ForgotPasswordRequest and
 * ResetPasswordRequest have already connected by the time either method
 * below touches it, exactly as login connects it before checking credentials.
 *
 * @group Tenant > Authentication
 */
class PasswordController extends BaseApiController
{
    private const BROKER = 'users';

    /**
     * Email a reset link. The response is identical whether or not the
     * address exists, so this cannot be used to enumerate staff.
     *
     * @unauthenticated
     */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $organization = $request->resolveOrganization();

        PasswordBroker::broker(self::BROKER)->sendResetLink(
            $request->only('email'),
            function ($user, string $token) use ($organization, $request) {
                $user->notify(new ResetPasswordNotification(
                    $token,
                    $organization->subdomain,
                    $request->getScheme(),
                ));
            }
        );

        return $this->noContent(
            'If that email address belongs to a member of staff, a reset link is on its way.'
        );
    }

    /**
     * @unauthenticated
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $request->resolveOrganization();

        $status = PasswordBroker::broker(self::BROKER)->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user) use ($request) {
                $user->forceFill([
                    'password' => Hash::make($request->password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
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
        $user = $request->user();

        if (! $user instanceof TenantUser) {
            return $this->fail('Unauthenticated.', 401);
        }

        $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user->update([
            'password' => Hash::make($request->password),
        ]);

        return $this->noContent('Password updated successfully.');
    }
}
