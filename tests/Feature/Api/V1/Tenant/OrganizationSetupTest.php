<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\EntityLabel;
use App\Models\Tenant\Location;
use App\Models\Tenant\SetupStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

/**
 * Organisation setup — the owner configuring their organization from one
 * screen.
 *
 * What is asserted: progress is read from the organization's own data where
 * the data can answer, a sign-off is only recorded once there is something to
 * sign off, setup cannot be finished with a required section missing, and one
 * organization's progress is never another's.
 */
class OrganizationSetupTest extends TenantTestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');

        $this->organization = $this->provisionOrganization('S');
        $this->signInAsOwner($this->organization);
    }

    /** @return array<string, array<string, mixed>> the steps, keyed */
    private function steps(): array
    {
        return collect($this->getJson('/api/v1/tenant/setup')->assertOk()->json('data.steps'))
            ->keyBy('key')
            ->all();
    }

    private function details(array $overrides = []): array
    {
        return array_merge([
            'organization_name' => 'Sunrise Clinics',
            'contact_person_name' => 'Dr. Asha Mehta',
            'phone_number' => '98100 00001',
            'address' => '12 MG Road',
            'city' => 'Pune',
            'state_province' => 'Maharashtra',
            'postal_code' => '411001',
        ], $overrides);
    }

    private function addBranch(bool $active = true): void
    {
        $this->onTenant($this->organization, fn () => Location::on('organization')->create([
            'name' => $active ? 'Pune Central' : 'Old Site',
            'code' => $active ? 'PUN' : 'OLD',
            'type' => Location::CLINIC,
            'is_active' => $active,
        ]));
    }

    public function test_a_new_organisation_opens_on_its_first_required_section(): void
    {
        $body = $this->getJson('/api/v1/tenant/setup')->assertOk()->json('data');

        $this->assertSame(7, $body['total']);
        $this->assertSame('organization', $body['current']);
        $this->assertFalse($body['can_complete']);
        $this->assertNull($body['completed_at']);
        $this->assertSame((int) round($body['completed_count'] / 7 * 100), $body['percent']);

        $steps = collect($body['steps'])->keyBy('key');

        $this->assertSame('incomplete', $steps['organization']['status']);
        $this->assertContains('Add a contact person.', $steps['organization']['missing']);
        $this->assertSame('incomplete', $steps['branches']['status']);
        // Finishing is required, and waits on the sections before it.
        $this->assertSame('incomplete', $steps['review']['status']);
        $this->assertContains('Finish Organisation Information first.', $steps['review']['missing']);
    }

    public function test_only_the_owner_opens_setup(): void
    {
        $this->signInAsStaff($this->organization);

        $this->getJson('/api/v1/tenant/setup')->assertForbidden();
        $this->putJson('/api/v1/tenant/setup/organization', $this->details())->assertForbidden();
        $this->postJson('/api/v1/tenant/setup/complete')->assertForbidden();
    }

    public function test_saving_the_details_completes_that_section_and_leaves_the_email_alone(): void
    {
        $this->putJson('/api/v1/tenant/setup/organization', $this->details([
            'contact_person_name' => '',
            'phone_number' => '12',
        ]))->assertStatus(422)->assertJsonValidationErrors(['contact_person_name', 'phone_number']);

        $email = $this->organization->email;

        $this->putJson('/api/v1/tenant/setup/organization', $this->details([
            'gstin' => '27abcde1234f1z5',
            // Not the setup's to change: it is the owner's sign-in.
            'email' => 'someone-else@example.com',
        ]))->assertOk();

        $this->assertSame('completed', $this->steps()['organization']['status']);

        $fresh = $this->organization->fresh();

        $this->assertSame('Sunrise Clinics', $fresh->organization_name);
        $this->assertSame('9810000001', $fresh->phone_number);
        $this->assertSame('27ABCDE1234F1Z5', $fresh->gstin);
        $this->assertSame($email, $fresh->email);

        // The address detail lives on the platform's profile row for the organisation.
        $this->assertSame('Pune', $fresh->profile?->city);
        $this->assertSame('411001', $fresh->profile?->postal_code);
    }

    public function test_an_active_branch_completes_the_branch_section(): void
    {
        $this->addBranch(active: false);

        $this->assertSame('incomplete', $this->steps()['branches']['status']);

        $this->addBranch();

        $branches = $this->steps()['branches'];

        $this->assertSame('completed', $branches['status']);
        $this->assertSame(2, $branches['counts']['branches']);
        $this->assertSame(1, $branches['counts']['clinics']);
    }

    public function test_departments_are_required_where_opd_runs_and_signed_off_once_saved(): void
    {
        // No OPD sold: departments are offered, not demanded.
        $this->assertFalse($this->steps()['departments']['mandatory']);

        $this->grantModule($this->organization, 'appointments');

        $departments = $this->steps()['departments'];

        $this->assertTrue($departments['mandatory']);
        $this->assertSame('incomplete', $departments['status']);
        $this->assertContains('Check the department list and save it.', $departments['missing']);

        $this->postJson('/api/v1/tenant/setup/steps/departments/confirm')->assertOk();

        $this->assertSame('completed', $this->steps()['departments']['status']);

        // Only the sections that are a sign-off can be signed off.
        $this->postJson('/api/v1/tenant/setup/steps/organization/confirm')->assertNotFound();
    }

    public function test_setup_cannot_finish_with_a_required_section_missing(): void
    {
        $this->postJson('/api/v1/tenant/setup/complete')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['steps.organization', 'steps.branches']);

        $this->onTenant($this->organization, fn () => $this->assertFalse(SetupStep::query()->exists()));

        $this->putJson('/api/v1/tenant/setup/organization', $this->details())->assertOk();
        $this->addBranch();

        // The session tells the workspace it is still closed.
        $this->assertFalse($this->getJson('/api/v1/tenant/auth/me')->assertOk()->json('data.setup_completed'));

        $done = $this->postJson('/api/v1/tenant/setup/complete')->assertOk()->json('data');

        $this->assertTrue($this->getJson('/api/v1/tenant/auth/me')->assertOk()->json('data.setup_completed'));

        $this->assertNotNull($done['completed_at']);
        $this->assertSame('completed', collect($done['steps'])->firstWhere('key', 'review')['status']);

        // Restored after signing out and in, and finishing again keeps the first date.
        $this->signInAsOwner($this->organization);

        $again = $this->postJson('/api/v1/tenant/setup/complete')->assertOk()->json('data');

        $this->assertSame($done['completed_at'], $again['completed_at']);
    }

    public function test_work_done_before_setup_existed_counts(): void
    {
        $this->onTenant($this->organization, fn () => EntityLabel::on('organization')->create([
            'entity' => 'customer',
            'singular' => 'Patient',
            'plural' => 'Patients',
        ]));

        $this->postJson('/api/v1/tenant/roles', [
            'name' => 'Front desk',
            'scope' => 'organization',
            'icon' => 'ti ti-headset',
            'capabilities' => ['customers.view'],
        ])->assertCreated();

        $steps = $this->steps();

        $this->assertSame('completed', $steps['settings']['status']);
        $this->assertSame('completed', $steps['roles']['status']);

        // The seeded staff member is somebody other than the owner.
        $this->assertSame('completed', $steps['users']['status']);
    }

    public function test_one_organisations_progress_is_its_own(): void
    {
        $this->postJson('/api/v1/tenant/setup/steps/settings/confirm')->assertOk();
        $this->assertSame('completed', $this->steps()['settings']['status']);

        $other = $this->provisionOrganization('S2');
        $this->signInAsOwner($other);

        $this->assertSame('pending', $this->steps()['settings']['status']);
    }
}
