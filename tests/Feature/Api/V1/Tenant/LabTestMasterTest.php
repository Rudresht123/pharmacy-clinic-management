<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\LabBranchPrice;
use App\Models\Tenant\LabDepartment;
use App\Models\Tenant\LabReferenceRange;
use App\Models\Tenant\LabTest;
use App\Models\Tenant\LabTestCatalog;
use App\Models\Tenant\LabTestParameter;
use App\Models\Tenant\LabUnit;
use App\Models\Tenant\Location;
use App\Services\Laboratory\ReferenceRanges;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * Phase 2 of the LIS: the test master — tests, their parameters, reference
 * ranges and branch prices — and the move of the old price list into it.
 */
class LabTestMasterTest extends TenantTestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private int $branch;

    private int $otherBranch;

    private int $haematology;

    private int $gramsPerDecilitre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');

        $this->organization = $this->provisionOrganization('LTM');
        $this->grantModule($this->organization, 'laboratory');

        [$this->branch, $this->otherBranch, $this->haematology, $this->gramsPerDecilitre] = $this->onTenant(
            $this->organization,
            fn () => [
                Location::on('organization')->create(['name' => 'Main Lab', 'code' => 'LTM-1', 'type' => Location::CLINIC, 'is_active' => true])->id,
                Location::on('organization')->create(['name' => 'Second Lab', 'code' => 'LTM-2', 'type' => Location::CLINIC, 'is_active' => true])->id,
                LabDepartment::on('organization')->create(['name' => 'Haematology'])->id,
                LabUnit::on('organization')->create(['symbol' => 'g/dL'])->id,
            ],
        );

        $this->placeStaffAt($this->organization, $this->branch);
    }

    /** @param list<string> $ticks */
    private function staffHolding(array $ticks): void
    {
        $this->setStaffCapabilities($this->organization, $ticks);
        $this->signInAsStaff($this->organization);
    }

    /** A CBC-like test with three kinds of answer. @return array<string, mixed> */
    private function cbc(array $overrides = []): array
    {
        return [
            'code' => 'cbc',
            'name' => 'Complete Blood Count',
            'department_id' => $this->haematology,
            'price' => 350,
            'tat_hours' => 6,
            'parameters' => [
                ['name' => 'Haemoglobin', 'code' => 'hb', 'section' => 'Red cells', 'result_type' => 'numeric', 'unit_id' => $this->gramsPerDecilitre, 'decimals' => 1],
                ['name' => 'Blood group', 'result_type' => 'choice', 'choices' => [['value' => 'A+'], ['value' => 'B+'], ['value' => 'O-', 'abnormal' => false]]],
                ['name' => 'Malaria antigen', 'result_type' => 'positive_negative', 'normal_value' => 'Negative'],
            ],
            ...$overrides,
        ];
    }

    private function createCbc(): array
    {
        $this->signInAsOwner($this->organization);

        return $this->postJson('/api/v1/tenant/lab/tests', $this->cbc())->assertCreated()->json('data');
    }

    /* ------------------------------------------------------------------
     | Tests and parameters
     |------------------------------------------------------------------ */

    public function test_a_test_is_saved_with_its_parameters(): void
    {
        $test = $this->createCbc();

        $this->assertSame('CBC', $test['code']);
        $this->assertSame('Haematology', $test['department_name']);
        $this->assertEquals(350, $test['price']);
        $this->assertCount(3, $test['parameters']);
        $this->assertSame('g/dL', $test['parameters'][0]['unit_symbol']);
        $this->assertSame('HB', $test['parameters'][0]['code']);
        $this->assertSame(1, $test['parameters'][0]['decimals']);
        $this->assertSame('A+', $test['parameters'][1]['choices'][0]['value']);
        // A text answer carries no unit, and a number no "normal answer".
        $this->assertNull($test['parameters'][2]['unit_id']);
        $this->assertSame('Negative', $test['parameters'][2]['normal_value']);

        $this->getJson("/api/v1/tenant/lab/tests/{$test['id']}")
            ->assertOk()
            ->assertJsonPath('data.parameters.0.ranges', [])
            ->assertJsonPath('data.branch_prices', []);
    }

    public function test_a_test_needs_a_department_a_unique_code_and_a_parameter(): void
    {
        $this->createCbc();

        $this->postJson('/api/v1/tenant/lab/tests', $this->cbc(['department_id' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('department_id');

        $this->postJson('/api/v1/tenant/lab/tests', $this->cbc(['code' => 'CbC']))
            ->assertStatus(422)->assertJsonPath('errors.code.0', 'Another test already has the code CBC.');

        $this->postJson('/api/v1/tenant/lab/tests', $this->cbc(['code' => 'ESR', 'parameters' => []]))
            ->assertStatus(422)->assertJsonPath('errors.parameters.0', 'A test read as values needs at least one parameter.');

        // A written report may stand without parameters.
        $this->postJson('/api/v1/tenant/lab/tests', $this->cbc([
            'code' => 'HISTO', 'result_layout' => 'narrative', 'parameters' => [],
        ]))->assertCreated();

        $this->postJson('/api/v1/tenant/lab/tests', $this->cbc([
            'code' => 'X1',
            'parameters' => [
                ['name' => 'A', 'code' => 'dup', 'result_type' => 'numeric'],
                ['name' => 'B', 'code' => 'DUP', 'result_type' => 'numeric'],
            ],
        ]))->assertStatus(422)->assertJsonValidationErrors('parameters.1.code');

        $this->postJson('/api/v1/tenant/lab/tests', $this->cbc([
            'code' => 'X2',
            'parameters' => [['name' => 'Group', 'result_type' => 'choice', 'choices' => [['value' => 'Only one']]]],
        ]))->assertStatus(422)->assertJsonPath('errors', fn ($errors) => isset($errors['parameters.0.choices']));
    }

    public function test_editing_keeps_matched_parameters_and_removes_the_rest(): void
    {
        $test = $this->createCbc();
        [$hb, $group] = [$test['parameters'][0]['id'], $test['parameters'][1]['id']];

        $saved = $this->putJson("/api/v1/tenant/lab/tests/{$test['id']}", [
            'name' => 'CBC with ESR',
            'parameters' => [
                ['id' => $hb, 'name' => 'Hemoglobin', 'result_type' => 'numeric', 'unit_id' => $this->gramsPerDecilitre, 'decimals' => 1],
                ['name' => 'ESR', 'result_type' => 'numeric'],
            ],
        ])->assertOk()->json('data');

        $this->assertSame('CBC with ESR', $saved['name']);
        $this->assertSame([$hb, 'ESR'], [$saved['parameters'][0]['id'], $saved['parameters'][1]['name']]);
        $this->assertSame('Hemoglobin', $saved['parameters'][0]['name']);

        $removed = $this->onTenant($this->organization, fn () => LabTestParameter::withTrashed()->find($group));
        $this->assertNotNull($removed->deleted_at);
        $this->assertSame('Removed from the test', $removed->deletion_reason);

        // A parameter of another test is not this test's to edit.
        $other = $this->postJson('/api/v1/tenant/lab/tests', $this->cbc(['code' => 'TSH']))->json('data');
        $this->putJson("/api/v1/tenant/lab/tests/{$test['id']}", [
            'parameters' => [['id' => $other['parameters'][0]['id'], 'name' => 'Stolen', 'result_type' => 'numeric']],
        ])->assertStatus(422)->assertJsonValidationErrors('parameters.0.id');
    }

    public function test_a_removed_test_leaves_the_menu(): void
    {
        $test = $this->createCbc();

        $this->deleteJson("/api/v1/tenant/lab/tests/{$test['id']}")->assertStatus(422);
        $this->deleteJson("/api/v1/tenant/lab/tests/{$test['id']}", ['reason' => 'No longer offered'])->assertOk();

        $this->getJson('/api/v1/tenant/lab/tests')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/tenant/lab/orderables')->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/tenant/lab/tests/{$test['id']}")->assertNotFound();

        // Its code is free again.
        $this->postJson('/api/v1/tenant/lab/tests', $this->cbc())->assertCreated();
    }

    public function test_the_list_filters_and_searches(): void
    {
        $this->createCbc();
        $this->postJson('/api/v1/tenant/lab/tests', $this->cbc(['code' => 'TSH', 'name' => 'Thyroid stimulating hormone', 'is_active' => false]))->assertCreated();

        $this->getJson('/api/v1/tenant/lab/tests?search=thyroid')->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'TSH');
        $this->getJson('/api/v1/tenant/lab/tests?active=0')->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/tenant/lab/tests?department_id={$this->haematology}")
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.parameters_count', 3)
            ->assertJsonPath('meta.total', 2);

        // The picker offers only what is switched on, an exact code first.
        $this->getJson('/api/v1/tenant/lab/orderables?search=cbc')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'CBC')
            ->assertJsonPath('data.0.type', 'test');
    }

    /* ------------------------------------------------------------------
     | Reference ranges
     |------------------------------------------------------------------ */

    public function test_ranges_are_saved_by_sex_and_age_band(): void
    {
        $test = $this->createCbc();
        $hb = $test['parameters'][0]['id'];

        $ranges = $this->putJson("/api/v1/tenant/lab/parameters/{$hb}/ranges", ['ranges' => [
            ['sex' => 'male', 'age_min' => 18, 'age_unit' => 'years', 'low' => '13', 'high' => '17', 'critical_low' => '7', 'critical_high' => '20'],
            ['sex' => 'female', 'age_min' => 18, 'age_unit' => 'years', 'low' => '12', 'high' => '15.5'],
            ['sex' => 'any', 'age_max' => 18, 'age_unit' => 'years', 'low' => '11', 'high' => '14'],
        ]])->assertOk()->json('data');

        $this->assertSame('Male, 18+ years', $ranges[0]['applies_to']);
        $this->assertSame('13 – 17', $ranges[0]['printed']);
        $this->assertSame('15.5', $ranges[1]['high']);
        $this->assertSame('Anyone, under 18 years', $ranges[2]['applies_to']);

        $this->getJson("/api/v1/tenant/lab/tests/{$test['id']}")
            ->assertJsonCount(3, 'data.parameters.0.ranges');

        // Replacing the set: one kept and changed, the others removed with a reason.
        $this->putJson("/api/v1/tenant/lab/parameters/{$hb}/ranges", ['ranges' => [
            ['id' => $ranges[0]['id'], 'sex' => 'any', 'low' => '12', 'high' => '17'],
        ]])->assertOk()->assertJsonCount(1, 'data');

        $this->assertSame(2, $this->onTenant($this->organization, fn () => LabReferenceRange::onlyTrashed()->count()));
    }

    public function test_ranges_that_overlap_or_contradict_are_refused(): void
    {
        $test = $this->createCbc();
        [$hb, $group, $malaria] = array_column($test['parameters'], 'id');

        // Two female bands covering 50–60 years.
        $this->putJson("/api/v1/tenant/lab/parameters/{$hb}/ranges", ['ranges' => [
            ['sex' => 'female', 'age_min' => 12, 'age_max' => 60, 'age_unit' => 'years', 'low' => '12', 'high' => '15'],
            ['sex' => 'female', 'age_min' => 50, 'age_unit' => 'years', 'low' => '11', 'high' => '15'],
        ]])->assertStatus(422)->assertJsonValidationErrors('ranges.1.age_min');

        // A band in months touching one in years does not overlap.
        $this->putJson("/api/v1/tenant/lab/parameters/{$hb}/ranges", ['ranges' => [
            ['sex' => 'any', 'age_max' => 12, 'age_unit' => 'months', 'low' => '10', 'high' => '14'],
            ['sex' => 'any', 'age_min' => 1, 'age_unit' => 'years', 'low' => '11', 'high' => '16'],
        ]])->assertOk();

        // Critical limits sit outside the normal ones.
        $this->putJson("/api/v1/tenant/lab/parameters/{$hb}/ranges", ['ranges' => [
            ['sex' => 'any', 'low' => '12', 'high' => '15', 'critical_low' => '13'],
        ]])->assertStatus(422)->assertJsonValidationErrors('ranges.0.critical_low');

        // A band must end after it starts, and say its unit.
        $this->putJson("/api/v1/tenant/lab/parameters/{$hb}/ranges", ['ranges' => [
            ['sex' => 'any', 'age_min' => 10, 'age_max' => 5, 'age_unit' => 'years', 'low' => '1'],
            ['sex' => 'male', 'age_min' => 10, 'low' => '1'],
        ]])->assertStatus(422)->assertJsonValidationErrors(['ranges.0.age_max', 'ranges.1.age_unit']);

        // A numeric range needs a limit; a word answer takes no numbers.
        $this->putJson("/api/v1/tenant/lab/parameters/{$hb}/ranges", ['ranges' => [['sex' => 'any']]])
            ->assertStatus(422)->assertJsonValidationErrors('ranges.0.low');

        $this->putJson("/api/v1/tenant/lab/parameters/{$malaria}/ranges", ['ranges' => [['sex' => 'any', 'low' => '1']]])
            ->assertStatus(422)->assertJsonValidationErrors(['ranges.0.low', 'ranges.0.normal_text']);

        $this->putJson("/api/v1/tenant/lab/parameters/{$malaria}/ranges", ['ranges' => [['sex' => 'any', 'normal_text' => 'Negative']]])
            ->assertOk()->assertJsonPath('data.0.printed', 'Negative');

        // A parameter with ranges keeps its kind of answer.
        $this->putJson("/api/v1/tenant/lab/tests/{$test['id']}", ['parameters' => [
            ['id' => $hb, 'name' => 'Haemoglobin', 'result_type' => 'text'],
            ['id' => $group, 'name' => 'Blood group', 'result_type' => 'choice', 'choices' => [['value' => 'A'], ['value' => 'B']]],
            ['id' => $malaria, 'name' => 'Malaria antigen', 'result_type' => 'positive_negative'],
        ]])->assertStatus(422)->assertJsonValidationErrors('parameters.0.result_type');
    }

    /** The one range a patient falls in — or none, never a guess. */
    public function test_the_right_range_is_found_for_a_patient(): void
    {
        $test = $this->createCbc();
        $hb = $test['parameters'][0]['id'];

        $this->putJson("/api/v1/tenant/lab/parameters/{$hb}/ranges", ['ranges' => [
            ['sex' => 'male', 'age_min' => 18, 'age_unit' => 'years', 'low' => '13', 'high' => '17'],
            ['sex' => 'female', 'age_min' => 18, 'age_unit' => 'years', 'low' => '12', 'high' => '15.5'],
            ['sex' => 'any', 'age_max' => 18, 'age_unit' => 'years', 'low' => '11', 'high' => '14'],
        ]])->assertOk();

        $this->onTenant($this->organization, function () use ($hb) {
            $parameter = LabTestParameter::query()->findOrFail($hb);
            $ranges = app(ReferenceRanges::class);
            $today = Carbon::parse('2026-11-08');
            $low = fn (?string $sex, ?string $dob) => $ranges
                ->resolve($parameter, $sex, $dob ? Carbon::parse($dob) : null, $today)
                ?->low;

            $this->assertEquals(12, $low('female', '1992-03-01'));      // 34: her own range
            $this->assertEquals(13, $low('male', '1990-01-01'));        // 36: his
            $this->assertEquals(11, $low('male', '2016-05-05'));        // 10: no boy's range, so anyone's
            $this->assertEquals(13, $low('male', '2008-11-08'));        // 18 today: an adult
            $this->assertEquals(11, $low('male', '2008-11-09'));        // 18 tomorrow: not yet
            $this->assertNull($low('other', '1990-01-01'));             // anyone's range is under 18 only
            $this->assertNull($low('female', null));                    // no birth date, every range has an age
        });
    }

    public function test_ranges_are_a_tick_of_their_own(): void
    {
        $test = $this->createCbc();
        $hb = $test['parameters'][0]['id'];
        $range = ['ranges' => [['sex' => 'any', 'low' => '12', 'high' => '16']]];

        $this->staffHolding(['laboratory.catalog_view']);
        $this->getJson('/api/v1/tenant/lab/tests')->assertOk();
        $this->getJson("/api/v1/tenant/lab/tests/{$test['id']}")->assertOk();
        $this->getJson("/api/v1/tenant/lab/parameters/{$hb}/ranges")->assertOk();
        $this->getJson('/api/v1/tenant/lab/masters/departments')->assertOk();
        $this->postJson('/api/v1/tenant/lab/tests', $this->cbc(['code' => 'ESR']))->assertForbidden();
        $this->putJson("/api/v1/tenant/lab/parameters/{$hb}/ranges", $range)->assertForbidden();

        $this->staffHolding(['laboratory.catalog_manage']);
        $this->postJson('/api/v1/tenant/lab/tests', $this->cbc(['code' => 'ESR']))->assertCreated();
        $this->putJson("/api/v1/tenant/lab/parameters/{$hb}/ranges", $range)->assertForbidden();

        $this->staffHolding(['laboratory.ranges_manage']);
        $this->putJson("/api/v1/tenant/lab/parameters/{$hb}/ranges", $range)->assertOk();
        $this->postJson('/api/v1/tenant/lab/tests', $this->cbc(['code' => 'TSH']))->assertForbidden();

        $this->staffHolding(['laboratory.view']);
        $this->getJson('/api/v1/tenant/lab/tests')->assertForbidden();
    }

    public function test_nothing_clinical_is_seeded(): void
    {
        $this->onTenant($this->organization, function () {
            $this->assertSame(0, LabTest::withTrashed()->count());
            $this->assertSame(0, LabReferenceRange::withTrashed()->count());
        });
    }

    /* ------------------------------------------------------------------
     | Prices
     |------------------------------------------------------------------ */

    public function test_a_branch_charges_its_own_price_where_it_has_one(): void
    {
        $test = $this->createCbc();

        $this->putJson("/api/v1/tenant/lab/tests/{$test['id']}/prices", ['prices' => [
            ['location_id' => $this->branch, 'price' => 300],
        ]])->assertOk()->assertJsonPath('data.branch_prices.0.location_name', 'Main Lab');

        $this->getJson("/api/v1/tenant/lab/orderables?location_id={$this->branch}")
            ->assertJsonPath('data.0.price', 300)
            ->assertJsonPath('data.0.branch_price', true);

        $this->getJson("/api/v1/tenant/lab/orderables?location_id={$this->otherBranch}")
            ->assertJsonPath('data.0.price', 350)
            ->assertJsonPath('data.0.branch_price', false);

        $this->putJson("/api/v1/tenant/lab/tests/{$test['id']}/prices", ['prices' => [
            ['location_id' => $this->branch, 'price' => 300],
            ['location_id' => $this->branch, 'price' => 310],
        ]])->assertStatus(422)->assertJsonValidationErrors('prices.1.location_id');

        // Somebody who works at one branch prices only that one.
        $this->staffHolding(['laboratory.catalog_manage']);

        $this->putJson("/api/v1/tenant/lab/tests/{$test['id']}/prices", ['prices' => [
            ['location_id' => $this->branch, 'price' => 290],
            ['location_id' => $this->otherBranch, 'price' => 200],
        ]])->assertForbidden();

        $this->putJson("/api/v1/tenant/lab/tests/{$test['id']}/prices", ['prices' => [
            ['location_id' => $this->branch, 'price' => 290, 'tax_percent' => 5],
        ]])->assertOk();

        $this->assertEquals(290, $this->onTenant($this->organization, fn () => LabBranchPrice::query()->value('price')));
    }

    /* ------------------------------------------------------------------
     | The lists the test master uses
     |------------------------------------------------------------------ */

    public function test_a_list_row_a_test_uses_is_not_removed(): void
    {
        $test = $this->createCbc();

        $this->deleteJson("/api/v1/tenant/lab/masters/departments/{$this->haematology}", ['reason' => 'Merged'])
            ->assertStatus(409)
            ->assertJsonPath('message', fn ($message) => str_contains($message, '1 test'));

        $this->deleteJson("/api/v1/tenant/lab/masters/units/{$this->gramsPerDecilitre}", ['reason' => 'Wrong'])
            ->assertStatus(409)
            ->assertJsonPath('message', fn ($message) => str_contains($message, '1 test parameter'));

        // Once the test is gone, its parameters no longer hold the unit.
        $this->deleteJson("/api/v1/tenant/lab/tests/{$test['id']}", ['reason' => 'Not offered'])->assertOk();
        $this->deleteJson("/api/v1/tenant/lab/masters/units/{$this->gramsPerDecilitre}", ['reason' => 'Wrong'])->assertOk();
    }

    /* ------------------------------------------------------------------
     | The old price list moves across
     |------------------------------------------------------------------ */

    public function test_the_old_price_list_moves_into_the_test_master(): void
    {
        $ids = $this->onTenant($this->organization, function () {
            $cbc = LabTestCatalog::on('organization')->create(['name' => 'CBC', 'code' => 'CBC', 'price' => 300, 'tax_percent' => 0, 'active' => true]);
            $override = LabTestCatalog::on('organization')->create(['location_id' => $this->branch, 'name' => 'CBC', 'code' => 'cbc', 'price' => 280, 'tax_percent' => 5, 'active' => true]);
            $esr = LabTestCatalog::on('organization')->create(['location_id' => $this->otherBranch, 'name' => 'ESR', 'price' => 120, 'tax_percent' => 0, 'active' => true]);
            $old = LabTestCatalog::on('organization')->create(['name' => 'Old test', 'code' => 'OLD', 'price' => 50, 'tax_percent' => 0, 'active' => true]);
            $old->delete();

            $previous = DB::getDefaultConnection();
            DB::setDefaultConnection('organization');

            try {
                (require base_path('database/migrations/organization/2026_11_08_000100_move_lab_catalogue_into_the_test_master.php'))->up();
            } finally {
                DB::setDefaultConnection($previous);
            }

            return compact('cbc', 'override', 'esr', 'old');
        });

        $this->onTenant($this->organization, function () use ($ids) {
            $cbc = LabTest::query()->where('legacy_catalog_id', $ids['cbc']->id)->firstOrFail();
            $this->assertSame('CBC', $cbc->code);
            $this->assertEquals(300, $cbc->price);
            $this->assertSame('General', $cbc->department->name);
            $this->assertSame(['text'], $cbc->parameters->pluck('result_type')->all());

            // The branch's own row became that branch's price for the same test.
            $price = LabBranchPrice::query()->where('location_id', $this->branch)->firstOrFail();
            $this->assertSame($cbc->id, $price->lab_test_id);
            $this->assertEquals(280, $price->price);
            $this->assertEquals(5, $price->tax_percent);

            // A branch-only row became a test of its own, priced as it was there.
            $esr = LabTest::query()->where('legacy_catalog_id', $ids['esr']->id)->firstOrFail();
            $this->assertSame('T'.$ids['esr']->id, $esr->code);
            $this->assertEquals(120, LabBranchPrice::query()->where('lab_test_id', $esr->id)->value('price'));

            // A removed row moved across removed.
            $this->assertNotNull(LabTest::onlyTrashed()->where('legacy_catalog_id', $ids['old']->id)->first());
        });
    }

    /* ------------------------------------------------------------------
     | Sold on its own, and kept to its own organisation
     |------------------------------------------------------------------ */

    public function test_a_lab_only_organisation_builds_its_tests(): void
    {
        $this->revokeFoundationModules($this->organization);
        $this->grantModule($this->organization, 'customers');
        $this->grantModule($this->organization, 'laboratory');

        $test = $this->createCbc();

        $this->putJson("/api/v1/tenant/lab/parameters/{$test['parameters'][0]['id']}/ranges", ['ranges' => [
            ['sex' => 'any', 'low' => '12', 'high' => '16'],
        ]])->assertOk();

        $this->getJson('/api/v1/tenant/lab/orderables')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_another_organisation_never_sees_these_tests(): void
    {
        $test = $this->createCbc();

        $other = $this->provisionOrganization('LTX');
        $this->grantModule($other, 'laboratory');
        $this->signInAsOwner($other);

        $this->getJson('/api/v1/tenant/lab/tests')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/tenant/lab/tests/{$test['id']}")->assertNotFound();
        $this->putJson("/api/v1/tenant/lab/parameters/{$test['parameters'][0]['id']}/ranges", ['ranges' => []])->assertNotFound();
    }
}
