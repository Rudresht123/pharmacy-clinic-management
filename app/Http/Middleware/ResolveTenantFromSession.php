<?php

namespace App\Http\Middleware;

use App\Models\Platform\Organization;
use App\Services\Tenancy\TenantConnectionService;
use App\Support\Tenancy\SubdomainResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
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
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /*
         * A phone signs in with a token and never starts a session. Its
         * organization arrives in X-Organization instead and is resolved by
         * ResolveTenantFromHeader. Asking a request that has no session for
         * one throws, and that answered every authenticated request from the
         * mobile app with a 500.
         */
        if (! $request->hasSession()) {
            return $next($request);
        }

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

            return $next($request);
        }

        $this->honourRememberCookie($request);

        return $next($request);
    }

    /**
     * A session that ended while its "remember me" cookie did not.
     *
     * Sessions last half an hour and that cookie lasts years, so this is the
     * ordinary way back for anybody who ticked the box. An ended session has no
     * organization in it, but the guard tries the cookie regardless, against no
     * database at all. That answered every request with a 500, signing in
     * included, until the browser's cookies were cleared.
     *
     * The host says which organization: a browser is on its subdomain, and the
     * cookie is host-only. That only chooses a database. The guard still has to
     * find the cookie's token in that database's `users`, so another
     * organization's subdomain finds nobody.
     */
    private function honourRememberCookie(Request $request): void
    {
        $guard = Auth::guard('web');
        $cookie = $guard->getRecallerName();

        if (! $request->cookies->has($cookie)) {
            return;
        }

        $subdomain = SubdomainResolver::fromHost($request->getHost());
        $organization = $subdomain === null ? null : Organization::where('subdomain', $subdomain)->first();

        // Nothing to check it against, so it proves nothing: signed out, not an error.
        if (! $organization || ! $organization->canSignIn()) {
            $request->cookies->remove($cookie);
            Cookie::queue(Cookie::forget($cookie));

            return;
        }

        $this->tenants->connect($organization->database_name);

        $request->attributes->set('tenant.organization', $organization);

        // The guard is about to write a session, and the next request needs this in it.
        $request->session()->put('tenant_organization_uuid', $organization->uuid);

        /*
         * Signing in is the only place `is_active` is checked, and this does not
         * pass through it. Without this, deactivating somebody would stop their
         * password and leave a cookie that works for years.
         */
        $user = $guard->user();

        if ($user && ! $user->is_active) {
            $guard->logout();
        }
    }
}
