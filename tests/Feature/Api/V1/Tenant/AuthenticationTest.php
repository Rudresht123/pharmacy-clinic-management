<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Http\Middleware\ResolveTenantFromSession;
use App\Models\Platform\Organization;
use App\Models\Platform\OrganizationType;
use App\Models\Platform\PlatformRole;
use App\Models\Platform\PlatformUser;
use App\Models\Tenant\User as TenantUser;
use App\Repositories\Platform\Contracts\OrganizationRepositoryInterface;
use App\Services\Tenancy\DatabaseService;
use App\Services\Tenancy\TenantConnectionService;
use App\Support\Platform\OrganizationCode;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The first tenant-facing login this codebase has ever had. Runs against a
 * real, fully-provisioned tenant database (same infrastructure as
 * OrganizationProvisioningTest), since there is no other way to exercise a
 * guard whose provider model connects to a different physical database per
 * organization.
 */
class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $createdDatabases = [];

    protected function tearDown(): void
    {
        foreach ($this->createdDatabases as $databaseName) {
            (new TenantConnectionService)->disconnect();
            DatabaseService::drop($databaseName);
        }

        parent::tearDown();
    }

    /** Provisions a real organization + tenant database, with one owner user on it. */
    private function provisionOrganizationWithOwner(string $password = 'password'): Organization
    {
        $type = OrganizationType::create([
            'name' => 'Pharmacy '.uniqid(),
            'slug' => 'pharmacy-'.uniqid(),
            'is_active' => true,
        ]);

        $admin = PlatformUser::factory()->withRole(PlatformRole::SUPER_ADMIN)->create();
        $unique = uniqid();

        $organization = $this->actingAs($admin, 'platform')
            ->postJson('/api/v1/admin/organizations', [
                'organization_name' => 'Tenant Auth Test '.$unique,
                'organization_code' => OrganizationCode::generate(),
                'organization_type_id' => $type->id,
                'subdomain' => 'tenant-auth-'.$unique,
                'email' => "owner-{$unique}@example.com",
            ])->json('data');

        $organization = Organization::where('uuid', $organization['uuid'])->firstOrFail();
        $this->createdDatabases[] = $organization->database_name;

        (new TenantConnectionService)->connect($organization->database_name);

        TenantUser::on(TenantConnectionService::CONNECTION)->create([
            'name' => 'Owner',
            'email' => $organization->email,
            'password' => bcrypt($password),
            'is_active' => true,
            'role' => TenantUser::OWNER,
        ]);

        (new TenantConnectionService)->disconnect();

        return $organization;
    }

    public function test_an_owner_can_sign_in_with_the_right_subdomain(): void
    {
        $organization = $this->provisionOrganizationWithOwner();

        $this->postJson('/api/v1/tenant/auth/login', [
            'subdomain' => $organization->subdomain,
            'email' => $organization->email,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.email', $organization->email)
            ->assertJsonPath('data.user.role', TenantUser::OWNER)
            ->assertJsonPath('data.organization.name', $organization->organization_name)
            ->assertJsonPath('data.organization.subdomain', $organization->subdomain)
            ->assertJsonPath('data.organization.has_logo', false);
    }

    public function test_the_subdomain_can_be_derived_from_the_host_header_alone(): void
    {
        $organization = $this->provisionOrganizationWithOwner();

        // Symfony's Request::create() derives the host from the URI string
        // itself, overwriting any HTTP_HOST passed via headers — so the
        // desired host has to be baked into the URL, not sent as a header.
        $this->postJson("http://{$organization->subdomain}.hms.local/api/v1/tenant/auth/login", [
            'email' => $organization->email,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.email', $organization->email);
    }

    public function test_the_host_header_wins_over_a_mismatched_body_field(): void
    {
        $organization = $this->provisionOrganizationWithOwner();

        $this->postJson("http://{$organization->subdomain}.hms.local/api/v1/tenant/auth/login", [
            'subdomain' => 'some-other-clinic',
            'email' => $organization->email,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.email', $organization->email);
    }

    /**
     * The database session driver stamps sessions.user_id on every session
     * write, resolving it through the *default* guard. With that default left
     * on 'web' it loaded a tenant User over the `organization` connection, so
     * any request that had not already selected a tenant database died on
     * "relation users does not exist" — including routes with no auth
     * middleware at all, because the failure is in saving the session rather
     * than anywhere in the route.
     */
    /**
     * Declaration order on the route is not what runs. Laravel sorts gathered
     * middleware by its priority list, and Authenticate is on that list while
     * a custom middleware is not — so auth:web was hoisted above
     * ResolveTenantFromSession and looked the tenant user up before any
     * tenant database had been selected, killing every authenticated request
     * with "relation users does not exist".
     *
     * Asserted against the sorted list the router actually builds, because
     * route:list and the route definition both showed the correct order while
     * the sorted one was wrong.
     */
    #[DataProvider('tenantGuardRoutes')]
    public function test_the_tenant_is_resolved_before_the_guard_runs(string $routeName): void
    {
        $router = app('router');
        $route = $router->getRoutes()->getByName($routeName);

        $this->assertNotNull($route, "Route {$routeName} is missing.");

        $middleware = array_values(array_filter(
            $router->gatherRouteMiddleware($route),
            'is_string'
        ));

        $resolvesTenant = null;
        $touchesGuard = null;

        foreach ($middleware as $position => $name) {
            if (str_starts_with($name, ResolveTenantFromSession::class)) {
                $resolvesTenant ??= $position;
            }

            // Both Authenticate and RedirectIfAuthenticated load the user.
            if (str_starts_with($name, Authenticate::class)
                || str_starts_with($name, RedirectIfAuthenticated::class)) {
                $touchesGuard ??= $position;
            }
        }

        $this->assertNotNull($resolvesTenant, "{$routeName} never resolves a tenant.");
        $this->assertNotNull($touchesGuard, "{$routeName} does not touch the guard.");

        $this->assertLessThan(
            $touchesGuard,
            $resolvesTenant,
            "On {$routeName} the guard runs before the tenant database is selected: "
                .implode(' -> ', $middleware)
        );
    }

    /** @return array<string, array{string}> */
    public static function tenantGuardRoutes(): array
    {
        return [
            'me' => ['tenant.auth.me'],
            'login' => ['tenant.auth.login'],
        ];
    }

    public function test_the_default_guard_never_resolves_tenant_users(): void
    {
        /*
         * Illuminate\Contracts\Auth\Guard is what the framework reaches for
         * when nothing names a guard — DatabaseSessionHandler::userId() being
         * the case that bit: it stamps sessions.user_id on every session
         * write. While the default guard was 'web' that meant loading a
         * tenant User over the `organization` connection, so any request
         * without a tenant database selected (the whole admin panel, the
         * public branding endpoint) died on "relation users does not exist".
         *
         * Asserted at this level rather than over HTTP because the suite
         * cannot reproduce the failing request: it runs the array session
         * driver, and Sanctum only attaches the session middleware to
         * requests it reads as coming from the frontend.
         */
        $model = $this->app->make(Guard::class)->getProvider()->createModel();

        $this->assertNotInstanceOf(
            TenantUser::class,
            $model,
            'The default guard resolves tenant users, so every session write needs a tenant connected.'
        );

        $this->assertInstanceOf(PlatformUser::class, $model);
    }

    public function test_a_wrong_password_is_rejected_generically(): void
    {
        $organization = $this->provisionOrganizationWithOwner();

        $this->postJson('/api/v1/tenant/auth/login', [
            'subdomain' => $organization->subdomain,
            'email' => $organization->email,
            'password' => 'not-the-password',
        ])->assertStatus(422)->assertJsonPath('errors.email.0', trans('auth.failed'));

        $this->assertGuest('web');
    }

    public function test_an_unknown_subdomain_is_rejected_with_the_same_generic_message(): void
    {
        $this->postJson('/api/v1/tenant/auth/login', [
            'subdomain' => 'no-such-clinic-'.uniqid(),
            'email' => 'owner@example.com',
            'password' => 'password',
        ])->assertStatus(422)->assertJsonPath('errors.email.0', trans('auth.failed'));
    }

    public function test_a_suspended_organization_shows_the_reason(): void
    {
        $organization = $this->provisionOrganizationWithOwner();

        app(OrganizationRepositoryInterface::class)->changeStatus(
            $organization, Organization::SUSPENDED, 'Non-payment'
        );

        $this->postJson('/api/v1/tenant/auth/login', [
            'subdomain' => $organization->subdomain,
            'email' => $organization->email,
            'password' => 'password',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'This organization is suspended: Non-payment');

        $this->assertGuest('web');
    }

    public function test_me_reconnects_the_right_tenant_database_on_a_later_request(): void
    {
        $organization = $this->provisionOrganizationWithOwner();

        $this->postJson('/api/v1/tenant/auth/login', [
            'subdomain' => $organization->subdomain,
            'email' => $organization->email,
            'password' => 'password',
        ])->assertOk();

        // A fresh request, no login payload at all — only the session
        // carries which tenant this is, and ResolveTenantFromSession must
        // reconnect before auth:web can look the user up.
        $this->getJson('/api/v1/tenant/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', $organization->email)
            ->assertJsonPath('data.organization.name', $organization->organization_name);
    }

    /**
     * last_login_at is written during login, so in that request it is still
     * the Carbon instance just assigned. Only a later request reads it back
     * from Postgres — as a plain string unless the model casts it, which made
     * UserResource's ->toIso8601String() fatal on refresh but never on login.
     */
    public function test_last_login_at_survives_a_round_trip_through_the_database(): void
    {
        $organization = $this->provisionOrganizationWithOwner();

        $this->postJson('/api/v1/tenant/auth/login', [
            'subdomain' => $organization->subdomain,
            'email' => $organization->email,
            'password' => 'password',
        ])->assertOk();

        // Drop the in-memory model so /me has to hydrate it from the database.
        $this->app['auth']->forgetGuards();

        $response = $this->getJson('/api/v1/tenant/auth/me')->assertOk();

        $this->assertNotNull(
            $response->json('data.user.last_login_at'),
            'last_login_at was written at login, so it must come back here.'
        );
    }

    public function test_logout_clears_the_session(): void
    {
        $organization = $this->provisionOrganizationWithOwner();

        $this->postJson('/api/v1/tenant/auth/login', [
            'subdomain' => $organization->subdomain,
            'email' => $organization->email,
            'password' => 'password',
        ])->assertOk();

        $this->postJson('/api/v1/tenant/auth/logout')->assertOk();

        $this->getJson('/api/v1/tenant/auth/me')->assertStatus(401);
    }

    public function test_login_is_rate_limited_after_five_attempts(): void
    {
        $organization = $this->provisionOrganizationWithOwner();

        foreach (range(1, 5) as $ignored) {
            $this->postJson('/api/v1/tenant/auth/login', [
                'subdomain' => $organization->subdomain,
                'email' => $organization->email,
                'password' => 'wrong',
            ])->assertStatus(422);
        }

        $this->postJson('/api/v1/tenant/auth/login', [
            'subdomain' => $organization->subdomain,
            'email' => $organization->email,
            'password' => 'password',
        ])->assertStatus(422);

        $this->assertGuest('web');
    }
}
