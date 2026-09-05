<?php

namespace App\Http\Middleware;

use App\Models\Tenant\User;
use App\Services\Permissions\Permission;
use App\Services\Tenancy\TenantBranchAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Works out which branch this request is happening in.
 *
 * Somebody who works at three branches has different permissions at each, so
 * every capability check needs to know which one is meant. The client says
 * — through the branch switcher — and this is what stops that from being
 * enough on its own.
 *
 * THE BRANCH IS NEVER TRUSTED FROM THE CLIENT. A branch id arriving in a
 * header is a number. It is checked against the caller's own memberships
 * first, and a caller who is not a member there is refused before any query
 * runs. Without that, the switcher would be a way to work at somebody else's
 * branch by editing one request.
 *
 * Absent, the person's own branch is used — their primary, or their only one,
 * or null for the owner and head office, who work across the network. So a
 * client that has never heard of branch switching behaves exactly as before.
 *
 * Deliberately NOT in the middleware priority list, for the reason set out at
 * length in EnsureTenantHasModule: anything listed there is hoisted above
 * `Authenticate` and handed a request with no signed-in user. Declared ahead
 * of `module:` and `permission:` on the route group instead, which is where
 * unlisted middleware keeps its order.
 */
class ResolveActingBranch
{
    /**
     * Where the client says it is working.
     *
     * A header rather than a query parameter: it applies to every request the
     * workspace makes, and threading it through each call site is how one gets
     * forgotten.
     */
    public const HEADER = 'X-Branch-Id';

    public function __construct(
        private readonly Permission $permission,
        private readonly TenantBranchAccess $branches,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $asked = $request->header(self::HEADER) ?? $request->query('branch_id');

        if ($asked !== null && $asked !== '') {
            if (! ctype_digit((string) $asked)) {
                abort(400, 'That is not a branch.');
            }

            $branchId = (int) $asked;

            /*
             * The whole point of this middleware. Refused here rather than
             * quietly falling back to their own branch — silently answering
             * about somewhere else would be worse than an error, because the
             * reply would look correct.
             */
            if (! $this->branches->canUse($user, $branchId)) {
                abort(403, 'You do not work at that branch.');
            }

            $this->permission->actAt($branchId);
            $request->attributes->set('tenant.branch', $branchId);

            return $next($request);
        }

        /*
         * Their own: primary, or only, or null for somebody organization-wide.
         *
         * actAt(null) rather than leaving it alone, because Permission is
         * scoped per request and a scoped instance is NOT rebuilt between
         * requests inside one test. Without clearing here, a request that
         * named a branch left it set for the next one, and a test that granted
         * a module then asked `me` got the answer from before the grant.
         */
        $this->permission->actAt(null);
        $request->attributes->set('tenant.branch', $this->permission->branchFor($user));

        return $next($request);
    }
}
