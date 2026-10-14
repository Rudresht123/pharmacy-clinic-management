<?php

namespace App\Http\Middleware;

use App\Models\Platform\PlatformUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Which platform administrators may reach a route: `platform.role:billing`,
 * or several, `platform.role:billing,ops,support`.
 *
 * A Super Admin always passes — PlatformUser::hasRole() gives that role
 * everything, so a route never has to list it. Used on the billing area,
 * where reading is open to operations and support but changing a price, a
 * plan or a clinic's subscription is the billing role's.
 */
class EnsurePlatformRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user('platform');

        if (! $user instanceof PlatformUser || ! $user->hasRole(...$roles)) {
            abort(403, 'Your administrator role does not allow this.');
        }

        return $next($request);
    }
}
