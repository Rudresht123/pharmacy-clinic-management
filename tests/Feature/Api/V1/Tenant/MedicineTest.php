<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\ActivityLog;
use App\Models\Tenant\Medicine;
use App\Models\Tenant\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

/**
 * Pharmacy Phase 1 — the medicine master.
 *
 * What is asserted is the catalogue's promises: a medicine is not in it
 * twice, however it is typed; removing one says who and why and takes it
 * out of every working list; bringing it back is its own capability and
 * cannot recreate a duplicate; and the fixed lists stay inside what the
 * database accepts.
 */
class MedicineTest extends TenantTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The migration seeds a frozen catalogue; the pharmacy modules arrive
        // the way a deploy adds them.
        $this->artisan('modules:sync');
    }

    /** An organization sold the medicine master, signed in as its owner. */
    private function catalogue(bool $withPharmacy = false): Organization
    {
        $organization = $this->provisionOrganization('M');
        $this->grantModule($organization, 'medicines');

        if ($withPharmacy) {
            $this->grantModule($organization, 'pharmacy');
        }

        $this->signInAsOwner($organization);

        return $organization;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'generic_name' => 'Paracetamol',
            'brand_name' => 'Dolo 650',
            'strength' => '650 mg',
            'dosage_form' => 'tablet',
            'route' => 'oral',
            'base_unit' => 'tablet',
            'pack_size' => 15,
            'manufacturer' => 'Micro Labs',
            'schedule' => 'OTC',
        ], $overrides);
    }

    private function add(array $overrides = []): array
    {
        return $this->postJson('/api/v1/tenant/medicines', $this->payload($overrides))
            ->assertCreated()
            ->json('data');
    }

    /** @return list<string> the generic names the list answers with */
    private function listed(string $query = ''): array
    {
        return array_column(
            $this->getJson('/api/v1/tenant/medicines'.$query)->assertOk()->json('data'),
            'display_name',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Entitlement and capability
    |--------------------------------------------------------------------------
    */

    public function test_an_organization_without_the_module_cannot_reach_medicines(): void
    {
        $organization = $this->provisionOrganization('M');
        $this->signInAsOwner($organization);

        $this->getJson('/api/v1/tenant/medicines')->assertForbidden();
        $this->postJson('/api/v1/tenant/medicines', $this->payload())->assertForbidden();
    }

    public function test_staff_holding_view_may_read_but_not_change_the_catalogue(): void
    {
        $organization = $this->catalogue();
        $medicine = $this->add();

        $this->setStaffCapabilities($organization, ['medicines.view']);
        $this->signInAsStaff($organization);

        $this->getJson('/api/v1/tenant/medicines')->assertOk();
        $this->getJson("/api/v1/tenant/medicines/{$medicine['id']}")->assertOk();

        $this->postJson('/api/v1/tenant/medicines', $this->payload(['strength' => '500 mg']))
            ->assertForbidden();
        $this->putJson("/api/v1/tenant/medicines/{$medicine['id']}", $this->payload())
            ->assertForbidden();
        $this->deleteJson("/api/v1/tenant/medicines/{$medicine['id']}", ['reason' => 'Test'])
            ->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Creating and editing
    |--------------------------------------------------------------------------
    */

    public function test_a_medicine_is_added_with_its_defaults(): void
    {
        $this->catalogue();

        $medicine = $this->add(['pack_size' => null, 'schedule' => null]);

        $this->assertSame('Dolo 650 (Paracetamol) 650 mg tablet', $medicine['display_name']);
        $this->assertSame(1, $medicine['pack_size']);
        $this->assertTrue($medicine['prescription_required']);
        $this->assertTrue($medicine['is_active']);
        $this->assertNull($medicine['deleted_at']);
    }

    public function test_a_value_outside_a_fixed_list_is_refused(): void
    {
        $this->catalogue();

        $this->postJson('/api/v1/tenant/medicines', $this->payload([
            'dosage_form' => 'elixir',
            'base_unit' => 'strip',
            'schedule' => 'Z',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['dosage_form', 'base_unit', 'schedule']);
    }

    public function test_a_medicine_can_be_saved_without_changing_anything(): void
    {
        $this->catalogue();
        $medicine = $this->add(['medicine_code' => 'MED-1']);

        $this->putJson("/api/v1/tenant/medicines/{$medicine['id']}", $this->payload([
            'medicine_code' => 'MED-1',
            'category' => 'Analgesic',
        ]))
            ->assertOk()
            ->assertJsonPath('data.category', 'Analgesic');
    }

    /*
    |--------------------------------------------------------------------------
    | Duplicates
    |--------------------------------------------------------------------------
    */

    /** The same medicine typed differently is still the same medicine. */
    public function test_a_duplicate_is_refused_naming_the_one_already_there(): void
    {
        $this->catalogue();
        $this->add();

        foreach ([
            [],
            ['strength' => '650mg'],
            ['brand_name' => 'dolo-650', 'generic_name' => 'PARACETAMOL'],
            ['manufacturer' => 'micro labs '],
        ] as $variant) {
            $this->postJson('/api/v1/tenant/medicines', $this->payload($variant))
                ->assertStatus(422)
                ->assertJsonPath(
                    'errors.generic_name.0',
                    'This medicine is already in the catalogue as Dolo 650 (Paracetamol) 650 mg tablet. Edit that one instead.',
                );
        }

        $this->assertCount(1, $this->listed());
    }

    /** One generic in another strength, form or brand is a different medicine. */
    public function test_another_strength_form_or_brand_is_not_a_duplicate(): void
    {
        $this->catalogue();
        $this->add();

        $this->add(['strength' => '500 mg', 'brand_name' => null]);
        $this->add(['dosage_form' => 'syrup', 'base_unit' => 'bottle', 'strength' => '250 mg/5 ml']);
        $this->add(['brand_name' => 'Calpol']);

        $this->assertCount(4, $this->listed());
    }

    public function test_a_code_is_unique_ignoring_case(): void
    {
        $this->catalogue();
        $this->add(['medicine_code' => 'MED-001']);

        $this->postJson('/api/v1/tenant/medicines', $this->payload([
            'strength' => '500 mg',
            'medicine_code' => 'med-001',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('medicine_code');
    }

    /*
    |--------------------------------------------------------------------------
    | Removing and restoring
    |--------------------------------------------------------------------------
    */

    public function test_removing_needs_a_reason_and_records_who_and_why(): void
    {
        $organization = $this->catalogue();
        $medicine = $this->add();

        $this->deleteJson("/api/v1/tenant/medicines/{$medicine['id']}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->deleteJson("/api/v1/tenant/medicines/{$medicine['id']}", ['reason' => 'Entered twice'])
            ->assertOk();

        $this->onTenant($organization, function () use ($medicine, $organization) {
            $row = Medicine::on('organization')->withTrashed()->findOrFail($medicine['id']);

            $this->assertNotNull($row->deleted_at);
            $this->assertSame('Entered twice', $row->deletion_reason);
            $this->assertSame(
                User::on('organization')->where('email', $organization->email)->value('id'),
                $row->deleted_by,
            );

            $log = ActivityLog::on('organization')
                ->where('entity_type', 'Medicine')
                ->where('entity_id', $medicine['id'])
                ->where('event', 'deleted')
                ->sole();

            $this->assertSame(['reason' => 'Entered twice'], $log->after);
        });
    }

    public function test_a_removed_medicine_leaves_every_working_list(): void
    {
        $this->catalogue();
        $medicine = $this->add();
        $this->add(['strength' => '500 mg', 'brand_name' => null]);

        $this->deleteJson("/api/v1/tenant/medicines/{$medicine['id']}", ['reason' => 'Discontinued'])
            ->assertOk();

        $this->assertSame(['Paracetamol 500 mg tablet'], $this->listed());
        $this->assertSame([], $this->listed('?search=Dolo'));
        $this->getJson("/api/v1/tenant/medicines/{$medicine['id']}")->assertNotFound();
    }

    /** Removing frees its identity and its code, as the partial indexes say. */
    public function test_a_removed_medicine_can_be_added_again(): void
    {
        $this->catalogue();
        $medicine = $this->add(['medicine_code' => 'MED-9']);

        $this->deleteJson("/api/v1/tenant/medicines/{$medicine['id']}", ['reason' => 'Wrong maker'])
            ->assertOk();

        $this->add(['medicine_code' => 'MED-9']);
    }

    public function test_restoring_is_its_own_capability(): void
    {
        $this->catalogue();
        $medicine = $this->add();

        $this->deleteJson("/api/v1/tenant/medicines/{$medicine['id']}", ['reason' => 'Test'])
            ->assertOk();

        // No pharmacy module, so no pharmacy.restore — even for the owner.
        $this->getJson('/api/v1/tenant/medicines/removed')->assertForbidden();
        $this->postJson("/api/v1/tenant/medicines/{$medicine['id']}/restore", ['reason' => 'Back'])
            ->assertForbidden();
    }

    public function test_a_removed_medicine_is_restored_with_a_reason(): void
    {
        $organization = $this->catalogue(withPharmacy: true);
        $medicine = $this->add();

        $this->deleteJson("/api/v1/tenant/medicines/{$medicine['id']}", ['reason' => 'Discontinued'])
            ->assertOk();

        $removed = $this->getJson('/api/v1/tenant/medicines/removed')->assertOk()->json('data.0');
        $this->assertSame('Discontinued', $removed['deletion_reason']);
        $this->assertNotNull($removed['deleted_by_name']);

        $this->postJson("/api/v1/tenant/medicines/{$medicine['id']}/restore")
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->postJson("/api/v1/tenant/medicines/{$medicine['id']}/restore", ['reason' => 'Back in stock'])
            ->assertOk()
            ->assertJsonPath('data.deleted_at', null);

        $this->assertCount(1, $this->listed());

        $this->onTenant($organization, function () use ($medicine) {
            $log = ActivityLog::on('organization')
                ->where('entity_type', 'Medicine')
                ->where('entity_id', $medicine['id'])
                ->where('event', 'restored')
                ->sole();

            $this->assertSame(['reason' => 'Back in stock'], $log->after);
        });

        // Restoring something that is not removed is a conflict, not a no-op.
        $this->postJson("/api/v1/tenant/medicines/{$medicine['id']}/restore", ['reason' => 'Again'])
            ->assertStatus(409);
    }

    /** Bringing one back cannot create the duplicate the catalogue refuses. */
    public function test_a_restore_is_refused_while_a_live_duplicate_exists(): void
    {
        $this->catalogue(withPharmacy: true);
        $medicine = $this->add();

        $this->deleteJson("/api/v1/tenant/medicines/{$medicine['id']}", ['reason' => 'Wrong'])
            ->assertOk();

        $this->add();

        $this->postJson("/api/v1/tenant/medicines/{$medicine['id']}/restore", ['reason' => 'Back'])
            ->assertStatus(409);
    }

    /*
    |--------------------------------------------------------------------------
    | Search and configuration
    |--------------------------------------------------------------------------
    */

    public function test_search_finds_by_generic_brand_code_and_strength(): void
    {
        $this->catalogue();
        $this->add(['medicine_code' => 'PCM-650']);
        $this->add([
            'generic_name' => 'Amoxicillin',
            'brand_name' => 'Mox',
            'strength' => '250 mg',
            'dosage_form' => 'capsule',
            'base_unit' => 'capsule',
            'schedule' => 'H',
        ]);

        $this->assertSame(['Mox (Amoxicillin) 250 mg capsule'], $this->listed('?search=amoxi'));
        $this->assertSame(['Mox (Amoxicillin) 250 mg capsule'], $this->listed('?search=mox'));
        $this->assertSame(['Dolo 650 (Paracetamol) 650 mg tablet'], $this->listed('?search=pcm'));
        $this->assertSame(['Dolo 650 (Paracetamol) 650 mg tablet'], $this->listed('?search=650 mg'));
        $this->assertCount(1, $this->listed('?schedule=H'));
    }

    public function test_medicines_appear_among_the_configurable_entities(): void
    {
        $this->catalogue();

        $this->assertContains(
            'medicine',
            $this->getJson('/api/v1/tenant/settings/fields')->assertOk()->json('data.*.entity'),
        );
    }

    /**
     * A fixed list can be relabelled, never given a value the database
     * refuses.
     */
    public function test_a_fixed_list_keeps_its_values_whatever_the_settings_say(): void
    {
        $this->catalogue();

        $fields = $this->getJson('/api/v1/tenant/settings/fields/medicine')
            ->assertOk()
            ->json('data.fields');

        $payload = fn (callable $change) => [
            'fields' => array_map(fn (array $field, int $position) => $change([
                'field_key' => $field['key'],
                'label' => $field['label'],
                'placeholder' => $field['placeholder'] ?? null,
                'is_custom' => false,
                'data_type' => null,
                'options' => $field['options'] ?? null,
                'is_required' => $field['required'],
                'show_in_form' => $field['show_in_form'],
                'show_in_table' => $field['in_table'],
                'sort_order' => $position,
            ]), $fields, array_keys($fields)),
        ];

        // Adding a value is refused.
        $this->putJson('/api/v1/tenant/settings/fields/medicine', $payload(
            fn (array $row) => $row['field_key'] === 'dosage_form'
                ? [...$row, 'options' => [...$row['options'], ['value' => 'elixir', 'label' => 'Elixir']]]
                : $row
        ))->assertStatus(422);

        // Renaming one is allowed, and the value underneath is unchanged.
        $this->putJson('/api/v1/tenant/settings/fields/medicine', $payload(
            fn (array $row) => $row['field_key'] === 'dosage_form'
                ? [...$row, 'options' => array_map(
                    fn (array $option) => $option['value'] === 'tablet'
                        ? ['value' => 'tablet', 'label' => 'Tab']
                        : $option,
                    $row['options'],
                )]
                : $row
        ))->assertOk();

        $dosageForm = collect($this->getJson('/api/v1/tenant/medicines/fields')->assertOk()->json('data'))
            ->firstWhere('key', 'dosage_form');

        $this->assertSame(Medicine::DOSAGE_FORMS, array_column($dosageForm['options'], 'value'));
        $this->assertSame('Tab', $dosageForm['options'][0]['label']);
    }
}
