<?php

namespace App\Http\Middleware;

use App\Models\Tenant\User as TenantUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes a token-authenticated tenant user visible on the `web` guard.
 *
 * The tenant side asks one question in many places — who is signed in — and it
 * asks it of `Auth::guard('web')`: the owner gate, branch access, staff scope,
 * history attribution, a dozen form requests. That was the only way in until
 * the mobile app, which authenticates on `tenant-api` with a bearer token.
 * Behind `auth:web,tenant-api` the request is authenticated, but every one of
 * those call sites still asked the session guard, found nobody, and refused —
 * so a phone signed in as the owner was told it was not the owner.
 *
 * Rewriting fifteen call sites to ask two guards would fix today's and miss
 * tomorrow's. This fixes the answer instead: once the token guard has
 * authenticated somebody, the session guard is told who for the rest of this
 * request.
 *
 * `setUser()` holds the user in memory only. It writes no session and sets no
 * cookie, so nothing about it outlives the request, and a phone never acquires
 * a browser session by the back door.
 *
 * Declared on the route group straight after `auth:` and deliberately NOT in
 * the middleware priority list — anything listed there is hoisted above
 * `Authenticate` and would run before there is a user to adopt.
 */
class AdoptTokenUser
{
    public function handle(Request $request, Closure $next): Response
    {
        // A browser request is already answered by the session; leave it be.
        if (Auth::guard('web')->user() === null) {
            $user = Auth::guard('tenant-api')->user();

            // Only a tenant user. The token table is tenant-side, so anything
            // else here would be a misconfiguration, and adopting it would hand
            // the tenant gates a user of the wrong kind.
            if ($user instanceof TenantUser) {
                Auth::guard('web')->setUser($user);
            }
        }

        return $next($request);
    }
}
