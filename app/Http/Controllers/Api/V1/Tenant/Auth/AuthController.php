<?php

namespace App\Http\Controllers\Api\V1\Tenant\Auth;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Tenant\Auth\LoginRequest;
use App\Http\Resources\Tenant\OrganizationSummaryResource;
use App\Http\Resources\Tenant\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Tenant session — the first login any organization's own staff can use.
 *
 * Every method here speaks to the `web` guard explicitly. Nothing in this
 * controller may fall back to the default guard's ambient behavior, since
 * getting the wrong tenant database connected is a data-isolation bug, not
 * just an auth bug.
 */
class AuthController extends BaseApiController
{
    public function login(LoginRequest $request): JsonResponse
    {
        $organization = $request->authenticate();

        // Fresh session id after a privilege change, so a fixated session id
        // captured before login is worthless.
        $request->session()->regenerate();

        // Every later request needs to know which tenant database this
        // session belongs to — there is no subdomain routing yet to derive
        // it from the request itself, so it travels in the session.
        $request->session()->put('tenant_organization_uuid', $organization->uuid);

        $user = Auth::guard('web')->user();

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        return $this->ok(
            [
                'user' => UserResource::make($user),
                'organization' => OrganizationSummaryResource::make($organization),
            ],
            'Signed in successfully.'
        );
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->forget('tenant_organization_uuid');
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->noContent('Signed out successfully.');
    }

    public function me(Request $request): JsonResponse
    {
        return $this->ok([
            'user' => UserResource::make($request->user()),
            'organization' => OrganizationSummaryResource::make(
                $request->attributes->get('tenant.organization')
            ),
        ]);
    }
}
