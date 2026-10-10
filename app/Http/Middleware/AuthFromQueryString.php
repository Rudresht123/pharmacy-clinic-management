<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a plain browser address bar carry what a header normally would.
 *
 * `X-Organization` and `Authorization: Bearer` are how this API is always
 * reached — by the dashboard's session, or by the app's own client, which
 * sets them in code. A GET typed straight into Chrome can set neither, so
 * this lifts `?org=` and `?token=` into those same headers before anything
 * downstream reads them.
 *
 * ONLY on the one-click test-push link. Putting a token in a URL means it
 * sits in browser history and server access logs in plain text, which is
 * fine for a disposable one tied to nothing but "push one notification to
 * yourself" — never attached to a route that does anything else.
 */
class AuthFromQueryString
{
    public function handle(Request $request, Closure $next): Response
    {
        $org = $request->query('org');

        if ($org !== null && $request->header(ResolveTenantFromHeader::HEADER) === null) {
            $request->headers->set(ResolveTenantFromHeader::HEADER, (string) $org);
        }

        $token = $request->query('token');

        if ($token !== null && $request->bearerToken() === null) {
            $request->headers->set('Authorization', 'Bearer '.$token);
        }

        return $next($request);
    }
}
