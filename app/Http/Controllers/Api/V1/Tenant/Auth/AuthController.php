<?php

namespace App\Http\Controllers\Api\V1\Tenant\Auth;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Tenant\Auth\LoginRequest;
use App\Http\Resources\Tenant\OrganizationSummaryResource;
use App\Http\Resources\Tenant\UserResource;
use App\Models\Platform\Organization;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\User as TenantUser;
use App\Services\Permissions\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Tenant session — the first login any organization's own staff can use.
 *
 * Every method here speaks to the `web` guard explicitly. Nothing in this
 * controller may fall back to the default guard's ambient behavior, since
 * getting the wrong tenant database connected is a data-isolation bug, not
 * just an auth bug.
 */
class AuthController extends BaseApiController
{
    public function login(LoginRequest $request, Permission $permission): JsonResponse
    {
        $organization = $request->authenticate();

        // Fresh session id after a privilege change, so a fixated session id
        // captured before login is worthless.
        $request->session()->regenerate();

        // Every later request needs to know which tenant database this
        // session belongs to — there is no subdomain routing yet to derive
        // it from the request itself, so it travels in the session.
        $request->session()->put('tenant_organization_uuid', $organization->uuid);

        $user = Auth::guard('web')->user();

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        /*
         * The same shape `me()` answers with, from the same method.
         *
         * Login used to return only the user and the organization, which was
         * fine while the sidebar was built from the role alone. The moment it
         * became capability-driven, signing in left the client with an empty
         * capability list — so the menu showed only what needs no capability
         * (the dashboard, and the owner-gated Roles entry) until a hard
         * refresh ran `me()` and filled it in.
         *
         * Two endpoints describing one session had drifted apart, so they now
         * share one method rather than agreeing by inspection.
         */
        return $this->ok(
            $this->session($user, $organization, $permission),
            'Signed in successfully.'
        );
    }

    /**
     * Sign in and get a token, for clients that cannot hold a session.
     *
     * The browser SPA uses [login] and a cookie. A phone is launched from an
     * icon with no cookie and no subdomain, so it carries a token instead and
     * names its organization in an `X-Organization` header on every later
     * request. The token is created in that organization's own database, which
     * is what makes the header safe to accept: pointed at another
     * organization, it finds that organization's token table, where this token
     * does not exist.
     *
     * The credential check, the rate limit and the lifecycle rules are the same
     * ones [login] uses -- LoginRequest owns both paths so they cannot drift.
     * The only difference is that no session is written.
     */
    public function token(LoginRequest $request, Permission $permission): JsonResponse
    {
        [$organization, $user] = $request->authenticateStateless();

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        /*
         * One token per device name, replaced on each sign-in.
         *
         * Without this a phone that signs in, is signed out by an expiry, and
         * signs in again leaves a live token behind every time -- and the list
         * of "signed-in devices" fills with entries nobody can account for.
         */
        $device = trim((string) $request->input('device_name')) ?: 'Mobile app';

        $user->tokens()->where('name', $device)->delete();

        $token = $user->createToken($device)->plainTextToken;

        return $this->ok(
            $this->session($user, $organization, $permission) + ['token' => $token],
            'Signed in successfully.'
        );
    }

    /**
     * Give up the token this request arrived with.
     *
     * Only that one. Signing out on a phone must not sign the same person out
     * of the front desk's tablet.
     */
    public function revokeToken(Request $request): JsonResponse
    {
        $this->actor($request)?->currentAccessToken()?->delete();

        return $this->noContent('Signed out successfully.');
    }

    /**
     * The signed-in tenant user, from whichever guard actually holds one.
     *
     * Asked explicitly rather than through `$request->user()`, which reads the
     * *default* guard. That was harmless while `web` was the only way in; with
     * a second guard it is a bug waiting to happen, and the type check is the
     * point: a platform administrator is not a tenant user, and a tenant
     * endpoint must never answer as though they were.
     */
    private function actor(Request $request): ?TenantUser
    {
        foreach (['web', 'tenant-api'] as $guard) {
            $user = Auth::guard($guard)->user();

            if ($user instanceof TenantUser) {
                return $user;
            }
        }

        return null;
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->forget('tenant_organization_uuid');
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->noContent('Signed out successfully.');
    }

    public function me(Request $request, Permission $permission): JsonResponse
    {
        $organization = $request->attributes->get('tenant.organization');
        $user = $this->actor($request);

        if (! $user) {
            return $this->fail('Unauthenticated.', 401);
        }

        if (! $organization) {
            return $this->ok([
                'user' => UserResource::make($user),
                'organization' => null,
                'modules' => [],
                'capabilities' => [],
            ]);
        }

        return $this->ok($this->session($user, $organization, $permission));
    }

    /**
     * Everything a client needs to know about the signed-in session.
     *
     * ONE method, used by both `login` and `me`. They described the same
     * session in two places and drifted: login answered without `modules` or
     * `capabilities` at all, so a freshly signed-in person saw a sidebar built
     * from an empty capability list until something forced `me` to run.
     *
     * @return array<string, mixed>
     */
    private function session(
        TenantUser $user,
        Organization $organization,
        Permission $permission,
    ): array {
        $user->loadMissing(['memberships.location', 'memberships.role']);

        return [
            'user' => UserResource::make($user),
            'organization' => OrganizationSummaryResource::make($organization),

            /*
             * Where this person may work, and which of those the answers below
             * are about. The client's branch switcher renders from this and
             * sends the choice back as X-Branch-Id — which ResolveActingBranch
             * checks against these same memberships rather than trusting.
             */
            'branches' => $user->memberships
                ->map(fn ($membership) => [
                    'id' => $membership->location_id,
                    'name' => $membership->location?->name,
                    'role' => $membership->role?->name,
                    'is_primary' => $membership->is_primary,
                ])
                ->values(),

            'active_branch' => $permission->branchFor($user),

            /*
             * Which doctor this account belongs to, when it belongs to one.
             *
             * A doctor may have no login at all — a visiting consultant who
             * never touches the system is why `doctors` is its own table — so
             * this is null for almost everybody. Where it is set, the queue
             * opens on their own list instead of the whole department, which
             * is the only thing standing between a doctor and the screen they
             * actually want.
             */
            'doctor_id' => $user->userable_type === Doctor::class
                ? (int) $user->userable_id
                : null,

            /*
             * What is running where this person works: sold to the
             * organization, and switched on at their branch. The sidebar hides
             * what is not here and EnsureTenantHasModule refuses it, from this
             * same service — the menu and the API agree by construction rather
             * than by coincidence.
             */
            'modules' => $permission->modulesAt(
                $organization,
                $permission->branchFor($user),
            ),

            /*
             * This person's own capabilities, NOT the organization's pool.
             * They differ the moment roles exist, and answering with the pool
             * would have every client believing a member of staff could do
             * everything the organization had been sold.
             */
            'capabilities' => $permission->capabilitiesFor($organization, $user),
        ];
    }
}
