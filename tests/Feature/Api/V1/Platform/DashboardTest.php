<?php

namespace Tests\Feature\Api\V1\Platform;

use App\Models\Platform\Organization;
use App\Models\Platform\PlatformRole;
use App\Models\Platform\PlatformUser;
use App\Models\Platform\WebsiteEnquiry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The super admin dashboard.
 *
 * What matters is that nothing on it is invented: every panel arrives, and
 * one the platform has no source for is null so the screen shows it empty.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private PlatformUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = PlatformUser::factory()->withRole(PlatformRole::SUPER_ADMIN)->create();
    }

    private function dashboard(array $query = []): array
    {
        return $this->actingAs($this->admin, 'platform')
            ->getJson('/api/v1/admin/dashboard?'.http_build_query($query))
            ->assertOk()
            ->json('data');
    }

    public function test_a_guest_cannot_see_it(): void
    {
        $this->getJson('/api/v1/admin/dashboard')->assertUnauthorized();
    }

    public function test_it_carries_real_figures_and_nulls_where_there_are_none(): void
    {
        Organization::factory()->active()->count(2)->create();
        Organization::factory()->active()->create(['trial_ends_at' => now()->addWeek()]);
        Organization::factory()->suspended()->create();
        Organization::factory()->create();

        $data = $this->dashboard();

        // Nothing the platform cannot answer is made up — it is there, and null.
        foreach (['revenue_trend', 'by_plan', 'top_organizations', 'subscription_revenue'] as $panel) {
            $this->assertArrayHasKey($panel, $data);
            $this->assertNull($data[$panel]);
        }

        // The tiles are the platform's own figures, every one real.
        $kpis = collect($data['kpis'])->pluck('value', 'key');
        $this->assertSame(5, $kpis['organizations']);
        $this->assertSame(3, $kpis['active']);
        $this->assertSame(5, $kpis['joined']);
        $this->assertSame(2, $kpis['awaiting']);
        $this->assertSame(1, $kpis['suspended']);
        $this->assertSame(0, $kpis['modules']);

        // A trial is an active organization whose trial has not ended.
        $status = collect($data['by_status'])->pluck('total', 'key');
        $this->assertSame(2, $status['active']);
        $this->assertSame(1, $status['trial']);
        $this->assertSame(1, $status['suspended']);
        $this->assertSame(1, $status['setting_up']);
        $this->assertSame(0, $status['cancelled']);

        // Real rows open the organization they name, and claim no plan.
        $this->assertCount(5, $data['new_organizations']);
        $this->assertNotNull($data['new_organizations'][0]['uuid']);
        $this->assertNull($data['new_organizations'][0]['plan']);

        // Only owners who finished their invitation have signed up.
        $this->assertCount(3, $data['recent_signups']);
    }

    public function test_locations_come_from_the_profiles_owners_fill_in(): void
    {
        $located = Organization::factory()->active()->count(2)->create();
        Organization::factory()->create();

        // Typed by owners, so spelt two ways — still one state.
        $located[0]->profile()->create(['city' => 'Pune', 'state_province' => 'Maharashtra']);
        $located[1]->profile()->create(['city' => 'Mumbai', 'state_province' => ' maharashtra']);

        $locations = $this->dashboard()['locations'];

        $this->assertSame(3, $locations['organizations']);
        $this->assertSame([['state' => 'Maharashtra', 'total' => 2]], $locations['points']);

        // A state is enough for the lists, not for the map: no point was found yet.
        $this->assertSame(0, $locations['located']);
        $this->assertSame([], $locations['places']);
    }

    public function test_an_empty_platform_still_answers_every_panel(): void
    {
        $data = $this->dashboard();

        $this->assertSame([], $data['new_organizations']);
        $this->assertSame([], $data['locations']['points']);
        $this->assertNull($data['module_usage']);
        $this->assertSame(0, $data['enquiries']['total']);
        $this->assertSame([], $data['enquiries']['rows']);
    }

    public function test_website_enquiries_are_counted_by_kind_and_listed(): void
    {
        WebsiteEnquiry::create(['type' => 'demo', 'name' => 'A', 'email' => 'a@x.in', 'status' => 'new']);
        WebsiteEnquiry::create(['type' => 'demo', 'name' => 'B', 'email' => 'b@x.in', 'status' => 'contacted']);
        WebsiteEnquiry::create(['type' => 'trial', 'name' => 'C', 'email' => 'c@x.in', 'status' => 'new']);

        // Last month: counted for the comparison, not in this period.
        $old = WebsiteEnquiry::create(['type' => 'demo', 'name' => 'D', 'email' => 'd@x.in', 'status' => 'new']);
        $old->forceFill(['created_at' => now()->startOfMonth()->subDays(3)])->save();

        $enquiries = $this->dashboard()['enquiries'];

        $this->assertSame(3, $enquiries['total']);
        $this->assertSame(200, $enquiries['change']);
        $this->assertSame(2, $enquiries['demo']);
        $this->assertSame(1, $enquiries['trial']);
        // Unanswered, whenever they arrived.
        $this->assertSame(3, $enquiries['awaiting_reply']);
        $this->assertCount(4, $enquiries['rows']);
        $this->assertSame('C', $enquiries['rows'][0]['name']);
    }

    public function test_the_range_is_validated(): void
    {
        $this->actingAs($this->admin, 'platform')
            ->getJson('/api/v1/admin/dashboard?from=2026-10-10&to=2026-10-01')
            ->assertUnprocessable();
    }
}
