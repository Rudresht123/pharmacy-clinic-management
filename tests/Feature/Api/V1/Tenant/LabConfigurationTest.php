<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\LabDepartment;
use App\Models\Tenant\LabSetting;
use App\Models\Tenant\Location;
use App\Models\Tenant\Role;
use App\Support\Laboratory\LabMasters;
use App\Support\Laboratory\StarterLists;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * Phase 1 of the LIS: the laboratory's settings and its short lists.
 *
 * What is asserted is what the rest of the lab will lean on — safe defaults,
 * names that cannot be doubled, a row in use that cannot vanish, nothing
 * clinical seeded, and every write behind the organisation's lab setup tick.
 */
class LabConfigurationTest extends TenantTestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private int $branch;

    private int $otherBranch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');

        $this->organization = $this->provisionOrganization('LAB');
        $this->grantModule($this->organization, 'laboratory');

        [$this->branch, $this->otherBranch] = $this->onTenant($this->organization, fn () => [
            $this->location('Main Lab', 'LAB-1'),
            $this->location('Second Lab', 'LAB-2'),
        ]);

        $this->placeStaffAt($this->organization, $this->branch);
    }

    private function location(string $name, string $code): int
    {
        return Location::on('organization')->create([
            'name' => $name, 'code' => $code, 'type' => Location::CLINIC, 'is_active' => true,
        ])->id;
    }

    /** @param list<string> $ticks */
    private function staffHolding(array $ticks): void
    {
        $this->setStaffCapabilities($this->organization, $ticks);
        $this->signInAsStaff($this->organization);
    }

    /* ------------------------------------------------------------------
     | Settings
     |------------------------------------------------------------------ */

    public function test_settings_start_safe_and_save(): void
    {
        $this->signInAsOwner($this->organization);

        $this->getJson('/api/v1/tenant/lab/settings')
            ->assertOk()
            ->assertJsonPath('data.approval_levels', 1)
            ->assertJsonPath('data.allow_self_review', false)
            ->assertJsonPath('data.collect_requires_payment', false)
            ->assertJsonPath('data.release_partial_reports', true)
            ->assertJsonPath('data.critical_call_required', true)
            ->assertJsonPath('data.label_size', LabSetting::LABEL_50X25)
            ->assertJsonPath('meta.approval_levels', LabSetting::APPROVAL_LEVELS)
            ->assertJsonPath('meta.label_sizes', LabSetting::LABEL_SIZES);

        $this->putJson('/api/v1/tenant/lab/settings', [
            'approval_levels' => 2,
            'collect_requires_payment' => true,
            'urgent_tat_hours' => 6,
            'stat_tat_minutes' => 60,
            'label_size' => LabSetting::LABEL_38X25,
        ])
            ->assertOk()
            ->assertJsonPath('data.approval_levels', 2)
            ->assertJsonPath('data.collect_requires_payment', true)
            ->assertJsonPath('data.urgent_tat_hours', 6)
            ->assertJsonPath('data.stat_tat_minutes', 60)
            ->assertJsonPath('data.label_size', LabSetting::LABEL_38X25)
            // A partial save leaves what it did not name alone.
            ->assertJsonPath('data.critical_call_required', true);

        $this->putJson('/api/v1/tenant/lab/settings', [
            'approval_levels' => 3,
            'label_size' => 'poster',
            'stat_tat_minutes' => 2,
        ])->assertStatus(422)->assertJsonValidationErrors(['approval_levels', 'label_size', 'stat_tat_minutes']);

        $this->assertSame(1, $this->onTenant($this->organization, fn () => LabSetting::query()->count()));
    }

    /* ------------------------------------------------------------------
     | The lists
     |------------------------------------------------------------------ */

    public function test_the_catalogue_describes_every_list(): void
    {
        $this->signInAsOwner($this->organization);

        $response = $this->getJson('/api/v1/tenant/lab/masters')->assertOk();

        $this->assertSame(LabMasters::keys(), array_column($response->json('data'), 'key'));
        $this->assertSame(
            ['name', 'cap_colour', 'additive', 'volume_ml'],
            array_column(collect($response->json('data'))->firstWhere('key', LabMasters::CONTAINERS)['fields'], 'name'),
        );
    }

    /** One valid row per list, and a change to each. */
    public function test_every_list_can_be_added_to_edited_and_read(): void
    {
        $this->signInAsOwner($this->organization);

        $department = $this->postJson('/api/v1/tenant/lab/masters/departments', ['name' => 'Haematology', 'code' => 'haem'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'HAEM')
            ->assertJsonPath('data.label', 'Haematology')
            ->json('data.id');

        $container = $this->postJson('/api/v1/tenant/lab/masters/containers', [
            'name' => 'EDTA tube', 'cap_colour' => 'lavender', 'additive' => 'K2 EDTA', 'volume_ml' => 3,
        ])->assertCreated()->assertJsonPath('data.volume_ml', 3)->json('data.id');

        $rows = [
            'test-categories' => ['name' => 'Complete blood count', 'department_id' => $department],
            'methods' => ['name' => 'Microscopy'],
            'units' => ['symbol' => 'g/dL', 'name' => 'Grams per decilitre'],
            'sample-types' => ['name' => 'Whole blood', 'code' => 'wb', 'default_container_id' => $container],
            'rejection-reasons' => ['reason' => 'Clotted sample', 'requires_recollection' => true],
            'equipment' => ['name' => 'Analyser 1', 'department_id' => $department, 'location_id' => $this->branch],
            'outsource-labs' => ['name' => 'City Reference Lab', 'email' => 'lab@example.com'],
        ];

        foreach ($rows as $master => $row) {
            $id = $this->postJson("/api/v1/tenant/lab/masters/{$master}", $row)->assertCreated()->json('data.id');

            $this->putJson("/api/v1/tenant/lab/masters/{$master}/{$id}", ['is_active' => false])
                ->assertOk()
                ->assertJsonPath('data.is_active', false);

            $this->getJson("/api/v1/tenant/lab/masters/{$master}")
                ->assertOk()
                ->assertJsonPath('data.0.id', $id);

            $this->getJson("/api/v1/tenant/lab/masters/{$master}?active_only=1")
                ->assertOk()
                ->assertJsonCount(0, 'data');
        }

        // A row names what it points at, so a table never looks it up.
        $this->getJson('/api/v1/tenant/lab/masters/sample-types')
            ->assertJsonPath('data.0.default_container_name', 'EDTA tube')
            ->assertJsonPath('data.0.code', 'WB');

        $this->getJson('/api/v1/tenant/lab/masters/equipment')
            ->assertJsonPath('data.0.department_name', 'Haematology')
            ->assertJsonPath('data.0.location_name', 'Main Lab');
    }

    public function test_names_are_unique_ignoring_case_but_units_keep_theirs(): void
    {
        $this->signInAsOwner($this->organization);

        $this->postJson('/api/v1/tenant/lab/masters/departments', ['name' => 'Haematology', 'code' => 'HAEM'])->assertCreated();

        $this->postJson('/api/v1/tenant/lab/masters/departments', ['name' => '  haematology ', 'code' => 'haem'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Another department is already called haematology.')
            ->assertJsonPath('errors.code.0', 'Another department already has the code HAEM.');

        // mU/L and MU/L are a thousand million apart.
        $this->postJson('/api/v1/tenant/lab/masters/units', ['symbol' => 'mU/L'])->assertCreated();
        $this->postJson('/api/v1/tenant/lab/masters/units', ['symbol' => 'MU/L'])->assertCreated();
        $this->postJson('/api/v1/tenant/lab/masters/units', ['symbol' => 'mU/L'])->assertStatus(422);

        // Blank is not a name, and a code is letters, digits and . _ -
        $this->postJson('/api/v1/tenant/lab/masters/methods', ['name' => '   '])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/tenant/lab/masters/sample-types', ['name' => 'Serum', 'code' => 'S R'])
            ->assertStatus(422)->assertJsonValidationErrors('code');

        // Editing a row may keep its own name.
        $id = $this->onTenant($this->organization, fn () => LabDepartment::query()->value('id'));
        $this->putJson("/api/v1/tenant/lab/masters/departments/{$id}", ['name' => 'HAEMATOLOGY'])->assertOk();
    }

    public function test_a_category_name_is_unique_within_its_department(): void
    {
        $this->signInAsOwner($this->organization);

        $haem = $this->postJson('/api/v1/tenant/lab/masters/departments', ['name' => 'Haematology'])->json('data.id');
        $bio = $this->postJson('/api/v1/tenant/lab/masters/departments', ['name' => 'Biochemistry'])->json('data.id');

        $this->postJson('/api/v1/tenant/lab/masters/test-categories', ['name' => 'Routine', 'department_id' => $haem])->assertCreated();
        $this->postJson('/api/v1/tenant/lab/masters/test-categories', ['name' => 'Routine', 'department_id' => $bio])->assertCreated();
        $this->postJson('/api/v1/tenant/lab/masters/test-categories', ['name' => 'routine', 'department_id' => $haem])
            ->assertStatus(422)->assertJsonValidationErrors('name');

        $this->postJson('/api/v1/tenant/lab/masters/test-categories', ['name' => 'Routine'])->assertCreated();
        $this->postJson('/api/v1/tenant/lab/masters/test-categories', ['name' => 'ROUTINE'])->assertStatus(422);
    }

    public function test_a_row_in_use_is_switched_off_not_removed(): void
    {
        $this->signInAsOwner($this->organization);

        $department = $this->postJson('/api/v1/tenant/lab/masters/departments', ['name' => 'Haematology'])->json('data.id');
        $category = $this->postJson('/api/v1/tenant/lab/masters/test-categories', [
            'name' => 'Blood counts', 'department_id' => $department,
        ])->json('data.id');

        $this->deleteJson("/api/v1/tenant/lab/masters/departments/{$department}")
            ->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->deleteJson("/api/v1/tenant/lab/masters/departments/{$department}", ['reason' => 'Merged'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Haematology is still used by 1 test category. Switch it off instead, or move it first.');

        $this->putJson("/api/v1/tenant/lab/masters/departments/{$department}", ['is_active' => false])->assertOk();

        $this->deleteJson("/api/v1/tenant/lab/masters/test-categories/{$category}", ['reason' => 'Not used'])->assertOk();
        $this->deleteJson("/api/v1/tenant/lab/masters/departments/{$department}", ['reason' => 'Merged into Pathology'])->assertOk();

        $removed = $this->onTenant($this->organization, fn () => LabDepartment::withTrashed()->find($department));
        $this->assertNotNull($removed->deleted_at);
        $this->assertSame('Merged into Pathology', $removed->deletion_reason);
        $this->assertNotNull($removed->deleted_by);

        // Gone from the list, and its name is free again.
        $this->getJson('/api/v1/tenant/lab/masters/departments')->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/tenant/lab/masters/departments', ['name' => 'Haematology'])->assertCreated();

        // A removed row cannot be pointed at.
        $this->postJson('/api/v1/tenant/lab/masters/test-categories', ['name' => 'Ghost', 'department_id' => $department])
            ->assertStatus(422)->assertJsonValidationErrors('department_id');
    }

    public function test_a_starter_list_adds_only_what_is_missing(): void
    {
        $this->signInAsOwner($this->organization);

        // One of its names already here, in another case and renamed code.
        $this->postJson('/api/v1/tenant/lab/masters/departments', ['name' => 'HAEMATOLOGY', 'code' => 'H1'])->assertCreated();

        $expected = count(StarterLists::for(LabMasters::DEPARTMENTS)) - 1;

        $this->postJson('/api/v1/tenant/lab/masters/departments/starter')
            ->assertOk()
            ->assertJsonPath('meta.added', $expected)
            ->assertJsonCount($expected + 1, 'data');

        $this->postJson('/api/v1/tenant/lab/masters/departments/starter')
            ->assertOk()
            ->assertJsonPath('meta.added', 0);

        // What somebody wrote is never touched.
        $this->assertSame('H1', $this->onTenant(
            $this->organization,
            fn () => LabDepartment::query()->whereRaw('lower(name) = ?', ['haematology'])->value('code'),
        ));

        $this->postJson('/api/v1/tenant/lab/masters/test-categories/starter')->assertStatus(409);
    }

    /** Vocabulary only: no list starts filled, and no starter row carries a clinical value. */
    public function test_nothing_clinical_is_seeded(): void
    {
        $this->onTenant($this->organization, function () {
            foreach (LabMasters::all() as $definition) {
                $this->assertSame(0, $definition['model']::query()->withTrashed()->count());
            }
        });

        foreach (LabMasters::all() as $key => $definition) {
            $allowed = [...array_column($definition['fields'], 'name')];

            foreach (StarterLists::for($key) as $row) {
                $this->assertSame([], array_diff(array_keys($row), $allowed), "{$key} starter has a field the list does not");
            }
        }
    }

    public function test_an_unknown_list_is_not_found(): void
    {
        $this->signInAsOwner($this->organization);

        $this->getJson('/api/v1/tenant/lab/masters/patients')->assertNotFound();
        $this->postJson('/api/v1/tenant/lab/masters/lab_settings', ['name' => 'x'])->assertNotFound();
    }

    /* ------------------------------------------------------------------
     | Who may
     |------------------------------------------------------------------ */

    public function test_reading_and_saving_are_separate_ticks(): void
    {
        $this->staffHolding(['laboratory.view']);

        $this->getJson('/api/v1/tenant/lab/settings')->assertForbidden();
        $this->getJson('/api/v1/tenant/lab/masters/departments')->assertForbidden();

        $this->staffHolding(['laboratory.settings_view']);

        $this->getJson('/api/v1/tenant/lab/settings')->assertOk();
        $this->getJson('/api/v1/tenant/lab/masters')->assertOk();
        $this->getJson('/api/v1/tenant/lab/masters/departments')->assertOk();
        $this->putJson('/api/v1/tenant/lab/settings', ['approval_levels' => 2])->assertForbidden();
        $this->postJson('/api/v1/tenant/lab/masters/departments', ['name' => 'Haematology'])->assertForbidden();
        $this->postJson('/api/v1/tenant/lab/masters/departments/starter')->assertForbidden();

        $this->staffHolding(['laboratory.settings_manage']);

        $this->putJson('/api/v1/tenant/lab/settings', ['approval_levels' => 2])
            ->assertOk()
            ->assertJsonPath('data.updated_by_name', fn ($name) => is_string($name) && $name !== '');
        $this->postJson('/api/v1/tenant/lab/masters/departments', ['name' => 'Haematology'])->assertCreated();
    }

    public function test_the_lab_switched_off_at_a_branch_refuses_there(): void
    {
        $this->disableModuleAtBranch($this->organization, $this->branch, 'laboratory');
        $this->staffHolding(['laboratory.settings_view', 'laboratory.settings_manage']);

        $this->getJson('/api/v1/tenant/lab/settings')->assertForbidden();
        $this->postJson('/api/v1/tenant/lab/masters/departments', ['name' => 'Haematology'])->assertForbidden();
    }

    public function test_an_organisation_without_the_lab_is_refused(): void
    {
        $other = $this->provisionOrganization('NOL');

        $this->signInAsOwner($other);

        $this->getJson('/api/v1/tenant/lab/settings')->assertForbidden();
        $this->getJson('/api/v1/tenant/lab/masters')->assertForbidden();
    }

    /**
     * Sold on its own: a diagnostic lab that bought nothing but the patient
     * register and the laboratory sets its lab up the same as anyone. No OPD,
     * no pharmacy, no billing module behind it (the user's ask, 2026-11-07).
     */
    public function test_a_lab_only_organisation_sets_up_its_lab(): void
    {
        $this->revokeFoundationModules($this->organization);
        $this->grantModule($this->organization, 'customers');
        $this->grantModule($this->organization, 'laboratory');

        $this->signInAsOwner($this->organization);

        $this->getJson('/api/v1/tenant/lab/settings')->assertOk();
        $this->putJson('/api/v1/tenant/lab/settings', ['approval_levels' => 2])->assertOk();
        $this->postJson('/api/v1/tenant/lab/masters/sample-types/starter')->assertOk();
        $this->postJson('/api/v1/tenant/lab/masters/departments', ['name' => 'Haematology'])->assertCreated();

        // And it really is alone: the OPD was never part of it.
        $this->getJson('/api/v1/tenant/appointments')->assertForbidden();
    }

    /** Equipment names a branch; somebody who works at one branch places it only there. */
    public function test_equipment_is_placed_only_at_a_reachable_branch(): void
    {
        $this->staffHolding(['laboratory.settings_manage']);

        $this->postJson('/api/v1/tenant/lab/masters/equipment', ['name' => 'Analyser 2', 'location_id' => $this->otherBranch])
            ->assertForbidden();

        $this->postJson('/api/v1/tenant/lab/masters/equipment', ['name' => 'Analyser 1', 'location_id' => $this->branch])
            ->assertCreated();
    }

    /** One organisation's lists are another's nothing: separate databases. */
    public function test_another_organisation_never_sees_these_lists(): void
    {
        $this->signInAsOwner($this->organization);
        $id = $this->postJson('/api/v1/tenant/lab/masters/departments', ['name' => 'Haematology'])->json('data.id');
        $this->putJson('/api/v1/tenant/lab/settings', ['approval_levels' => 2])->assertOk();

        $other = $this->provisionOrganization('LBB');
        $this->grantModule($other, 'laboratory');
        $this->signInAsOwner($other);

        $this->getJson('/api/v1/tenant/lab/masters/departments')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/tenant/lab/settings')->assertJsonPath('data.approval_levels', 1);
        $this->putJson("/api/v1/tenant/lab/masters/departments/{$id}", ['name' => 'Taken over'])->assertNotFound();
        $this->deleteJson("/api/v1/tenant/lab/masters/departments/{$id}", ['reason' => 'x'])->assertNotFound();
    }

    /* ------------------------------------------------------------------
     | Roles
     |------------------------------------------------------------------ */

    public function test_both_ticks_are_registered_with_a_hint(): void
    {
        $lab = collect(ModuleRegistry::all())->firstWhere('key', 'laboratory');

        $this->assertSame(['customers'], $lab['requires']);

        $ticks = collect($lab['capabilities'])->keyBy('key');
        $this->assertSame(ModuleRegistry::SCOPE_BRANCH, $ticks['laboratory.settings_view']['scope']);
        $this->assertSame(ModuleRegistry::SCOPE_ORGANIZATION, $ticks['laboratory.settings_manage']['scope']);
        $this->assertTrue($ticks['laboratory.settings_manage']['settings']);
    }

    /** Whoever keeps the price list, signs results off or runs a lab branch is handed the screen. */
    public function test_the_backfill_gives_the_screen_to_the_roles_doing_that_work(): void
    {
        $this->setStaffCapabilities($this->organization, ['billing.manage_services']);

        $this->onTenant($this->organization, function () {
            $previous = DB::getDefaultConnection();
            DB::setDefaultConnection('organization');

            try {
                (require base_path('database/migrations/organization/2026_11_07_000100_give_lab_settings_to_roles.php'))->up();
            } finally {
                DB::setDefaultConnection($previous);
            }
        });

        $held = $this->onTenant($this->organization, fn () => Role::on('organization')
            ->where('slug', Role::SEEDED_STAFF)
            ->firstOrFail()
            ->capabilities()
            ->pluck('capability')
            ->all());

        $this->assertContains('laboratory.settings_manage', $held);
        $this->assertContains('laboratory.settings_view', $held);

        $this->signInAsStaff($this->organization);
        $this->putJson('/api/v1/tenant/lab/settings', ['release_partial_reports' => false])->assertOk();
    }
}
