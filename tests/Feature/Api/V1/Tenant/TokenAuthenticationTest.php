<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Http\Middleware\ResolveTenantFromHeader;
use App\Http\Middleware\ResolveTenantFromSession;
use App\Models\Platform\Organization;
use App\Models\Platform\OrganizationType;
use App\Models\Platform\PlatformRole;
use App\Models\Platform\PlatformUser;
use App\Models\Tenant\Location;
use App\Models\Tenant\PersonalAccessToken;
use App\Models\Tenant\User as TenantUser;
use App\Services\Tenancy\DatabaseService;
use App\Services\Tenancy\TenantConnectionService;
use App\Support\Platform\OrganizationCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Sign-in for clients that cannot hold a cookie.
 *
 * AuthenticationTest covers the browser's session login. This covers the
 * phone's: a token issued into the organization's own database, presented with
 * an `X-Organization` header naming which organization to look it up in.
 *
 * The header is the part worth being careful about, so most of what follows is
 * about what happens when it is wrong, missing, or pointed somewhere else.
 */
class TokenAuthenticationTest extends TestCase
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

    /** Provisions a real organization and tenant database, with one owner on it. */
    private function provisionOrganizationWithOwner(string $password = 'password'): Organization
    {
        $type = OrganizationType::create([
            'name' => 'Clinic '.uniqid(),
            'slug' => 'clinic-'.uniqid(),
            'is_active' => true,
        ]);

        $admin = PlatformUser::factory()->withRole(PlatformRole::SUPER_ADMIN)->create();
        $unique = uniqid();

        $created = $this->actingAs($admin, 'platform')
            ->postJson('/api/v1/admin/organizations', [
                'organization_name' => 'Token Auth Test '.$unique,
                'organization_code' => OrganizationCode::generate(),
                'organization_type_id' => $type->id,
                'subdomain' => 'token-auth-'.$unique,
                'email' => "owner-{$unique}@example.com",
            ])->json('data');

        $organization = Organization::where('uuid', $created['uuid'])->firstOrFail();
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

    /**
     * Forgets the resolved guards, so the next call authenticates from scratch.
     *
     * Several tests here make more than one request, and within a single test
     * they all share one container. `AuthManager` caches guard instances and a
     * guard caches the user it resolved, so without this the second request
     * would reuse the first one's answer -- and a test asserting that a revoked
     * token stops working would pass or fail for the wrong reason. Two real
     * requests never share that state.
     */
    private function asFreshRequest(): void
    {
        $this->app['auth']->forgetGuards();
    }

    private function signIn(Organization $organization, string $password = 'password'): string
    {
        return $this->postJson('/api/v1/tenant/auth/token', [
            'subdomain' => $organization->subdomain,
            'email' => $organization->email,
            'password' => $password,
            'device_name' => 'Test phone',
        ])->assertOk()->json('data.token');
    }

    public function test_signing_in_returns_a_token_and_the_session_it_belongs_to(): void
    {
        $organization = $this->provisionOrganizationWithOwner();

        $response = $this->postJson('/api/v1/tenant/auth/token', [
            'subdomain' => $organization->subdomain,
            'email' => $organization->email,
            'password' => 'password',
            'device_name' => 'Test phone',
        ])->assertOk();

        $this->assertNotEmpty($response->json('data.token'));

        // The same payload the browser login answers with, so one client is
        // never told less about its own session than the other.
        $response->assertJsonPath('data.organization.subdomain', $organization->subdomain);
        $this->assertNotNull($response->json('data.user'));
        $this->assertIsArray($response->json('data.capabilities'));
    }

    public function test_the_token_authenticates_a_later_request(): void
    {
        $organization = $this->provisionOrganizationWithOwner();
        $token = $this->signIn($organization);

        $this->asFreshRequest();

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            ResolveTenantFromHeader::HEADER => $organization->subdomain,
        ])->getJson('/api/v1/tenant/auth/me')
            ->assertOk()
            ->assertJsonPath('data.organization.subdomain', $organization->subdomain);
    }

    /**
     * Without the header there is no database connected, so there is nothing
     * to look the token up in. The request is simply unauthenticated -- the
     * same answer a made-up token gets.
     */
    public function test_a_token_without_the_organization_header_is_refused(): void
    {
        $organization = $this->provisionOrganizationWithOwner();
        $token = $this->signIn($organization);

        $this->asFreshRequest();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/tenant/auth/me')
            ->assertStatus(401);
    }

    /**
     * The test this whole design exists for.
     *
     * A token is created inside one organization's database. Presented with a
     * header naming a different organization, it is looked up in *that*
     * organization's token table -- where it does not exist. The header
     * therefore cannot be used to reach across the boundary, which is what
     * makes accepting it from the client safe at all.
     */
    public function test_a_token_from_one_organization_cannot_be_used_against_another(): void
    {
        $mine = $this->provisionOrganizationWithOwner();
        $theirs = $this->provisionOrganizationWithOwner();

        $token = $this->signIn($mine);

        $this->asFreshRequest();

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            ResolveTenantFromHeader::HEADER => $theirs->subdomain,
        ])->getJson('/api/v1/tenant/auth/me')
            ->assertStatus(401);

        // And still works against its own, so the refusal above is the header
        // doing its job rather than the token being broken.
        $this->asFreshRequest();

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            ResolveTenantFromHeader::HEADER => $mine->subdomain,
        ])->getJson('/api/v1/tenant/auth/me')->assertOk();
    }

    public function test_a_header_naming_no_organization_leaves_the_request_unauthenticated(): void
    {
        $organization = $this->provisionOrganizationWithOwner();
        $token = $this->signIn($organization);

        $this->asFreshRequest();

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            ResolveTenantFromHeader::HEADER => 'no-such-clinic-'.uniqid(),
        ])->getJson('/api/v1/tenant/auth/me')
            ->assertStatus(401);
    }

    public function test_signing_out_revokes_only_the_token_that_was_used(): void
    {
        $organization = $this->provisionOrganizationWithOwner();

        $phone = $this->signIn($organization);

        $tablet = $this->postJson('/api/v1/tenant/auth/token', [
            'subdomain' => $organization->subdomain,
            'email' => $organization->email,
            'password' => 'password',
            'device_name' => 'Front desk tablet',
        ])->assertOk()->json('data.token');

        $headers = fn (string $token): array => [
            'Authorization' => "Bearer {$token}",
            ResolveTenantFromHeader::HEADER => $organization->subdomain,
        ];

        $this->asFreshRequest();

        $this->withHeaders($headers($phone))
            ->deleteJson('/api/v1/tenant/auth/token')
            ->assertSuccessful();

        $this->asFreshRequest();

        $this->withHeaders($headers($phone))->getJson('/api/v1/tenant/auth/me')->assertStatus(401);

        $this->asFreshRequest();

        // The other device is untouched: signing out on a phone must not sign
        // the same person out of the front desk.
        $this->withHeaders($headers($tablet))->getJson('/api/v1/tenant/auth/me')->assertOk();
    }

    /**
     * Signing in twice from the same device replaces its token rather than
     * leaving the old one live, so the list of signed-in devices does not fill
     * with entries nobody can account for.
     */
    public function test_signing_in_again_from_the_same_device_replaces_its_token(): void
    {
        $organization = $this->provisionOrganizationWithOwner();

        $first = $this->signIn($organization);
        $second = $this->signIn($organization);

        $this->assertNotSame($first, $second);

        $headers = fn (string $token): array => [
            'Authorization' => "Bearer {$token}",
            ResolveTenantFromHeader::HEADER => $organization->subdomain,
        ];

        $this->asFreshRequest();
        $this->withHeaders($headers($first))->getJson('/api/v1/tenant/auth/me')->assertStatus(401);

        $this->asFreshRequest();
        $this->withHeaders($headers($second))->getJson('/api/v1/tenant/auth/me')->assertOk();
    }

    public function test_a_wrong_password_is_refused_without_a_token(): void
    {
        $organization = $this->provisionOrganizationWithOwner();

        $this->postJson('/api/v1/tenant/auth/token', [
            'subdomain' => $organization->subdomain,
            'email' => $organization->email,
            'password' => 'not-the-password',
        ])->assertStatus(422);
    }

    public function test_a_deactivated_user_cannot_get_a_token(): void
    {
        $organization = $this->provisionOrganizationWithOwner();

        (new TenantConnectionService)->connect($organization->database_name);

        TenantUser::on(TenantConnectionService::CONNECTION)
            ->where('email', $organization->email)
            ->update(['is_active' => false]);

        (new TenantConnectionService)->disconnect();

        $this->postJson('/api/v1/tenant/auth/token', [
            'subdomain' => $organization->subdomain,
            'email' => $organization->email,
            'password' => 'password',
        ])->assertStatus(422);
    }

    /** One branch in the organization's database, for routes that need a real id. */
    private function createLocation(Organization $organization): Location
    {
        (new TenantConnectionService)->connect($organization->database_name);

        $location = Location::on(TenantConnectionService::CONNECTION)->create([
            'name' => 'Main Street',
            'code' => 'MAIN',
            'type' => Location::CLINIC,
            'is_active' => true,
        ]);

        (new TenantConnectionService)->disconnect();

        return $location;
    }

    /**
     * The gates behind the token, not only the guard in front of it.
     *
     * Every tenant gate — the owner check, branch access, staff scope — asks
     * the session guard who is signed in. A phone signs in on the token guard,
     * so before AdoptTokenUser an owner holding a perfectly valid token was
     * refused by the first owner-only route as "not the owner". The tests
     * above never noticed, because `me` asks neither guard by name.
     */
    public function test_an_owner_token_passes_the_owner_gate(): void
    {
        $organization = $this->provisionOrganizationWithOwner();
        $location = $this->createLocation($organization);
        $token = $this->signIn($organization);

        $this->asFreshRequest();

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            ResolveTenantFromHeader::HEADER => $organization->subdomain,
        ])->getJson("/api/v1/tenant/locations/{$location->id}/modules")
            ->assertOk();
    }

    /**
     * The bridge adopts who the token says — it never promotes anybody.
     *
     * The same owner-only route, with a staff member's token, against a real
     * location id so the refusal cannot be a 404 in disguise.
     */
    public function test_a_staff_token_is_still_refused_by_the_owner_gate(): void
    {
        $organization = $this->provisionOrganizationWithOwner();
        $location = $this->createLocation($organization);

        (new TenantConnectionService)->connect($organization->database_name);

        $email = 'staff-'.uniqid().'@example.com';

        TenantUser::on(TenantConnectionService::CONNECTION)->create([
            'name' => 'Staff member',
            'email' => $email,
            'password' => bcrypt('password'),
            'is_active' => true,
            'role' => TenantUser::STAFF,
        ]);

        (new TenantConnectionService)->disconnect();

        $token = $this->postJson('/api/v1/tenant/auth/token', [
            'subdomain' => $organization->subdomain,
            'email' => $email,
            'password' => 'password',
            'device_name' => 'Test phone',
        ])->assertOk()->json('data.token');

        $this->asFreshRequest();

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            ResolveTenantFromHeader::HEADER => $organization->subdomain,
        ])->getJson("/api/v1/tenant/locations/{$location->id}/modules")
            ->assertForbidden();
    }

    /**
     * A phone has no session, and the session resolver must not assume one.
     *
     * Every tenant route runs ResolveTenantFromSession first, and it read
     * `$request->session()` unconditionally. That throws when no session was
     * started, which is exactly what a bearer-token request from a device
     * looks like. The feature tests above never saw it, because their
     * requests arrive with a session. The phone got a 500 on every
     * authenticated call. Driven directly, so the request really has none.
     */
    public function test_the_session_resolver_passes_a_request_with_no_session_through(): void
    {
        $request = Request::create('/api/v1/tenant/auth/me', 'GET');

        $this->assertFalse($request->hasSession());

        $response = $this->app->make(ResolveTenantFromSession::class)
            ->handle($request, fn () => response('passed through'));

        $this->assertSame('passed through', $response->getContent());
    }

    /** The token is stored hashed, so a dump of the table does not let anyone in. */
    public function test_the_plaintext_token_is_never_stored(): void
    {
        $organization = $this->provisionOrganizationWithOwner();
        $token = $this->signIn($organization);

        (new TenantConnectionService)->connect($organization->database_name);

        $stored = PersonalAccessToken::on(TenantConnectionService::CONNECTION)
            ->pluck('token')
            ->all();

        (new TenantConnectionService)->disconnect();

        $this->assertNotEmpty($stored);

        foreach ($stored as $value) {
            $this->assertStringNotContainsString($value, $token);
            $this->assertNotSame($token, $value);
        }
    }
}
