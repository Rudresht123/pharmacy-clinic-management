<?php

namespace App\Http\Middleware;

use App\Models\Platform\Organization;
use App\Services\Permissions\Permission;
use App\Services\Queue\QueueDisplays;
use App\Services\Queue\QueueModule;
use App\Services\Subscriptions\EntitlementService;
use App\Services\Tenancy\TenantConnectionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opens a television by the secret in its URL.
 *
 * The sibling of ResolveTenantFromHeader, for a client with no user at all. The
 * token's public half chooses the database; its hash is then looked for in that
 * database and nowhere else, so a token from one clinic is simply not found in
 * another, and no screen can ever read a database other than its own.
 *
 * ASKED ON EVERY REQUEST, like the organization's lifecycle check: a screen
 * revoked, expired, switched off, or whose clinic no longer has the Smart Queue
 * or stopped paying, stops at its next read rather than whenever somebody unplugs
 * it.
 *
 * A token that opens nothing answers 404. One that was real answers 403 with why,
 * so the wall can say "this display has been switched off" instead of looking
 * broken; whoever sees that already held the URL.
 */
class ResolveQueueDisplay
{
    public const ATTRIBUTE = 'queue.display';

    public function __construct(
        private readonly QueueDisplays $displays,
        private readonly TenantConnectionService $tenants,
        private readonly Permission $permission,
        private readonly EntitlementService $entitlements,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->route('token');

        $organization = $this->displays->organizationFor($token);

        if (! $organization instanceof Organization || ! $organization->canSignIn()) {
            return $this->unknown();
        }

        $this->tenants->connect($organization->database_name);

        $display = $this->displays->find($token);

        if ($display === null) {
            return $this->unknown();
        }

        $refusal = $display->refusal()
            ?? (! $this->permission->hasModule($organization, QueueModule::KEY, (int) $display->location_id) ? 'unavailable' : null)
            ?? ($this->entitlements->isLocked($organization) ? 'unavailable' : null);

        if ($refusal !== null) {
            return response()->json([
                'success' => false,
                'message' => 'This display is not available.',
                'code' => 'display_'.$refusal,
            ], 403);
        }

        $request->attributes->set('tenant.organization', $organization);
        $request->attributes->set(self::ATTRIBUTE, $display);

        return $next($request);
    }

    private function unknown(): Response
    {
        return response()->json([
            'success' => false,
            'message' => 'This display link is not valid.',
            'code' => 'display_unknown',
        ], 404);
    }
}
