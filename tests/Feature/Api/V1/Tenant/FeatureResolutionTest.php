<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Http\Middleware\ResolveTenantFromHeader;
use App\Models\Platform\Organization;
use App\Models\Tenant\CommunicationChannel;
use App\Models\Tenant\Location;
use App\Models\Tenant\LocationModule;
use App\Models\Tenant\OrganizationModuleSetting;
use App\Models\Tenant\User;
use App\Services\Modules\FeatureResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TenantTestCase;

/**
 * Sold, switched on for the organisation, and not switched off at the branch —
 * the formula, end to end.
 *
 * The cases that matter are the ones where the levels disagree: the
 * organisation switching off something a branch had deliberately on, a branch
 * asking for something the organisation does not run, and two organisations
 * whose switches must never meet. Each fails without the thing it tests.
 */
class FeatureResolutionTest extends TenantTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The migration seeds a frozen catalogue; the full one arrives the way
        // a deploy adds it.
        $this->artisan('modules:sync');
    }

    /**
     * An organization with Branch A and Branch B; the staff member works at A.
     *
     * @return array{0: Organization, 1: int, 2: int}
     */
    private function network(string $prefix = 'F'): array
    {
        $organization = $this->provisionOrganization($prefix);

        [$a, $b] = $this->onTenant($organization, fn () => [
            Location::on('organization')->create([
                'name' => 'Branch A', 'code' => $prefix.'-A',
                'type' => Location::CLINIC, 'is_active' => true,
            ])->id,
            Location::on('organization')->create([
                'name' => 'Branch B', 'code' => $prefix.'-B',
                'type' => Location::CLINIC, 'is_active' => true,
            ])->id,
        ]);

        $this->placeStaffAt($organization, $a);

        return [$organization, $a, $b];
    }

    /** @return array<string, bool> */
    private function features(Organization $organization, ?int $locationId): array
    {
        return $this->onTenant(
            $organization,
            fn () => app(FeatureResolver::class)->forBranch($organization, $locationId),
        );
    }

    /** @return array<string, mixed> one module's row from explain() */
    private function explained(Organization $organization, ?int $locationId, string $key): array
    {
        $rows = $this->onTenant(
            $organization,
            fn () => app(FeatureResolver::class)->explain($organization, $locationId),
        );

        return collect($rows)->firstWhere('key', $key);
    }

    private function switchForOrganization(string $key, bool $on): void
    {
        $this->putJson('/api/v1/tenant/module-settings', ['modules' => [$key => $on]])->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | The organisation's level
    |--------------------------------------------------------------------------
    */

    public function test_a_module_the_organization_bought_runs_everywhere_until_somebody_says_otherwise(): void
    {
        [$organization, $a, $b] = $this->network();

        $this->grantModule($organization, 'appointments');
        $this->setStaffCapabilities($organization, ['appointments.view']);

        foreach ([null, $a, $b] as $where) {
            $this->assertTrue($this->features($organization, $where)['appointments']);
        }

        $this->signInAsStaff($organization);

        $this->getJson('/api/v1/tenant/auth/me')
            ->assertOk()
            ->assertJsonPath('data.features.appointments', true)
            // Never sold, so off everywhere — and present as false, not absent.
            ->assertJsonPath('data.features.laboratory', false);

        $this->getJson('/api/v1/tenant/doctors')->assertOk();
    }

    public function test_switching_a_module_off_for_the_organization_turns_it_off_at_every_branch_and_for_the_owner(): void
    {
        [$organization, $a, $b] = $this->network();

        $this->grantModule($organization, 'appointments');
        $this->setStaffCapabilities($organization, ['appointments.view']);

        $this->signInAsOwner($organization);

        $this->putJson('/api/v1/tenant/module-settings', ['modules' => ['appointments' => false]])
            ->assertOk()
            ->assertJsonPath('data.modules.0.key', 'appointments')
            ->assertJsonPath('data.modules.0.is_enabled', false);

        // The owner bypasses roles, never modules: the organisation itself
        // does not run this any more.
        $this->getJson('/api/v1/tenant/doctors')->assertStatus(403);

        foreach ([null, $a, $b] as $where) {
            $this->assertFalse($this->features($organization, $where)['appointments']);
        }

        $this->signInAsStaff($organization);
        $this->getJson('/api/v1/tenant/doctors')->assertStatus(403);
        $me = $this->getJson('/api/v1/tenant/auth/me')->assertJsonPath('data.features.appointments', false);
        $this->assertNotContains('appointments', $me->json('data.modules'));

        // And back on: the row goes rather than turning true.
        $this->signInAsOwner($organization);
        $this->switchForOrganization('appointments', true);

        $this->assertSame(0, $this->onTenant($organization, fn () => OrganizationModuleSetting::count()));
        $this->getJson('/api/v1/tenant/doctors')->assertOk();
    }

    public function test_a_branch_with_no_override_inherits_the_organization(): void
    {
        [$organization, $a] = $this->network();

        $this->grantModule($organization, 'appointments');

        $row = $this->explained($organization, $a, 'appointments');
        $this->assertTrue($row['effective']);
        $this->assertNull($row['branch_override']);
        $this->assertSame(FeatureResolver::INHERITED, $row['reason']);

        $this->signInAsOwner($organization);
        $this->switchForOrganization('appointments', false);

        $row = $this->explained($organization, $a, 'appointments');
        $this->assertFalse($row['effective']);
        $this->assertNull($row['branch_override']);
        $this->assertSame(FeatureResolver::ORGANIZATION_OFF, $row['reason']);
    }

    public function test_core_modules_cannot_be_switched_off_at_either_level(): void
    {
        [$organization, $a] = $this->network();

        $this->signInAsOwner($organization);

        $this->putJson('/api/v1/tenant/module-settings', ['modules' => ['customers' => false]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('modules.customers');

        $this->putJson("/api/v1/tenant/locations/{$a}/modules", ['overrides' => ['customers' => false]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('overrides.customers');

        $this->assertTrue($this->features($organization, $a)['customers']);
    }

    public function test_the_organization_cannot_switch_off_what_another_module_needs(): void
    {
        [$organization] = $this->network();

        $this->grantModule($organization, 'medicines');
        $this->grantModule($organization, 'pharmacy');
        $this->signInAsOwner($organization);

        $this->putJson('/api/v1/tenant/module-settings', ['modules' => ['medicines' => false]])
            ->assertStatus(422)
            ->assertJsonPath('errors.modules.0', 'Pharmacy needs Medicines. Enable it too.');

        $this->assertSame(0, $this->onTenant($organization, fn () => OrganizationModuleSetting::count()));

        // Both together is consistent.
        $this->putJson('/api/v1/tenant/module-settings', [
            'modules' => ['medicines' => false, 'pharmacy' => false],
        ])->assertOk();
    }

    public function test_a_locked_module_cannot_be_switched_off_for_the_organization(): void
    {
        [$organization] = $this->network();

        $this->grantModule($organization, 'appointments');
        $this->signInAsOwner($organization);

        $this->putJson('/api/v1/tenant/module-locks', ['modules' => ['appointments']])->assertOk();

        $this->putJson('/api/v1/tenant/module-settings', ['modules' => ['appointments' => false]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('modules.appointments');
    }

    public function test_only_the_owner_decides_what_the_organization_runs(): void
    {
        [$organization] = $this->network();

        $this->grantModule($organization, 'appointments');
        $this->signInAsStaff($organization);

        $this->getJson('/api/v1/tenant/module-settings')->assertStatus(403);
        $this->putJson('/api/v1/tenant/module-settings', ['modules' => ['appointments' => false]])
            ->assertStatus(403);

        $this->assertTrue($this->features($organization, null)['appointments']);
    }

    /*
    |--------------------------------------------------------------------------
    | The branch's level
    |--------------------------------------------------------------------------
    */

    public function test_a_branch_can_switch_a_module_off_for_itself_alone(): void
    {
        [$organization, $a, $b] = $this->network();

        $this->grantModule($organization, 'appointments');
        $this->setStaffCapabilities($organization, ['appointments.view']);

        $this->signInAsOwner($organization);

        $this->putJson("/api/v1/tenant/locations/{$a}/modules", ['overrides' => ['appointments' => false]])
            ->assertOk()
            ->assertJsonPath('data.modules.0.override', false)
            ->assertJsonPath('data.modules.0.is_enabled', false)
            ->assertJsonPath('data.modules.0.reason', FeatureResolver::BRANCH_OFF);

        $this->assertFalse($this->features($organization, $a)['appointments']);
        $this->assertTrue($this->features($organization, $b)['appointments']);

        // The staff member works at A.
        $this->signInAsStaff($organization);
        $this->getJson('/api/v1/tenant/doctors')->assertStatus(403);
    }

    public function test_a_branch_can_record_a_deliberate_on_and_clear_it_again(): void
    {
        [$organization, $a] = $this->network();

        $this->grantModule($organization, 'appointments');
        $this->signInAsOwner($organization);

        $this->putJson("/api/v1/tenant/locations/{$a}/modules", ['overrides' => ['appointments' => true]])
            ->assertOk()
            ->assertJsonPath('data.modules.0.override', true)
            ->assertJsonPath('data.modules.0.is_enabled', true)
            ->assertJsonPath('data.modules.0.reason', FeatureResolver::BRANCH_ON);

        $this->assertTrue($this->onTenant($organization, fn () => LocationModule::query()
            ->where('location_id', $a)
            ->where('module_key', 'appointments')
            ->value('is_enabled')));

        // Null is "no opinion": the row goes and the branch inherits.
        $this->putJson("/api/v1/tenant/locations/{$a}/modules", ['overrides' => ['appointments' => null]])
            ->assertOk()
            ->assertJsonPath('data.modules.0.override', null)
            ->assertJsonPath('data.modules.0.reason', FeatureResolver::INHERITED);

        $this->assertSame(0, $this->onTenant($organization, fn () => LocationModule::count()));
    }

    public function test_a_branch_cannot_switch_on_a_module_the_organization_does_not_have(): void
    {
        [$organization, $a] = $this->network();

        $this->grantModule($organization, 'appointments');
        $this->signInAsOwner($organization);

        $this->putJson("/api/v1/tenant/locations/{$a}/modules", ['overrides' => ['laboratory' => true]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('overrides.laboratory');

        $this->assertSame(0, $this->onTenant($organization, fn () => LocationModule::count()));
        $this->assertFalse($this->features($organization, $a)['laboratory']);
    }

    public function test_a_branch_cannot_switch_on_a_module_the_organization_has_switched_off(): void
    {
        [$organization, $a] = $this->network();

        $this->grantModule($organization, 'appointments');
        $this->signInAsOwner($organization);
        $this->switchForOrganization('appointments', false);

        $this->putJson("/api/v1/tenant/locations/{$a}/modules", ['overrides' => ['appointments' => true]])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'overrides.appointments' => 'is switched off for your organisation, so a branch cannot switch it on.',
            ]);

        // The switch screen's own body is refused the same way.
        $this->putJson("/api/v1/tenant/locations/{$a}/modules", ['modules' => ['appointments']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('modules.0');

        $this->assertSame(0, $this->onTenant($organization, fn () => LocationModule::count()));
    }

    public function test_a_stored_branch_on_never_beats_the_organization(): void
    {
        [$organization, $a] = $this->network();

        $this->grantModule($organization, 'appointments');
        $this->setStaffCapabilities($organization, ['appointments.view']);

        // Deliberately on at A while the organisation runs it…
        $this->signInAsOwner($organization);
        $this->putJson("/api/v1/tenant/locations/{$a}/modules", ['overrides' => ['appointments' => true]])
            ->assertOk();

        // …then the organisation switches it off.
        $this->switchForOrganization('appointments', false);

        $row = $this->explained($organization, $a, 'appointments');
        $this->assertTrue($row['branch_override']);
        $this->assertFalse($row['effective']);
        $this->assertSame(FeatureResolver::ORGANIZATION_OFF, $row['reason']);

        $this->signInAsStaff($organization);
        $this->getJson('/api/v1/tenant/doctors')->assertStatus(403);
        $this->getJson('/api/v1/tenant/auth/me')->assertJsonPath('data.features.appointments', false);
    }

    /*
    |--------------------------------------------------------------------------
    | Effective features
    |--------------------------------------------------------------------------
    */

    /**
     * The example from the brief, exactly.
     *
     *   Organisation  OPD on, Pharmacy on
     *   Branch A      OPD inherit, Pharmacy on
     *   Branch B      OPD off,     Pharmacy inherit
     */
    public function test_the_worked_example_resolves_exactly(): void
    {
        [$organization, $a, $b] = $this->network();

        foreach (['appointments', 'medicines', 'pharmacy', 'communication'] as $module) {
            $this->grantModule($organization, $module);
        }

        $this->signInAsOwner($organization);

        $this->putJson("/api/v1/tenant/locations/{$a}/modules", ['overrides' => ['pharmacy' => true]])
            ->assertOk();
        $this->putJson("/api/v1/tenant/locations/{$b}/modules", ['overrides' => ['appointments' => false]])
            ->assertOk();

        $atA = $this->features($organization, $a);
        $atB = $this->features($organization, $b);

        $this->assertTrue($atA['appointments']);
        $this->assertTrue($atA['pharmacy']);
        $this->assertTrue($atA['opd_pharmacy_handoff']);

        $this->assertFalse($atB['appointments']);
        $this->assertTrue($atB['pharmacy']);
        $this->assertFalse($atB['opd_pharmacy_handoff']);

        $this->assertSame(FeatureResolver::INHERITED, $this->explained($organization, $a, 'appointments')['reason']);
        $this->assertSame(FeatureResolver::BRANCH_ON, $this->explained($organization, $a, 'pharmacy')['reason']);
        $this->assertSame(FeatureResolver::BRANCH_OFF, $this->explained($organization, $b, 'appointments')['reason']);
        $this->assertSame(FeatureResolver::INHERITED, $this->explained($organization, $b, 'pharmacy')['reason']);
    }

    public function test_the_opd_handoff_needs_both_appointments_and_pharmacy(): void
    {
        [$organization, $a] = $this->network();

        // A medical shop: pharmacy, never sold the OPD.
        $this->grantModule($organization, 'medicines');
        $this->grantModule($organization, 'pharmacy');

        $this->assertFalse($this->features($organization, null)['opd_pharmacy_handoff']);
        $this->assertFalse($this->features($organization, $a)['opd_pharmacy_handoff']);

        $this->signInAsOwner($organization);
        $this->getJson('/api/v1/tenant/auth/me')->assertJsonPath('data.features.opd_pharmacy_handoff', false);

        // Sold the OPD as well: both halves are present.
        $this->grantModule($organization, 'appointments');
        $this->assertTrue($this->features($organization, $a)['opd_pharmacy_handoff']);
        $this->getJson('/api/v1/tenant/auth/me')->assertJsonPath('data.features.opd_pharmacy_handoff', true);

        // Switching either half off, at any level, takes it away.
        $this->switchForOrganization('appointments', false);
        $this->assertFalse($this->features($organization, $a)['opd_pharmacy_handoff']);
    }

    public function test_explain_agrees_with_what_is_enforced(): void
    {
        [$organization, $a, $b] = $this->network();

        foreach (['appointments', 'medicines', 'pharmacy', 'communication'] as $module) {
            $this->grantModule($organization, $module);
        }

        $this->signInAsOwner($organization);
        $this->switchForOrganization('communication', false);
        $this->putJson("/api/v1/tenant/locations/{$a}/modules", ['overrides' => ['pharmacy' => true]])->assertOk();
        $this->putJson("/api/v1/tenant/locations/{$b}/modules", ['overrides' => ['appointments' => false]])->assertOk();

        $this->onTenant($organization, function () use ($organization, $a, $b) {
            $resolver = app(FeatureResolver::class);

            foreach ([null, $a, $b] as $where) {
                $enabled = $resolver->enabled($organization, $where);

                foreach ($resolver->explain($organization, $where) as $row) {
                    $this->assertSame(
                        in_array($row['key'], $enabled, true),
                        $row['effective'],
                        "explain() and enabled() disagree about {$row['key']} at ".($where ?? 'the organisation'),
                    );
                }
            }
        });
    }

    public function test_the_permissions_panel_names_the_organization_switch(): void
    {
        [$organization] = $this->network();

        $this->grantModule($organization, 'appointments');
        $this->setStaffCapabilities($organization, ['appointments.view']);

        $staffId = $this->onTenant($organization, fn () => User::on('organization')
            ->where('email', self::STAFF_EMAIL)
            ->value('id'));

        $this->signInAsOwner($organization);
        $this->switchForOrganization('appointments', false);

        $row = collect($this->getJson("/api/v1/tenant/users/{$staffId}/effective-permissions")
            ->assertOk()
            ->json('data.capabilities'))
            ->firstWhere('capability', 'appointments.view');

        $this->assertFalse($row['allowed']);
        $this->assertSame('organization_off', $row['source']);
    }

    public function test_channels_are_reported_inside_the_communication_module(): void
    {
        [$organization] = $this->network();

        $this->signInAsOwner($organization);

        $this->getJson('/api/v1/tenant/auth/me')
            ->assertJsonPath('data.communication.enabled', false)
            ->assertJsonPath('data.communication.channels.sms', false);

        $this->grantModule($organization, 'communication');
        $this->onTenant($organization, fn () => CommunicationChannel::forChannel(CommunicationChannel::SMS)
            ->forceFill(['is_connected' => true])
            ->save());

        $this->getJson('/api/v1/tenant/auth/me')
            ->assertJsonPath('data.features.communication', true)
            ->assertJsonPath('data.communication.enabled', true)
            ->assertJsonPath('data.communication.channels.sms', true)
            ->assertJsonPath('data.communication.channels.whatsapp', false);

        // A connected number sends nothing once the module is off.
        $this->switchForOrganization('communication', false);

        $this->getJson('/api/v1/tenant/auth/me')
            ->assertJsonPath('data.communication.enabled', false)
            ->assertJsonPath('data.communication.channels.sms', false);
    }

    /*
    |--------------------------------------------------------------------------
    | Isolation
    |--------------------------------------------------------------------------
    */

    public function test_one_organizations_switches_never_reach_another(): void
    {
        [$mine] = $this->network('M');
        [$theirs] = $this->network('N');

        $this->grantModule($mine, 'appointments');
        $this->grantModule($theirs, 'appointments');

        $this->signInAsOwner($mine);
        $this->switchForOrganization('appointments', false);

        $this->assertFalse($this->features($mine, null)['appointments']);
        $this->assertTrue($this->features($theirs, null)['appointments']);

        // My owner's token, pointed at their organisation, is a stranger there.
        $token = $this->postJson('/api/v1/tenant/auth/token', [
            'subdomain' => $mine->subdomain,
            'email' => $mine->email,
            'password' => self::PASSWORD,
            'device_name' => 'Test phone',
        ])->assertOk()->json('data.token');

        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            ResolveTenantFromHeader::HEADER => $theirs->subdomain,
        ])->putJson('/api/v1/tenant/module-settings', ['modules' => ['appointments' => false]])
            ->assertStatus(401);

        $this->assertTrue($this->features($theirs, null)['appointments']);
    }

    public function test_the_resolver_refuses_to_answer_about_an_organization_it_is_not_connected_to(): void
    {
        [$mine] = $this->network('M');
        [$theirs] = $this->network('N');

        $this->expectException(LogicException::class);

        // Connected to mine, asked about theirs: their entitlements with my
        // switches would be a plausible-looking wrong answer.
        $this->onTenant($mine, fn () => app(FeatureResolver::class)->enabled($theirs));
    }
}
