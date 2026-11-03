<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\User as TenantUser;
use App\Services\Tenancy\TenantConnectionService;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

/**
 * Coming back after the session has ended, holding a "remember me" cookie.
 *
 * Sessions last half an hour and that cookie lasts years, so this is what
 * happens to anybody who ticked the box and went for lunch. It used to answer
 * every request with a 500, signing in included, because the guard tried the
 * cookie with no tenant database connected.
 */
class RememberMeTest extends TenantTestCase
{
    use RefreshDatabase;

    private const TOKEN = 'a-remember-token-of-a-believable-length-for-this-test-000000';

    private function cookieName(): string
    {
        return 'remember_web_'.sha1(SessionGuard::class);
    }

    /** The cookie this person's browser holds, as the guard would have written it. */
    private function cookieFor(Organization $organization, string $email): string
    {
        return $this->onTenant($organization, function () use ($email) {
            $user = TenantUser::on(TenantConnectionService::CONNECTION)->where('email', $email)->firstOrFail();

            $user->forceFill(['remember_token' => self::TOKEN])->save();

            return $user->id.'|'.self::TOKEN.'|'.$user->getAuthPassword();
        });
    }

    private function url(Organization $organization, string $path): string
    {
        return 'http://'.$organization->subdomain.'.'.config('organization.main_domain').'/api/v1/tenant/'.$path;
    }

    /** What a session running out leaves behind: nothing but the cookie. */
    private function sessionEnds(): void
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        (new TenantConnectionService)->disconnect();
    }

    public function test_the_cookie_signs_somebody_back_in_after_the_session_ends(): void
    {
        $organization = $this->provisionOrganization('R');
        $cookie = $this->cookieFor($organization, self::STAFF_EMAIL);

        $this->sessionEnds();

        $this->withCredentials()->withCookie($this->cookieName(), $cookie)
            ->getJson($this->url($organization, 'auth/me'))
            ->assertOk()
            ->assertJsonPath('data.user.email', self::STAFF_EMAIL);

        // It carries on from a session now, with no cookie needed.
        $this->assertSame($organization->uuid, session('tenant_organization_uuid'));
    }

    /** The account was deactivated while they were away. */
    public function test_the_cookie_does_not_bring_back_a_deactivated_account(): void
    {
        $organization = $this->provisionOrganization('R');
        $cookie = $this->cookieFor($organization, self::STAFF_EMAIL);

        $this->onTenant($organization, fn () => TenantUser::on(TenantConnectionService::CONNECTION)
            ->where('email', self::STAFF_EMAIL)
            ->update(['is_active' => false]));

        $this->sessionEnds();

        $this->withCredentials()->withCookie($this->cookieName(), $cookie)
            ->getJson($this->url($organization, 'auth/me'))
            ->assertUnauthorized();
    }

    public function test_the_cookie_does_not_outlive_a_suspended_organization(): void
    {
        $organization = $this->provisionOrganization('R');
        $cookie = $this->cookieFor($organization, self::STAFF_EMAIL);

        $organization->forceFill(['status' => Organization::SUSPENDED])->save();

        $this->sessionEnds();

        $this->withCredentials()->withCookie($this->cookieName(), $cookie)
            ->getJson($this->url($organization, 'auth/me'))
            ->assertUnauthorized();
    }

    /** The host chooses a database and proves nothing: the token has to be in it. */
    public function test_the_cookie_is_worthless_on_another_organizations_subdomain(): void
    {
        $mine = $this->provisionOrganization('R');
        $theirs = $this->provisionOrganization('S');
        $cookie = $this->cookieFor($mine, self::STAFF_EMAIL);

        $this->sessionEnds();

        $this->withCredentials()->withCookie($this->cookieName(), $cookie)
            ->getJson($this->url($theirs, 'auth/me'))
            ->assertUnauthorized();
    }

    /** A password reset rotates the token, which is what makes the cookie stale. */
    public function test_a_stale_cookie_is_signed_out_rather_than_an_error(): void
    {
        $organization = $this->provisionOrganization('R');

        $this->sessionEnds();

        $this->withCredentials()->withCookie($this->cookieName(), '1|not-the-token|not-the-hash')
            ->getJson($this->url($organization, 'auth/me'))
            ->assertUnauthorized();
    }

    /** The lock-out itself: signing in hit the same crash, so there was no way back. */
    public function test_somebody_holding_a_stale_cookie_can_still_sign_in(): void
    {
        $organization = $this->provisionOrganization('R');

        $this->sessionEnds();

        $this->withCredentials()->withCookie($this->cookieName(), '1|not-the-token|not-the-hash')
            ->postJson($this->url($organization, 'auth/login'), [
                'subdomain' => $organization->subdomain,
                'email' => self::STAFF_EMAIL,
                'password' => self::PASSWORD,
            ])
            ->assertOk();
    }

    /** No organization on this host, so nothing to check the cookie against. */
    public function test_a_host_that_is_no_organization_is_signed_out_rather_than_an_error(): void
    {
        $this->sessionEnds();

        $this->withCredentials()->withCookie($this->cookieName(), '1|not-the-token|not-the-hash')
            ->getJson('/api/v1/tenant/auth/me')
            ->assertUnauthorized();
    }
}
