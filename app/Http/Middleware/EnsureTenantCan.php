<?php

namespace App\Http\Middleware;

use App\Models\Tenant\User;
use App\Services\Permissions\Permission;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses an action the signed-in person's role does not hold.
 *
 * Used as `permission:customers.delete`. Level three of the flow, and it gets
 * levels one and two for free: Permission asks the organization's entitlements
 * and the branch's modules before it looks at a role, so this cannot let
 * through an action belonging to a module nobody bought.
 *
 * Sits alongside `module:` rather than replacing it. `module:appointments`
 * gates a whole section on the module alone; this gates one action on a named
 * capability. A route that has both is not redundant — the group check makes
 * the whole section disappear at once, which is a clearer failure than every
 * request inside it failing separately.
 *
 * More than one capability — `permission:pharmacy.view,reports.sales,…` — asks
 * for ANY of them, never all: a shared read like "which stores exist" is
 * behind more than one door, and whoever holds any one of the capabilities
 * behind it is not asking for anything the others do not already grant.
 *
 * The branch is the person's own. A route that acts on a branch named in the
 * request — booking into a particular clinic, say — has to check that branch
 * too, and does so in the controller where the value has been validated;
 * middleware reading an unvalidated request parameter to decide permission is
 * how a permission check gets talked out of its answer.
 *
 * Deliberately NOT in the middleware priority list, for the reason set out at
 * length in EnsureTenantHasModule.
 */
class EnsureTenantCan
{
    public function __construct(
        private readonly Permission $permission,
    ) {}

    public function handle(Request $request, Closure $next, string ...$capabilities): Response
    {
        $organization = $request->attributes->get('tenant.organization');
        $user = $request->user();

        if (! $organization || ! $user instanceof User) {
            abort(403, 'This action is not available to you.');
        }

        foreach ($capabilities as $capability) {
            if ($this->permission->allows($organization, $user, $capability)) {
                return $next($request);
            }
        }

        abort(403, 'This action is not available to you.');
    }
}
