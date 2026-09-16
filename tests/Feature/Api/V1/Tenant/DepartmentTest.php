<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\Department;
use App\Models\Tenant\Doctor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * Departments and sub-departments.
 *
 * What is asserted: the list an organisation already had arrives as
 * top-level departments; a tree is two levels and no loops; names are unique
 * among siblings; a doctor in a sub-department still reads as its
 * department on every screen that reads the text; and nothing in use is
 * deleted.
 */
class DepartmentTest extends TenantTestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/organization/2026_09_26_000000_create_departments_table.php';

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');

        $this->organization = $this->provisionOrganization('D');
        $this->grantModule($this->organization, 'appointments');
        $this->signInAsOwner($this->organization);
    }

    private function add(string $name, ?int $parent = null)
    {
        return $this->postJson('/api/v1/tenant/departments', ['name' => $name, 'parent_id' => $parent]);
    }

    /** @return Collection<string, array<string, mixed>> the top-level departments, by name */
    private function tree(): Collection
    {
        return collect($this->getJson('/api/v1/tenant/departments')->assertOk()->json('data'))->keyBy('name');
    }

    private function id(string $name): int
    {
        return (int) $this->tree()[$name]['id'];
    }

    public function test_the_existing_list_arrives_as_top_level_departments(): void
    {
        $cardiology = $this->tree()['Cardiology'];

        $this->assertNull($cardiology['parent_id']);
        $this->assertTrue($cardiology['is_top_level']);
        $this->assertSame([], $cardiology['children']);
    }

    public function test_a_department_takes_sub_departments(): void
    {
        $cardiology = $this->id('Cardiology');

        $this->add('Interventional Cardiology', $cardiology)->assertCreated();
        $this->add('Cardiac Diagnostics', $cardiology)->assertCreated();

        $node = $this->tree()['Cardiology'];

        $this->assertSame(2, $node['children_count']);
        $this->assertEqualsCanonicalizing(
            ['Interventional Cardiology', 'Cardiac Diagnostics'],
            array_column($node['children'], 'name'),
        );
    }

    public function test_the_tree_is_two_levels_and_never_loops(): void
    {
        $cardiology = $this->id('Cardiology');
        $sub = $this->add('Interventional Cardiology', $cardiology)->json('data.id');

        // Not under a sub-department.
        $this->add('Cath Lab', $sub)->assertStatus(422)->assertJsonValidationErrors('parent_id');

        // Not its own parent.
        $this->putJson("/api/v1/tenant/departments/{$cardiology}", ['name' => 'Cardiology', 'parent_id' => $cardiology])
            ->assertStatus(422)
            ->assertJsonValidationErrors('parent_id');

        // A department with sub-departments cannot become one.
        $this->putJson("/api/v1/tenant/departments/{$cardiology}", [
            'name' => 'Cardiology',
            'parent_id' => $this->id('General Medicine'),
        ])->assertStatus(422)->assertJsonValidationErrors('parent_id');
    }

    public function test_names_are_unique_among_siblings(): void
    {
        $cardiology = $this->id('Cardiology');
        $medicine = $this->id('General Medicine');

        $this->add('cardiology')->assertStatus(422)->assertJsonValidationErrors('name');

        $this->add('Preventive Care', $cardiology)->assertCreated();
        $this->add('preventive care', $cardiology)->assertStatus(422)->assertJsonValidationErrors('name');

        // The same name under another department is another sub-department.
        $this->add('Preventive Care', $medicine)->assertCreated();
    }

    public function test_a_doctor_in_a_sub_department_reads_as_its_department(): void
    {
        $cardiology = $this->id('Cardiology');
        $sub = $this->add('Interventional Cardiology', $cardiology)->json('data.id');

        $doctor = $this->postJson('/api/v1/tenant/doctors', [
            'name' => 'Dr. Asha Mehta',
            'is_active' => true,
            'department_id' => $sub,
        ])->assertCreated()->json('data');

        // The text the OPD board, filters and booking read is the department's.
        $this->assertSame('Cardiology', $doctor['specialisation']);
        $this->assertSame('Cardiology › Interventional Cardiology', $doctor['department_name']);

        // Renaming the department follows through to its doctors.
        $this->putJson("/api/v1/tenant/departments/{$cardiology}", ['name' => 'Heart Care'])->assertOk();

        $this->assertSame('Heart Care', $this->onTenant(
            $this->organization,
            fn () => Doctor::on('organization')->findOrFail($doctor['id'])->specialisation,
        ));
    }

    public function test_a_department_in_use_is_not_deleted(): void
    {
        $cardiology = $this->id('Cardiology');
        $sub = $this->add('Interventional Cardiology', $cardiology)->json('data.id');

        $this->postJson('/api/v1/tenant/doctors', [
            'name' => 'Dr. Asha Mehta',
            'is_active' => true,
            'department_id' => $sub,
        ])->assertCreated();

        $this->deleteJson("/api/v1/tenant/departments/{$cardiology}", ['reason' => 'Merged'])->assertStatus(409);
        $this->deleteJson("/api/v1/tenant/departments/{$sub}", ['reason' => 'Merged'])->assertStatus(409);

        $empty = $this->add('Sleep Clinic')->json('data.id');

        $this->deleteJson("/api/v1/tenant/departments/{$empty}")->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->deleteJson("/api/v1/tenant/departments/{$empty}", ['reason' => 'Never opened'])->assertOk();

        $this->assertArrayNotHasKey('Sleep Clinic', $this->tree()->all());

        $this->onTenant($this->organization, fn () => $this->assertSame(
            'Never opened',
            Department::on('organization')->withTrashed()->findOrFail($empty)->deletion_reason,
        ));
    }

    /** Staff belong to departments too — a receptionist in General Medicine. */
    public function test_staff_are_counted_in_their_department_and_keep_it_from_removal(): void
    {
        $sub = $this->add('Adult Medicine', $this->id('General Medicine'))->json('data.id');

        $this->onTenant($this->organization, fn () => \App\Models\Tenant\User::on('organization')
            ->where('email', self::STAFF_EMAIL)
            ->update(['department_id' => $sub]));

        $child = collect($this->tree()['General Medicine']['children'])->firstWhere('id', $sub);

        $this->assertSame(1, $child['staff_count']);
        $this->assertSame(0, $child['doctors_count']);

        $this->deleteJson("/api/v1/tenant/departments/{$sub}", ['reason' => 'Merged'])
            ->assertStatus(409)
            ->assertJsonPath('message', '1 staff is in Adult Medicine. Move them to another department first, or deactivate it instead.');
    }

    public function test_only_settings_managers_change_departments(): void
    {
        $this->setStaffCapabilities($this->organization, ['customers.view']);
        $this->signInAsStaff($this->organization);

        // Everybody reads the tree: the doctor form and booking pick from it.
        $this->getJson('/api/v1/tenant/departments')->assertOk();

        $this->add('Neurology')->assertForbidden();
    }

    public function test_names_doctors_already_use_become_departments(): void
    {
        $created = $this->onTenant($this->organization, function () {
            Doctor::on('organization')->create(['name' => 'Dr. Old Record', 'specialisation' => 'Neurology']);

            $previous = DB::getDefaultConnection();
            DB::setDefaultConnection('organization');

            try {
                return [
                    (require base_path(self::MIGRATION))->carryOverExistingList(),
                    // Run again, it finds nothing new.
                    (require base_path(self::MIGRATION))->carryOverExistingList(),
                ];
            } finally {
                DB::setDefaultConnection($previous);
            }
        });

        $this->assertSame([1, 0], $created);

        $this->onTenant($this->organization, function () {
            $neurology = Department::on('organization')->where('name', 'Neurology')->firstOrFail();

            $this->assertSame(
                $neurology->id,
                Doctor::on('organization')->where('name', 'Dr. Old Record')->value('department_id'),
            );
        });
    }
}
