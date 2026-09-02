<?php

namespace Tests;

use App\Models\Platform\Organization;
use App\Models\Platform\OrganizationType;
use App\Models\Platform\PlatformRole;
use App\Models\Platform\PlatformUser;
use App\Models\Tenant\User as TenantUser;
use App\Services\Tenancy\DatabaseService;
use App\Services\Tenancy\TenantConnectionService;

/**
 * Base class for anything exercising a real tenant database.
 *
 * Provisioning an organization over HTTP, seeding an owner and a staff
 * member, signing them in and dropping the database afterwards was repeated
 * in every tenant test. It lives here once so a change to the harness — or
 * to how provisioning works — is one edit rather than four.
 */
abstract class TenantTestCase extends TestCase
{
    /** Databases created during the test, dropped in tearDown. */
    protected array $createdDatabases = [];

    protected const PASSWORD = 'password';

    protected const STAFF_EMAIL = 'staff@example.com';

    protected function tearDown(): void
    {
        foreach ($this->createdDatabases as $databaseName) {
            (new TenantConnectionService())->disconnect();
            DatabaseService::drop($databaseName);
        }

        parent::tearDown();
    }

    /**
     * A fully provisioned organization with an owner and a staff member.
     *
     * @param  string  $prefix  distinguishes this suite's organizations
     */
    protected function provisionOrganization(string $prefix = 'T'): Organization
    {
        $type = OrganizationType::create([
            'name' => 'Pharmacy '.uniqid(),
            'slug' => 'pharmacy-'.uniqid(),
            'is_active' => true,
        ]);

        $admin = PlatformUser::factory()->withRole(PlatformRole::SUPER_ADMIN)->create();
        $unique = uniqid();

        $created = $this->actingAs($admin, 'platform')
            ->postJson('/api/v1/admin/organizations', [
                'organization_name' => "{$prefix} Test {$unique}",
                'organization_code' => strtoupper(substr($prefix, 0, 2)).$unique,
                'organization_type_id' => $type->id,
                'subdomain' => strtolower($prefix).'-'.$unique,
                'email' => "owner-{$unique}@example.com",
            ])->json('data');

        $organization = Organization::where('uuid', $created['uuid'])->firstOrFail();
        $this->createdDatabases[] = $organization->database_name;

        $this->onTenant($organization, function () use ($organization) {
            foreach ([
                [$organization->email, TenantUser::OWNER],
                [self::STAFF_EMAIL, TenantUser::STAFF],
            ] as [$email, $role]) {
                TenantUser::on(TenantConnectionService::CONNECTION)->create([
                    'name' => ucfirst($role),
                    'email' => $email,
                    'password' => bcrypt(self::PASSWORD),
                    'is_active' => true,
                    'role' => $role,
                ]);
            }
        });

        /*
         * Drop the admin's session before anyone signs in as a tenant.
         *
         * Provisioning above runs as a platform admin, and Sanctum's
         * AuthenticateSession stores that admin's password hash in the
         * session (sanctum.guard is ['platform']). One session store serves
         * both phases here, so without this the tenant inherits it and the
         * second authenticated request is met with a flushed session and a
         * 401. Production never sees this: the admin and tenant hosts have
         * separate, host-scoped session cookies.
         */
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        return $organization;
    }

    /** Runs a callback against one tenant's database, then disconnects. */
    protected function onTenant(Organization $organization, callable $callback): mixed
    {
        (new TenantConnectionService())->connect($organization->database_name);

        try {
            return $callback();
        } finally {
            (new TenantConnectionService())->disconnect();
        }
    }

    /** There is no actingAs for tenants — they sign in over HTTP. */
    protected function signIn(Organization $organization, string $email): void
    {
        $this->postJson('/api/v1/tenant/auth/login', [
            'subdomain' => $organization->subdomain,
            'email' => $email,
            'password' => self::PASSWORD,
        ])->assertOk();
    }

    protected function signInAsOwner(Organization $organization): void
    {
        $this->signIn($organization, $organization->email);
    }

    protected function signInAsStaff(Organization $organization): void
    {
        $this->signIn($organization, self::STAFF_EMAIL);
    }
}
