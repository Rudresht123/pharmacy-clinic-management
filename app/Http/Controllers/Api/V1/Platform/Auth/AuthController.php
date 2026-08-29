<?php

namespace App\Http\Controllers\Api\V1\Platform\Auth;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Platform\Auth\LoginRequest;
use App\Http\Resources\Platform\PlatformUserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Admin panel session — Build Spec §19 (Auth group).
 *
 * Every method here speaks to the `platform` guard explicitly. Nothing in
 * this controller may fall back to the default guard, because the default is
 * the tenant one.
 */
class AuthController extends BaseApiController
{
    public function login(LoginRequest $request): JsonResponse
    {
        $request->authenticate();

        // Fresh session id after a privilege change, so a fixated session id
        // captured before login is worthless.
        $request->session()->regenerate();

        $admin = Auth::guard('platform')->user();

        $admin->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        return $this->ok(
            PlatformUserResource::make($admin->load('roles')),
            'Signed in successfully.'
        );
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('platform')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->noContent('Signed out successfully.');
    }

    /**
     * The SPA calls this on boot to decide between the panel and the login
     * screen, and reads `roles` from it to shape the navigation.
     */
    public function me(Request $request): JsonResponse
    {
        return $this->ok(
            PlatformUserResource::make($request->user()->load('roles'))
        );
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $admin = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => [
                'required', 'string', 'email', 'max:190',
                Rule::unique('platform_users', 'email')->ignore($admin->id),
            ],
        ]);

        $admin->update($data);

        return $this->ok(
            PlatformUserResource::make($admin->fresh()->load('roles')),
            'Profile updated successfully.'
        );
    }
}
