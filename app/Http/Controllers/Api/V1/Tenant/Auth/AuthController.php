<?php

namespace App\Http\Controllers\Api\V1\Tenant\Auth;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Tenant\Auth\LoginRequest;
use App\Http\Resources\Tenant\UserResource;
use App\Models\Tenant\User as TenantUser;
use App\Services\Tenant\SessionPayload;
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
    public function login(LoginRequest $request, SessionPayload $session): JsonResponse
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
            $session->for($user, $organization),
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
    public function token(LoginRequest $request, SessionPayload $session): JsonResponse
    {
        [$organization, $user] = $request->authenticateStateless();

        // One token per device name, replaced on each sign-in — see
        // SessionPayload::withToken, which the patient sign-in shares.
        return $this->ok(
            $session->withToken($user, $organization, $request->input('device_name'), $request->ip()),
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

    public function me(Request $request, SessionPayload $session): JsonResponse
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

        return $this->ok($session->for($user, $organization));
    }
}
