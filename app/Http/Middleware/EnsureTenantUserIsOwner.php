<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to an organization's owner.
 *
 * The first authorization on the tenant side; everything before this was
 * gated on being signed in at all. Kept as middleware rather than a policy
 * because that is how this codebase does authorization — every FormRequest
 * returns `authorize(): true` and the route decides.
 *
 * Deliberately NOT registered in bootstrap/app.php's middleware priority
 * list. ResolveTenantFromSession is on that list because Laravel hoists
 * Authenticate above unlisted middleware; adding this one there would hoist
 * it above the guard too and hand it a request with no user. Left unlisted
 * it sorts to the end of the stack, which is exactly where it belongs:
 *
 *   EnsureFrontendRequestsAreStateful -> ResolveTenantFromSession
 *     -> Authenticate:web -> SubstituteBindings -> EnsureTenantUserIsOwner
 *
 * One consequence of sitting after SubstituteBindings: a staff user asking
 * for a record that does not exist gets 404 rather than 403, because the
 * binding fails first.
 */
class EnsureTenantUserIsOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();

        if (! $user?->isOwner()) {
            abort(403, 'Only the organization owner can perform this action.');
        }

        return $next($request);
    }
}
