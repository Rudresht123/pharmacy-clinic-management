<?php

namespace App\Http\Middleware;

use App\Models\Tenant\User;
use App\Services\Permissions\Permission;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a route belonging to a module that is not running here.
 *
 * Used as `module:appointments`. "Here" is two questions, and this asks both:
 * whether the organization was sold the module, and whether the branch this
 * person works at runs it. A chain may hold the appointments module and still
 * have a branch that is a plain counter, and staff there should not be looking
 * at an OPD queue.
 *
 * The organization comes from the request attribute ResolveTenantFromSession
 * already stashed, so this costs no extra query, and the decision itself is
 * Permission's — the same service the sidebar and every capability check read,
 * so a screen can never be visible for something the API would refuse.
 *
 * Deliberately NOT in the middleware priority list. Laravel hoists anything
 * listed there above unlisted middleware; putting this one in would run it
 * before `Authenticate` and hand it a request with no session resolved —
 * exactly the trap documented for EnsureTenantUserIsOwner. Left unlisted it
 * sorts to the end, which is where it belongs:
 *
 *   ResolveTenantFromSession → Authenticate:web → SubstituteBindings
 *     → EnsureTenantHasModule → EnsureTenantUserIsOwner
 */
class EnsureTenantHasModule
{
    public function __construct(
        private readonly Permission $permission,
    ) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        $organization = $request->attributes->get('tenant.organization');
        $user = $request->user();

        if (! $organization) {
            abort(403, 'This module is not enabled for your organization.');
        }

        /*
         * The branch they work at, resolved from their memberships by
         * Permission so this and the capability check cannot disagree about
         * where somebody is. Null for the owner and for head office, both of
         * whom work across the network — and nothing at branch level narrows
         * a person who is not at one counter.
         */
        $branch = $user instanceof User ? $this->permission->branchFor($user) : null;

        if (! $this->permission->hasModule($organization, $module, $branch)) {
            abort(403, $branch === null
                ? 'This module is not enabled for your organization.'
                : 'This module is not enabled at your branch.');
        }

        return $next($request);
    }
}
