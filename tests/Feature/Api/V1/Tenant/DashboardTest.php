<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

/**
 * The workspace's first screen.
 *
 * The rule worth testing is not "it returns numbers" but that a panel the
 * caller may not see is ABSENT rather than present-and-hidden — the response
 * itself is the boundary, so a client cannot leak what it was never sent.
 */
class DashboardTest extends TenantTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');

        /*
         * Off for every test but the one below. Demo data deliberately fills
         * the panels that have nothing real behind them, which is the opposite
         * of what the rest of this file checks — that a panel somebody may not
         * see never reaches them.
         */
        config(['hms.dashboard_demo' => false]);
    }

    /**
     * Demo mode fills the empty panels, and still respects the gating.
     *
     * The flag is for showing the screen before its modules ship. It must not
     * become a way around the permission model — a panel the caller could not
     * see stays absent whether or not there are sample figures for it.
     */
    public function test_demo_mode_fills_panels_without_widening_access(): void
    {
        [$organization] = $this->network();

        config(['hms.dashboard_demo' => true]);

        $this->setStaffCapabilities($organization, ['customers.view']);
        $this->signInAsStaff($organization);

        $data = $this->getJson('/api/v1/tenant/dashboard')->assertOk()->json('data');

        /*
         * Branch demo, not the organization's: this person works at Lucknow,
         * so they get a branch's day rather than the network. Two different
         * screens, chosen by where they are standing.
         */
        $this->assertTrue($data['demo']);
        $this->assertSame('branch', $data['context']);
        $this->assertSame(124, $data['headline'][0]['total']);

        // Still absent: they hold no branches or audit capability, and a demo
        // flag is not a capability.
        $this->assertArrayNotHasKey('branches', $data);
        $this->assertArrayNotHasKey('activity', $data);
    }

    /** @return array{0: Organization, 1: int, 2: int} */
    private function network(): array
    {
        $organization = $this->provisionOrganization('D');

        [$here, $there] = $this->onTenant($organization, function () {
            $here = Location::on('organization')->create([
                'name' => 'Lucknow', 'code' => 'D-LKO',
                'type' => Location::CLINIC, 'is_active' => true,
            ]);

            $there = Location::on('organization')->create([
                'name' => 'Delhi', 'code' => 'D-DEL',
                'type' => Location::CLINIC, 'is_active' => true,
            ]);

            return [$here->id, $there->id];
        });

        $this->placeStaffAt($organization, $here);

        return [$organization, $here, $there];
    }

    public function test_the_owner_sees_every_panel_they_hold(): void
    {
        [$organization] = $this->network();

        $this->signInAsOwner($organization);

        $data = $this->getJson('/api/v1/tenant/dashboard')->assertOk()->json('data');

        foreach (['headline', 'patients', 'branches', 'insights', 'plan', 'activity'] as $panel) {
            $this->assertArrayHasKey($panel, $data);
        }

        // Appointments was never sold to this organization, so the panel is
        // not merely empty — it is not there.
        $this->assertArrayNotHasKey('appointments', $data);

        // Nor is there an appointments card among the headline figures.
        $this->assertNotContains('appointments', array_column($data['headline'], 'key'));

        $this->assertSame('Across every branch', $data['scope']['label']);
    }

    /**
     * A panel the caller cannot see never reaches them.
     *
     * Absent, not empty and not zeroed: the response is the boundary, so
     * nothing is left for a client to decide about.
     */
    public function test_a_panel_without_its_capability_is_absent(): void
    {
        [$organization] = $this->network();

        $this->setStaffCapabilities($organization, ['customers.view']);
        $this->signInAsStaff($organization);

        $data = $this->getJson('/api/v1/tenant/dashboard')->assertOk()->json('data');

        $this->assertArrayHasKey('patients', $data);

        foreach (['branches', 'activity', 'appointments'] as $panel) {
            $this->assertArrayNotHasKey($panel, $data);
        }

        // The headline carries only the figures they may see — one card, not
        // four of which three would read zero.
        $this->assertSame(['patients'], array_column($data['headline'], 'key'));
    }

    /** Holding nothing is a valid dashboard, not a refusal. */
    public function test_somebody_holding_nothing_gets_an_empty_dashboard(): void
    {
        [$organization] = $this->network();

        $this->setStaffCapabilities($organization, []);
        $this->signInAsStaff($organization);

        $data = $this->getJson('/api/v1/tenant/dashboard')->assertOk()->json('data');

        /*
         * `headline` and `plan` need no capability — the first is empty
         * because every card inside it does, and the second says which
         * modules the organization holds, which `/auth/me` already tells
         * them. Everything that answers a capability is gone.
         */
        $this->assertSame(['context', 'scope', 'headline', 'plan'], array_keys($data));
        $this->assertSame([], $data['headline']);
    }

    /**
     * The numbers are about the branches this person works at.
     *
     * Not a filter the client sent — it comes from where they are a member,
     * so a branch manager's dashboard cannot be widened by editing a request.
     */
    public function test_the_figures_are_scoped_to_the_callers_branches(): void
    {
        [$organization, $here, $there] = $this->network();

        $this->onTenant($organization, function () use ($here, $there) {
            Customer::on('organization')->create([
                'name' => 'Asha Lucknow', 'phone' => '9876500051',
                'is_active' => true, 'registered_location_id' => $here,
            ]);

            foreach (['9876500052', '9876500053'] as $phone) {
                Customer::on('organization')->create([
                    'name' => 'Delhi patient', 'phone' => $phone,
                    'is_active' => true, 'registered_location_id' => $there,
                ]);
            }
        });

        $this->setStaffCapabilities($organization, ['customers.view']);
        $this->signInAsStaff($organization);

        $mine = $this->getJson('/api/v1/tenant/dashboard')->assertOk()->json('data');

        $this->assertSame(1, $mine['patients']['total']);
        $this->assertSame('Lucknow', $mine['scope']['label']);

        // The owner sees all three, which is what makes the one above a scope
        // rather than the only customer on file.
        $this->signInAsOwner($organization);

        $all = $this->getJson('/api/v1/tenant/dashboard')->assertOk()->json('data');

        $this->assertSame(3, $all['patients']['total']);
    }

    /** The appointments panel needs the module, not just the capability. */
    public function test_the_opd_panel_needs_the_module(): void
    {
        [$organization] = $this->network();

        $this->setStaffCapabilities($organization, ['appointments.view']);
        $this->signInAsStaff($organization);

        $this->assertArrayNotHasKey(
            'appointments',
            $this->getJson('/api/v1/tenant/dashboard')->assertOk()->json('data'),
        );

        $this->grantModule($organization, 'appointments');

        $this->signInAsStaff($organization);

        $data = $this->getJson('/api/v1/tenant/dashboard')->assertOk()->json('data');

        $this->assertArrayHasKey('appointments', $data);
        $this->assertSame(0, $data['appointments']['waiting']);
        $this->assertNull($data['appointments']['longest_wait_minutes']);
        $this->assertSame([], $data['appointments']['upcoming']);
    }
}
