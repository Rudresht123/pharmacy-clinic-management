<?php

namespace Tests\Feature\Api\V1\Platform;

use App\Models\Platform\Module;
use App\Models\Platform\Organization;
use App\Models\Platform\OrganizationType;
use App\Models\Platform\PlatformRole;
use App\Models\Platform\PlatformUser;
use App\Services\Modules\ModuleAccess;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Module entitlements — the outer half of the permission model.
 *
 * What is asserted here is not "the endpoint returns 200" but the three
 * things the rest of the system will trust: core modules cannot be taken
 * away, a binding is only live when it is enabled AND started AND unexpired,
 * and the capability pool follows the entitlements rather than the
 * catalogue.
 */
class OrganizationModuleTest extends TestCase
{
    use RefreshDatabase;

    private PlatformUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // The catalogue the bindings point at; the same command a deploy runs.
        $this->artisan('modules:sync');

        /*
         * One administrator for the whole test, not one per call. Sanctum's
         * AuthenticateSession stamps the signed-in user's password hash into
         * the session and flushes it when the two disagree — so signing in as
         * a second, freshly created admin mid-test logs the first one out and
         * the next request answers 401.
         */
        $this->admin = PlatformUser::factory()->withRole(PlatformRole::SUPER_ADMIN)->create();
    }

    private function actingAsAdmin(): self
    {
        return $this->actingAs($this->admin, 'platform');
    }

    private function organization(): Organization
    {
        $type = OrganizationType::create([
            'name' => 'Pharmacy',
            'slug' => 'pharmacy',
            'is_active' => true,
        ]);

        return Organization::factory()->create(['organization_type_id' => $type->id]);
    }

    private function bind(Organization $organization, array $modules): void
    {
        $this->actingAsAdmin()
            ->putJson("/api/v1/admin/organizations/{$organization->uuid}/modules", [
                'modules' => $modules,
            ])
            ->assertOk();
    }

    /**
     * The catalogue comes back with this organization's standing on it,
     * joined server-side.
     */
    public function test_an_admin_sees_every_module_and_where_the_organization_stands(): void
    {
        $organization = $this->organization();

        $body = $this->actingAsAdmin()
            ->getJson("/api/v1/admin/organizations/{$organization->uuid}/modules")
            ->assertOk()
            ->json('data');

        $this->assertCount(count(ModuleRegistry::all()), $body['modules']);

        $states = collect($body['modules'])->pluck('state', 'key');

        // Nothing has been sold yet, and the core four are simply there.
        $this->assertSame('core', $states['branches']);
        $this->assertSame('unbound', $states['appointments']);
    }

    public function test_an_admin_can_bind_a_module_to_an_organization(): void
    {
        $organization = $this->organization();

        $this->bind($organization, [
            ['key' => 'appointments', 'is_enabled' => true, 'note' => 'Signed 3 Sep'],
        ]);

        $this->assertDatabaseHas('organization_modules', [
            'organization_id' => $organization->id,
            'module_id' => Module::where('key', 'appointments')->value('id'),
            'is_enabled' => true,
            'note' => 'Signed 3 Sep',
        ]);

        $this->assertTrue(app(ModuleAccess::class)->has($organization, 'appointments'));
    }

    /**
     * Unticking a module removes the arrangement rather than disabling it.
     *
     * A row left behind would carry stale dates that silently come back the
     * moment somebody re-ticks the module.
     */
    public function test_a_module_left_out_of_the_submission_is_unbound(): void
    {
        $organization = $this->organization();

        $this->bind($organization, [
            ['key' => 'appointments', 'is_enabled' => true],
            ['key' => 'prescriptions', 'is_enabled' => true],
        ]);

        $this->bind($organization, [
            ['key' => 'appointments', 'is_enabled' => true],
        ]);

        $this->assertDatabaseMissing('organization_modules', [
            'organization_id' => $organization->id,
            'module_id' => Module::where('key', 'prescriptions')->value('id'),
        ]);

        $this->assertSame(
            ['branches', 'people', 'customers', 'settings', 'appointments'],
            app(ModuleAccess::class)->enabled($organization),
        );
    }

    /** Core modules are not a commercial choice and cannot be assigned. */
    public function test_a_core_module_cannot_be_bound(): void
    {
        $organization = $this->organization();

        $this->actingAsAdmin()
            ->putJson("/api/v1/admin/organizations/{$organization->uuid}/modules", [
                'modules' => [['key' => 'branches', 'is_enabled' => true]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('modules.0.key');
    }

    /** And they are available whether or not anything has been sold. */
    public function test_core_modules_are_available_with_no_bindings_at_all(): void
    {
        $organization = $this->organization();

        $this->assertSame(
            ModuleRegistry::coreKeys(),
            app(ModuleAccess::class)->enabled($organization),
        );
    }

    /**
     * Three conditions, each failing on its own.
     *
     * Asserted separately because a screen has to be able to say which — a
     * revoked module, one that has not started, and one that has lapsed are
     * different conversations with the customer.
     */
    public function test_a_binding_is_live_only_when_enabled_started_and_unexpired(): void
    {
        $access = app(ModuleAccess::class);

        $revoked = $this->organization();
        $this->bind($revoked, [['key' => 'appointments', 'is_enabled' => false]]);
        $this->assertFalse($access->has($revoked, 'appointments'));

        $scheduled = $this->organization();
        $this->bind($scheduled, [[
            'key' => 'appointments',
            'is_enabled' => true,
            'starts_at' => now()->addWeek()->toDateString(),
        ]]);
        $this->assertFalse($access->has($scheduled, 'appointments'));

        $lapsed = $this->organization();
        $this->bind($lapsed, [[
            'key' => 'appointments',
            'is_enabled' => true,
            'expires_at' => now()->subDay()->toDateString(),
        ]]);
        $this->assertFalse($access->has($lapsed, 'appointments'));

        $live = $this->organization();
        $this->bind($live, [[
            'key' => 'appointments',
            'is_enabled' => true,
            'starts_at' => now()->subMonth()->toDateString(),
            'expires_at' => now()->addMonth()->toDateString(),
        ]]);
        $this->assertTrue($access->has($live, 'appointments'));
    }

    /**
     * A subscription paid up to today is not over this morning.
     *
     * The obvious implementation compares the expiry timestamp to now, which
     * ends the subscription at midnight on the day the customer paid for.
     */
    public function test_a_subscription_expiring_today_still_works_today(): void
    {
        $organization = $this->organization();

        $this->bind($organization, [[
            'key' => 'appointments',
            'is_enabled' => true,
            'expires_at' => now()->toDateString(),
        ]]);

        $this->assertTrue(app(ModuleAccess::class)->has($organization, 'appointments'));
    }

    /**
     * The capability pool is what the organization will hand to its own
     * roles, so it has to follow the entitlements — not the catalogue.
     */
    public function test_the_capability_pool_follows_the_entitlements(): void
    {
        $organization = $this->organization();
        $access = app(ModuleAccess::class);

        $this->assertNotContains('appointments.doctors', $access->capabilities($organization));
        $this->assertContains('branches.edit', $access->capabilities($organization));

        $this->bind($organization, [['key' => 'appointments', 'is_enabled' => true]]);

        $this->assertContains('appointments.doctors', $access->capabilities($organization->fresh()));

        // And the route-level question route permissions will ask.
        $this->assertTrue(
            $access->grantsCapability($organization->fresh(), 'appointments.doctors')
        );
        $this->assertFalse(
            $access->grantsCapability($organization->fresh(), 'prescriptions.write')
        );
    }

    /** Revoking a module takes its capabilities with it. */
    public function test_revoking_a_module_removes_its_capabilities(): void
    {
        $organization = $this->organization();
        $access = app(ModuleAccess::class);

        $this->bind($organization, [['key' => 'prescriptions', 'is_enabled' => true]]);
        $this->assertContains('prescriptions.write', $access->capabilities($organization->fresh()));

        $this->bind($organization, [['key' => 'prescriptions', 'is_enabled' => false]]);
        $this->assertNotContains('prescriptions.write', $access->capabilities($organization->fresh()));
    }

    public function test_an_end_date_before_the_start_date_is_rejected(): void
    {
        $organization = $this->organization();

        $this->actingAsAdmin()
            ->putJson("/api/v1/admin/organizations/{$organization->uuid}/modules", [
                'modules' => [[
                    'key' => 'appointments',
                    'is_enabled' => true,
                    'starts_at' => now()->addMonth()->toDateString(),
                    'expires_at' => now()->toDateString(),
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('modules.0.expires_at');
    }

    public function test_modules_are_not_readable_without_an_admin_session(): void
    {
        $organization = $this->organization();

        $this->getJson("/api/v1/admin/organizations/{$organization->uuid}/modules")
            ->assertUnauthorized();
    }

    /**
     * The catalogue screen counts organizations that can actually use a
     * module, not every row ever written.
     *
     * A revoked or lapsed binding still has a row; reporting it as adoption
     * would tell a platform administrator they have customers they do not.
     */
    public function test_the_catalogue_counts_live_entitlements_only(): void
    {
        $using = $this->organization();
        $lapsed = $this->organization();

        $this->bind($using, [['key' => 'appointments', 'is_enabled' => true]]);
        $this->bind($lapsed, [[
            'key' => 'appointments',
            'is_enabled' => true,
            'expires_at' => now()->subWeek()->toDateString(),
        ]]);

        $modules = collect(
            $this->actingAsAdmin()->getJson('/api/v1/admin/modules')->assertOk()->json('data.modules')
        )->keyBy('key');

        $this->assertSame(1, $modules['appointments']['organizations']);

        // Both are still assigned — one of them just is not usable.
        $this->assertSame(2, $modules['appointments']['assigned']);

        // Core modules have no rows at all, so a count would read as nobody.
        $this->assertNull($modules['branches']['organizations']);
    }

    /** A subscription ending inside the month is worth chasing. */
    public function test_the_catalogue_flags_subscriptions_ending_soon(): void
    {
        $soon = $this->organization();
        $later = $this->organization();

        $this->bind($soon, [[
            'key' => 'prescriptions',
            'is_enabled' => true,
            'expires_at' => now()->addDays(10)->toDateString(),
        ]]);

        $this->bind($later, [[
            'key' => 'prescriptions',
            'is_enabled' => true,
            'expires_at' => now()->addMonths(6)->toDateString(),
        ]]);

        $modules = collect(
            $this->actingAsAdmin()->getJson('/api/v1/admin/modules')->assertOk()->json('data.modules')
        )->keyBy('key');

        $this->assertSame(2, $modules['prescriptions']['organizations']);
        $this->assertSame(1, $modules['prescriptions']['expiring_soon']);
    }

    /**
     * The assign list counts what an organization can actually use.
     *
     * Core modules are excluded on purpose — every organization has them, so
     * including them would make every row read the same and the column would
     * stop distinguishing anything.
     */
    public function test_the_assign_list_counts_optional_modules_only(): void
    {
        $organization = $this->organization();

        $this->bind($organization, [
            ['key' => 'appointments', 'is_enabled' => true],
            ['key' => 'prescriptions', 'is_enabled' => false],
        ]);

        $row = collect(
            $this->actingAsAdmin()
                ->getJson('/api/v1/admin/modules/organizations')
                ->assertOk()
                ->json('data')
        )->firstWhere('uuid', $organization->uuid);

        // One live optional module, not two bound ones and not six with core.
        $this->assertSame(1, $row['modules']);

        // Core capabilities plus the appointments module's three.
        $this->assertSame(
            count(app(ModuleAccess::class)->capabilities($organization->fresh())),
            $row['capabilities'],
        );
    }

    /** An organization with nothing sold still lists, reading as core only. */
    public function test_an_organization_with_no_modules_still_appears(): void
    {
        $organization = $this->organization();

        $row = collect(
            $this->actingAsAdmin()
                ->getJson('/api/v1/admin/modules/organizations')
                ->assertOk()
                ->json('data')
        )->firstWhere('uuid', $organization->uuid);

        $this->assertNotNull($row);
        $this->assertSame(0, $row['modules']);
        $this->assertSame(0, $row['expiring_soon']);
    }

    /**
     * The catalogue is a mirror of the code registry, reconciled by the same
     * command a deploy runs — so a module added in a release cannot be
     * missing from a database that was seeded before it.
     */
    public function test_the_catalogue_syncs_from_the_registry_without_duplicating(): void
    {
        $this->artisan('modules:sync');
        $this->artisan('modules:sync');

        $this->assertSame(count(ModuleRegistry::all()), Module::count());

        $this->assertDatabaseHas('modules', [
            'key' => 'branches',
            'is_core' => true,
        ]);
    }
}
