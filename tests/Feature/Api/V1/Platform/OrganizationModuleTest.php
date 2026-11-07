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
 * things the rest of the system will trust: nothing is included by default,
 * a binding is only live when it is enabled AND started AND unexpired, and the
 * capability pool follows the entitlements rather than the catalogue.
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

    /**
     * A full replacement, with what Appointments runs on added underneath it,
     * since most of these tests are about Appointments and not about that.
     */
    private function bind(Organization $organization, array $modules): void
    {
        $this->actingAsAdmin()
            ->putJson("/api/v1/admin/organizations/{$organization->uuid}/modules", [
                'modules' => [
                    ['key' => 'customers', 'is_enabled' => true],
                    ['key' => 'settings', 'is_enabled' => true],
                    ...$modules,
                ],
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

        // Nothing has been granted yet, and that goes for every module.
        $this->assertSame(['unbound'], $states->unique()->values()->all());
        $this->assertFalse(collect($body['modules'])->contains('is_live', true));
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

        // A full replacement: what is left out goes.
        $this->bind($organization, [
            ['key' => 'appointments', 'is_enabled' => true],
            ['key' => 'medicines', 'is_enabled' => true],
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
            ['customers', 'settings', 'appointments'],
            app(ModuleAccess::class)->enabled($organization),
        );
    }

    /** Every module is granted, the foundation ones too. */
    public function test_a_foundation_module_can_be_granted_and_taken_back(): void
    {
        $organization = $this->organization();
        $access = app(ModuleAccess::class);

        $this->actingAsAdmin()
            ->putJson("/api/v1/admin/organizations/{$organization->uuid}/modules", [
                'modules' => [['key' => 'branches', 'is_enabled' => true]],
            ])
            ->assertOk();

        $this->assertTrue($access->has($organization, 'branches'));

        $this->actingAsAdmin()
            ->putJson("/api/v1/admin/organizations/{$organization->uuid}/modules", ['modules' => []])
            ->assertOk();

        $this->assertFalse($access->has($organization, 'branches'));
    }

    /** The whole point: an organization that was given nothing has nothing. */
    public function test_nothing_is_available_until_it_is_granted(): void
    {
        $organization = $this->organization();
        $access = app(ModuleAccess::class);

        $this->assertSame([], $access->enabled($organization));
        $this->assertSame([], $access->capabilities($organization));

        foreach (ModuleRegistry::keys() as $key) {
            $this->assertFalse($access->has($organization, $key), $key);
        }
    }

    /** A module that leans on a foundation one now needs it actually granted. */
    public function test_a_module_cannot_be_granted_without_the_foundation_it_runs_on(): void
    {
        $organization = $this->organization();

        $this->actingAsAdmin()
            ->putJson("/api/v1/admin/organizations/{$organization->uuid}/modules", [
                'modules' => [['key' => 'documents', 'is_enabled' => true]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.modules.0', 'Patient documents needs Customers & Patients. Switch it on too, or switch Patient documents off.');

        $this->actingAsAdmin()
            ->putJson("/api/v1/admin/organizations/{$organization->uuid}/modules", [
                'modules' => [['key' => 'appointments', 'is_enabled' => true]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.modules.0', 'Appointments needs Customers & Patients and Configuration. Switch them on too, or switch Appointments off.');

        $this->assertDatabaseMissing('organization_modules', ['organization_id' => $organization->id]);
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

        $this->assertSame([], $access->capabilities($organization));

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

        $this->bind($organization, [
            ['key' => 'medicines', 'is_enabled' => true],
            ['key' => 'prescriptions', 'is_enabled' => true],
        ]);
        $this->assertContains('prescriptions.write', $access->capabilities($organization->fresh()));

        // Switching off the dependent module needs nothing; medicines stays.
        $this->bind($organization, [
            ['key' => 'medicines', 'is_enabled' => true],
            ['key' => 'prescriptions', 'is_enabled' => false],
        ]);
        $this->assertNotContains('prescriptions.write', $access->capabilities($organization->fresh()));
        $this->assertContains('medicines.view', $access->capabilities($organization->fresh()));
    }

    /**
     * A module cannot be sold without the one it runs on. Refused whole:
     * nothing from the submission is bound.
     */
    public function test_a_module_cannot_be_bound_without_what_it_requires(): void
    {
        $organization = $this->organization();

        foreach (['pharmacy' => 'Pharmacy'] as $key => $name) {
            $this->actingAsAdmin()
                ->putJson("/api/v1/admin/organizations/{$organization->uuid}/modules", [
                    'modules' => [
                        ['key' => 'customers', 'is_enabled' => true],
                        ['key' => 'settings', 'is_enabled' => true],
                        ['key' => 'appointments', 'is_enabled' => true],
                        ['key' => $key, 'is_enabled' => true],
                    ],
                ])
                ->assertStatus(422)
                ->assertJsonPath('errors.modules.0', "{$name} needs Medicines. Switch it on too, or switch {$name} off.");
        }

        $this->assertDatabaseMissing('organization_modules', ['organization_id' => $organization->id]);
    }

    /** A clinic with no catalogue types its medicines, so Prescriptions stands alone. */
    public function test_prescriptions_can_be_granted_without_medicines_or_a_pharmacy(): void
    {
        $organization = $this->organization();

        $this->actingAsAdmin()
            ->putJson("/api/v1/admin/organizations/{$organization->uuid}/modules", [
                'modules' => [['key' => 'prescriptions', 'is_enabled' => true]],
            ])
            ->assertOk();

        $this->assertTrue(app(ModuleAccess::class)->has($organization, 'prescriptions'));
    }

    /**
     * A diagnostic lab takes walk-ins and referrals and has no OPD, so the
     * laboratory needs the patient register and nothing else (decided
     * 2026-11-07, docs/architecture/laboratory.md D4).
     */
    public function test_the_laboratory_can_be_granted_with_patients_alone(): void
    {
        $organization = $this->organization();

        $refused = $this->actingAsAdmin()
            ->putJson("/api/v1/admin/organizations/{$organization->uuid}/modules", [
                'modules' => [['key' => 'laboratory', 'is_enabled' => true]],
            ])
            ->assertStatus(422);

        $this->assertStringContainsString('Laboratory needs Customers & Patients', (string) $refused->json('errors.modules.0'));

        $this->bind($organization, [['key' => 'laboratory', 'is_enabled' => true]]);

        $access = app(ModuleAccess::class);
        $this->assertTrue($access->has($organization->fresh(), 'laboratory'));
        $this->assertFalse($access->has($organization->fresh(), 'appointments'));
    }

    public function test_a_module_binds_once_what_it_requires_is_bound_with_it(): void
    {
        $organization = $this->organization();
        $access = app(ModuleAccess::class);

        $this->bind($organization, [
            ['key' => 'medicines', 'is_enabled' => true],
            ['key' => 'prescriptions', 'is_enabled' => true],
            ['key' => 'pharmacy', 'is_enabled' => true],
        ]);

        $capabilities = $access->capabilities($organization->fresh());

        foreach (['medicines.manage', 'prescriptions.cancel', 'pharmacy.dispense'] as $capability) {
            $this->assertContains($capability, $capabilities);
        }
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

        // Counted the same way as everything else: nobody here was given it.
        $this->assertSame(0, $modules['branches']['organizations']);
    }

    /** A subscription ending inside the month is worth chasing. */
    public function test_the_catalogue_flags_subscriptions_ending_soon(): void
    {
        $soon = $this->organization();
        $later = $this->organization();

        $this->bind($soon, [
            ['key' => 'medicines', 'is_enabled' => true],
            [
                'key' => 'prescriptions',
                'is_enabled' => true,
                'expires_at' => now()->addDays(10)->toDateString(),
            ],
        ]);

        $this->bind($later, [
            ['key' => 'medicines', 'is_enabled' => true],
            [
                'key' => 'prescriptions',
                'is_enabled' => true,
                'expires_at' => now()->addMonths(6)->toDateString(),
            ],
        ]);

        $modules = collect(
            $this->actingAsAdmin()->getJson('/api/v1/admin/modules')->assertOk()->json('data.modules')
        )->keyBy('key');

        $this->assertSame(2, $modules['prescriptions']['organizations']);
        $this->assertSame(1, $modules['prescriptions']['expiring_soon']);
    }

    /** The assign list counts what an organization can actually use. */
    public function test_the_assign_list_counts_live_modules(): void
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

        // Customers, Configuration and Appointments are live; Prescriptions is off.
        $this->assertSame(3, $row['modules']);

        $this->assertSame(
            count(app(ModuleAccess::class)->capabilities($organization->fresh())),
            $row['capabilities'],
        );
    }

    /** An organization with nothing granted still lists, reading as none. */
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

        $this->assertDatabaseHas('modules', ['key' => 'branches', 'is_active' => true]);
    }
}
