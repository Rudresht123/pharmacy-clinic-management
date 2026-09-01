<?php

namespace App\Http\Middleware;

use App\Models\Platform\Organization;
use App\Services\Tenancy\TenantConnectionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Points the shared tenant connection at whichever organization this
 * session's login resolved, before `auth:web` tries to look up the session
 * user — without this, that lookup would run against whatever database the
 * connection last happened to be pointed at (or none at all).
 *
 * Re-checks the organization can still sign in on every request, not just
 * at login: an org suspended after a session was issued must not keep
 * working just because the cookie is still valid.
 */
class ResolveTenantFromSession
{
    public function __construct(
        private readonly TenantConnectionService $tenants,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $uuid = $request->session()->get('tenant_organization_uuid');

        if ($uuid) {
            $organization = Organization::where('uuid', $uuid)->first();

            if (! $organization || ! $organization->canSignIn()) {
                Auth::guard('web')->logout();
                $request->session()->forget('tenant_organization_uuid');

                return $next($request);
            }

            $this->tenants->connect($organization->database_name);

            // AuthController::me() needs this same row to answer with
            // organization details — stashed here so it isn't queried twice.
            $request->attributes->set('tenant.organization', $organization);
        }

        return $next($request);
    }
}
