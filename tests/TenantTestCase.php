<?php

namespace Tests;

use App\Models\Platform\Module;
use App\Models\Platform\Organization;
use App\Models\Platform\OrganizationModule;
use App\Models\Platform\OrganizationType;
use App\Models\Platform\PlatformRole;
use App\Models\Platform\PlatformUser;
use App\Models\Tenant\BranchMembership;
use App\Models\Tenant\LocationModule;
use App\Models\Tenant\Role;
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
            (new TenantConnectionService)->disconnect();
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
            /*
             * The staff member gets the seeded `staff` role, exactly as the
             * People form requires one. Without it they would hold no
             * capabilities at all, and every "staff can read this" test would
             * fail for a reason that has nothing to do with what it is testing.
             */
            $staffRole = Role::on(TenantConnectionService::CONNECTION)
                ->where('slug', Role::SEEDED_STAFF)
                ->firstOrFail();

            foreach ([
                [$organization->email, TenantUser::OWNER, null],
                [self::STAFF_EMAIL, TenantUser::STAFF, $staffRole->id],
            ] as [$email, $role, $roleId]) {
                TenantUser::on(TenantConnectionService::CONNECTION)->create([
                    'name' => ucfirst($role),
                    'email' => $email,
                    'password' => bcrypt(self::PASSWORD),
                    'is_active' => true,
                    'role' => $role,
                    'role_id' => $roleId,
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
        (new TenantConnectionService)->connect($organization->database_name);

        try {
            return $callback();
        } finally {
            (new TenantConnectionService)->disconnect();
        }
    }

    /** There is no actingAs for tenants — they sign in over HTTP. */
    protected function signIn(Organization $organization, string $email): void
    {
        /*
         * Signed out first, so a test can switch from the owner to a staff
         * member. Without this the second login meets `guest:web`, which
         * redirects because somebody is already authenticated — a 302 that
         * reads like a broken route rather than the two-sessions-in-one-test
         * problem it actually is.
         */
        $this->flushSession();
        $this->app['auth']->forgetGuards();

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

    /**
     * Replace what the seeded `staff` role holds.
     *
     * The whole set, not an addition — a test that says staff hold exactly
     * these two capabilities is also saying they hold nothing else, and adding
     * to the seeded defaults would leave that half unproven.
     *
     * @param  list<string>  $capabilities
     */
    protected function setStaffCapabilities(Organization $organization, array $capabilities): void
    {
        $this->onTenant($organization, function () use ($capabilities) {
            $user = TenantUser::on(TenantConnectionService::CONNECTION)
                ->with('memberships')
                ->where('email', self::STAFF_EMAIL)
                ->firstOrFail();

            /*
             * Whichever role they actually hold, rather than the `staff` slug.
             * A role now lives either on `users.role_id` (head office) or on a
             * membership (branch staff), and hardcoding one of them made this
             * helper silently edit a role the test subject was not on.
             */
            $roleIds = collect([$user->role_id])
                ->concat($user->memberships->pluck('role_id'))
                ->filter()
                ->unique();

            foreach ($roleIds as $roleId) {
                Role::on(TenantConnectionService::CONNECTION)
                    ->findOrFail($roleId)
                    ->load('capabilities')
                    ->syncCapabilities($capabilities);
            }
        });
    }

    /** Switch a module off at one branch — level two of the permission flow. */
    protected function disableModuleAtBranch(
        Organization $organization,
        int $locationId,
        string $moduleKey,
    ): void {
        $this->onTenant($organization, function () use ($locationId, $moduleKey) {
            LocationModule::on(TenantConnectionService::CONNECTION)->updateOrCreate(
                ['location_id' => $locationId, 'module_key' => $moduleKey],
                ['is_enabled' => false],
            );
        });
    }

    /**
     * Put the seeded staff member at a branch.
     *
     * A membership, not `users.location_id` — that column is on its way out and
     * setting it decides nothing any more. Called again with another branch
     * adds a second membership rather than replacing the first, which is the
     * behaviour worth testing: somebody can work at two.
     */
    protected function placeStaffAt(Organization $organization, int $locationId): void
    {
        $this->onTenant($organization, function () use ($locationId) {
            $user = TenantUser::on(TenantConnectionService::CONNECTION)
                ->where('email', self::STAFF_EMAIL)
                ->firstOrFail();

            $branchRole = Role::on(TenantConnectionService::CONNECTION)
                ->where('slug', Role::SEEDED_STAFF)
                ->value('id');

            BranchMembership::on(TenantConnectionService::CONNECTION)->updateOrCreate(
                ['user_id' => $user->id, 'location_id' => $locationId],
                ['role_id' => $branchRole],
            );

            /*
             * The organization-scoped slot is cleared as they become branch
             * staff. Somebody's role lives in one place: on `users.role_id`
             * while they are head office, on the membership once they work
             * somewhere. Leaving both set makes them hold the same role twice —
             * which is what the members list correctly reported as two people.
             */
            $user->forceFill(['role_id' => null])->save();
        });
    }

    /** Take them off a branch — the other half of testing that scope bites. */
    protected function removeStaffFrom(Organization $organization, int $locationId): void
    {
        $this->onTenant($organization, function () use ($locationId) {
            $userId = TenantUser::on(TenantConnectionService::CONNECTION)
                ->where('email', self::STAFF_EMAIL)
                ->value('id');

            BranchMembership::on(TenantConnectionService::CONNECTION)
                ->where('user_id', $userId)
                ->where('location_id', $locationId)
                ->delete();
        });
    }

    /** The id of the seeded `staff` role, which every staff account needs. */
    protected function staffRoleId(Organization $organization): int
    {
        return (int) $this->onTenant($organization, fn () => Role::on(TenantConnectionService::CONNECTION)
            ->where('slug', Role::SEEDED_STAFF)
            ->value('id'));
    }

    /** Sell a module to the organization — level one. */
    protected function grantModule(Organization $organization, string $moduleKey): void
    {
        $module = Module::where('key', $moduleKey)->firstOrFail();

        OrganizationModule::updateOrCreate(
            ['organization_id' => $organization->id, 'module_id' => $module->id],
            ['is_enabled' => true],
        );
    }
}
