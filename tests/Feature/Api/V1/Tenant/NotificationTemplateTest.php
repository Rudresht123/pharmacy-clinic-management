<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\GlobalNotificationTemplate;
use App\Models\Platform\Organization;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Location;
use App\Models\Tenant\NotificationTemplate;
use App\Models\Tenant\PatientNotification;
use App\Services\Notifications\NotificationTemplateResolver;
use App\Services\Portal\PatientNotifications;
use App\Support\Notifications\NotificationTypes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

/**
 * Global → Organisation → Branch, and the variables that may be used.
 *
 * Branch A and Branch B; the staff member works at A.
 */
class NotificationTemplateTest extends TenantTestCase
{
    use RefreshDatabase;

    private const KEY = NotificationTypes::APPOINTMENT_CONFIRMED;

    private Organization $organization;

    private int $a;

    private int $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');

        $this->organization = $this->provisionOrganization('NT');
        $this->grantModule($this->organization, 'appointments');
        $this->grantModule($this->organization, 'communication');

        [$this->a, $this->b] = $this->onTenant($this->organization, fn () => [
            Location::on('organization')->create([
                'name' => 'Branch A', 'code' => 'NT-A', 'type' => Location::CLINIC, 'is_active' => true,
            ])->id,
            Location::on('organization')->create([
                'name' => 'Branch B', 'code' => 'NT-B', 'type' => Location::CLINIC, 'is_active' => true,
            ])->id,
        ]);

        $this->placeStaffAt($this->organization, $this->a);
        $this->signInAsOwner($this->organization);
    }

    /** @return array{title: string, body: string, source: string} */
    private function resolved(Organization $organization, ?int $locationId): array
    {
        return $this->onTenant(
            $organization,
            fn () => app(NotificationTemplateResolver::class)->resolve(self::KEY, $locationId),
        );
    }

    private function saveOrganization(string $body, bool $active = true): \Illuminate\Testing\TestResponse
    {
        return $this->putJson('/api/v1/tenant/notification-templates/'.self::KEY, [
            'title' => 'Booked!',
            'body' => $body,
            'is_active' => $active,
        ]);
    }

    private function saveBranch(int $locationId, string $body, bool $active = true): \Illuminate\Testing\TestResponse
    {
        return $this->putJson('/api/v1/tenant/notification-templates/'.self::KEY."/branches/{$locationId}", [
            'title' => 'See you soon',
            'body' => $body,
            'is_active' => $active,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Inheritance
    |--------------------------------------------------------------------------
    */

    public function test_with_nothing_customised_the_global_wording_is_used_and_it_is_todays(): void
    {
        $template = $this->resolved($this->organization, $this->a);

        $this->assertSame(NotificationTemplateResolver::GLOBAL, $template['source']);
        $this->assertSame(NotificationTypes::find(self::KEY)['body'], $template['body']);
    }

    public function test_an_organization_template_overrides_the_global_one_at_every_branch(): void
    {
        $this->saveOrganization('Hi {{patient_name}}, {{doctor_name}} will see you on {{appointment_date}}.')
            ->assertOk()
            ->assertJsonPath('data.types.0.effective.source', NotificationTemplateResolver::ORGANIZATION);

        foreach ([null, $this->a, $this->b] as $where) {
            $this->assertSame(NotificationTemplateResolver::ORGANIZATION, $this->resolved($this->organization, $where)['source']);
        }
    }

    public function test_a_branch_template_overrides_the_organization_at_that_branch_only(): void
    {
        $this->saveOrganization('Organisation wording for {{patient_name}}.')->assertOk();
        $this->saveBranch($this->a, 'Branch A wording for {{patient_name}}.')
            ->assertOk()
            ->assertJsonPath('data.branch_id', $this->a)
            ->assertJsonPath('data.types.0.effective.source', NotificationTemplateResolver::BRANCH);

        $this->assertSame('Branch A wording for {{patient_name}}.', $this->resolved($this->organization, $this->a)['body']);
        $this->assertSame('Organisation wording for {{patient_name}}.', $this->resolved($this->organization, $this->b)['body']);
    }

    public function test_an_inactive_branch_template_falls_back_to_the_organization(): void
    {
        $this->saveOrganization('Organisation wording.')->assertOk();
        $this->saveBranch($this->a, 'Branch wording.', active: false)->assertOk();

        $this->assertSame(NotificationTemplateResolver::ORGANIZATION, $this->resolved($this->organization, $this->a)['source']);
    }

    public function test_removing_a_level_inherits_from_the_one_below_again(): void
    {
        $this->saveOrganization('Organisation wording.')->assertOk();
        $this->saveBranch($this->a, 'Branch wording.')->assertOk();

        $this->deleteJson('/api/v1/tenant/notification-templates/'.self::KEY."/branches/{$this->a}")->assertOk();
        $this->assertSame(NotificationTemplateResolver::ORGANIZATION, $this->resolved($this->organization, $this->a)['source']);

        $this->deleteJson('/api/v1/tenant/notification-templates/'.self::KEY)->assertOk();
        $this->assertSame(NotificationTemplateResolver::GLOBAL, $this->resolved($this->organization, $this->a)['source']);
    }

    public function test_the_global_level_is_the_platforms_and_is_used_below_everything(): void
    {
        GlobalNotificationTemplate::query()
            ->where('template_key', self::KEY)
            ->update(['body' => 'Platform wording for {{patient_name}}.']);

        $this->assertSame('Platform wording for {{patient_name}}.', $this->resolved($this->organization, $this->b)['body']);
    }

    public function test_one_organizations_templates_never_reach_another(): void
    {
        $this->saveOrganization('Only for this clinic.')->assertOk();

        $other = $this->provisionOrganization('NX');

        $this->assertSame(NotificationTemplateResolver::GLOBAL, $this->resolved($other, null)['source']);
    }

    /*
    |--------------------------------------------------------------------------
    | Variables
    |--------------------------------------------------------------------------
    */

    public function test_a_variable_the_notification_cannot_fill_is_refused_by_name(): void
    {
        $this->saveOrganization('Hi {{patinet_name}}, your {{diagnosis}} is ready.')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['body' => '{{patinet_name}}, {{diagnosis}} cannot be filled in this notification']);

        $this->assertSame(0, $this->onTenant($this->organization, fn () => NotificationTemplate::query()->count()));
    }

    public function test_the_patient_is_told_in_the_resolved_wording_with_the_blanks_filled(): void
    {
        $this->saveBranch($this->a, '{{patient_name}}, {{doctor_name}} will see you on {{appointment_date}} at {{branch_name}}.')
            ->assertOk();

        $this->onTenant($this->organization, function () {
            $doctor = Doctor::on('organization')->create(['name' => 'Dr. Amit Singh', 'is_active' => true]);
            $patient = Customer::on('organization')->create([
                'name' => 'Rohit Sharma', 'phone' => '9810000006', 'is_active' => true,
            ]);

            $visit = Appointment::on('organization')->create([
                'customer_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'location_id' => $this->a,
                'appointment_date' => '2026-09-07',
                'type' => Appointment::BOOKED,
                'status' => Appointment::STATUS_BOOKED,
                'slot_at' => '10:30',
            ]);

            app(PatientNotifications::class)->appointmentBooked($visit);

            $told = PatientNotification::query()->sole();

            $this->assertSame('See you soon', $told->title);
            $this->assertSame('Rohit Sharma, Dr. Amit Singh will see you on 07 Sep 2026 at Branch A.', $told->body);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Who may change what
    |--------------------------------------------------------------------------
    */

    public function test_a_branch_may_reword_its_own_notifications_and_nobody_elses(): void
    {
        $this->setStaffCapabilities($this->organization, ['communication.view', 'communication.branch_templates']);
        $this->signInAsStaff($this->organization);

        $this->saveBranch($this->a, 'Branch A wording.')->assertOk();
        $this->saveBranch($this->b, 'Branch B wording.')->assertStatus(403);
        $this->saveOrganization('Organisation wording.')->assertStatus(403);
    }
}
