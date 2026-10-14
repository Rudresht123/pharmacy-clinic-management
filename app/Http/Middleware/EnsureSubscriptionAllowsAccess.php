<?php

namespace App\Http\Middleware;

use App\Models\Platform\Organization;
use App\Services\Subscriptions\EntitlementService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stops a locked organization at the door — every tenant and portal request,
 * not just the screens.
 *
 * Asked on the server for every protected request, from the subscription's
 * DATES (EntitlementService → SubscriptionClock), so an expiry bites at its
 * exact moment whether or not the scheduler has run, and knowing an API URL
 * is no way around it. Nothing is deleted or changed by a lock: the data is
 * all still there, waiting for the renewal.
 *
 * Locked, only this gets through:
 *   - who am I, sign out, drop this device's token — so the app can show why
 *     and let somebody leave;
 *   - the subscription screens — status, invoices, renew, pay — which check
 *     for themselves that the person is the owner or holds
 *     `settings.subscription`;
 *   - the guides.
 * Everything else answers 423 with `code: subscription_locked`, which the web
 * app and the phone turn into the "subscription expired" page.
 *
 * The patient portal keeps its READS while locked — a patient can still see
 * their own prescriptions and reports, which are theirs — and loses its
 * writes: no booking, no messaging, no calls.
 *
 * During grace, if the Super Admin has made grace read-only, writes answer
 * 423 `subscription_read_only` and reads go through.
 *
 * The platform's own administration never passes through here: the Super
 * Admin panel is its own route group, so support access is untouched.
 *
 * Left off the middleware priority list, like `module` — it has to run after
 * the tenant is resolved and the user is known.
 */
class EnsureSubscriptionAllowsAccess
{
    /** Reachable while locked, by path under /api/v1/tenant/. */
    private const OPEN_WHEN_LOCKED = [
        'auth/me',
        'auth/logout',
        'auth/token',
        'auth/password',
        'subscription',
        'subscription/*',
        'guides',
        'guides/*',
        'devices/*',
    ];

    /** The portal's own sign-out and device paths, under /api/v1/tenant/portal/. */
    private const PORTAL_OPEN_WHEN_LOCKED = [
        'devices/*',
    ];

    public function __construct(
        private readonly EntitlementService $entitlements,
    ) {}

    public function handle(Request $request, Closure $next, string $area = 'tenant'): Response
    {
        $organization = $request->attributes->get('tenant.organization');

        if (! $organization instanceof Organization) {
            return $next($request);
        }

        $reading = $request->isMethodSafe();

        if ($this->entitlements->isLocked($organization)) {
            if ($this->openWhenLocked($request, $area) || ($area === 'portal' && $reading)) {
                return $next($request);
            }

            return $this->refuse($organization, 'subscription_locked', $this->lockedMessage($organization));
        }

        if (! $reading && ! $this->entitlements->canWrite($organization) && ! $this->openWhenLocked($request, $area)) {
            return $this->refuse(
                $organization,
                'subscription_read_only',
                'Your subscription has expired and is in its grace period, read-only. Renew it to make changes again.',
            );
        }

        return $next($request);
    }

    private function openWhenLocked(Request $request, string $area): bool
    {
        $prefix = $area === 'portal' ? 'api/v1/tenant/portal/' : 'api/v1/tenant/';
        $paths = $area === 'portal' ? self::PORTAL_OPEN_WHEN_LOCKED : self::OPEN_WHEN_LOCKED;

        foreach ($paths as $path) {
            if ($request->is($prefix.$path)) {
                return true;
            }
        }

        return false;
    }

    private function lockedMessage(Organization $organization): string
    {
        $status = $this->entitlements->status($organization);

        return $status['lock_reason'] === 'billing_suspended'
            ? 'Your organization\'s subscription has been suspended. Contact us, or your administrator, to restore access.'
            : 'Your organization\'s subscription has expired. Renew it to restore access — your data is safe and nothing has been deleted.';
    }

    private function refuse(Organization $organization, string $code, string $message): Response
    {
        return response()->json([
            'message' => $message,
            'code' => $code,
            'subscription' => $this->entitlements->status($organization),
        ], 423);
    }
}
