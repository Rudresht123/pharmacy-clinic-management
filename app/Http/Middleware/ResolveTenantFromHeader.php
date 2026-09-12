<?php

namespace App\Http\Middleware;

use App\Models\Platform\Organization;
use App\Services\Tenancy\TenantConnectionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Points the shared tenant connection at the organization named by the
 * `X-Organization` header, before the token guard looks a token up.
 *
 * The sibling of ResolveTenantFromSession, for clients that have no session.
 * A browser arrives on `{subdomain}.{main_domain}` and carries a cookie; a
 * phone has neither, so it says which organization it is talking to on every
 * request and proves it with a token *from that organization's database*.
 *
 * The header is not trusted for anything but choosing a database. It names a
 * clinic, which is public information — the same thing the workspace lookup
 * hands out. Authentication happens afterwards, against the tokens in that
 * database, so pointing the header at another organization gets you that
 * organization's token table, where your token does not exist.
 *
 * The lifecycle check runs on every request, not just at sign-in: an
 * organization suspended after a token was issued must stop working
 * immediately, not whenever the token happens to expire.
 */
class ResolveTenantFromHeader
{
    public const HEADER = 'X-Organization';

    public function __construct(
        private readonly TenantConnectionService $tenants,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $subdomain = trim((string) $request->header(self::HEADER));

        $organization = $subdomain === ''
            ? null
            : Organization::where('subdomain', mb_strtolower($subdomain))->first();

        if (! $organization || ! $organization->canSignIn()) {
            /*
             * A token with no organization behind it is refused here, not
             * left for the guard.
             *
             * Letting it through would mean the token being looked up in
             * whatever database the connection happens to be pointing at. On
             * php-fpm that is nothing, and the request fails by accident. On a
             * long-lived worker -- Octane, a queue, anything that serves two
             * requests in one process -- it could be the *previous* request's
             * tenant, and a token would authenticate against a database its
             * owner never named. Correct behaviour must not depend on which of
             * those is running.
             *
             * Only when a token was actually presented. A browser reaches these
             * same routes with a cookie and no header, and must fall through to
             * the session path untouched.
             */
            if ($request->bearerToken()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            return $next($request);
        }

        $this->tenants->connect($organization->database_name);

        // AuthController::me() answers with organization details from this
        // same row -- stashed so it isn't queried twice, exactly as the
        // session middleware does.
        $request->attributes->set('tenant.organization', $organization);

        return $next($request);
    }
}
