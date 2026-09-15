<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\ActivityLog;
use App\Models\Tenant\Location;
use App\Models\Tenant\Medicine;
use App\Models\Tenant\PharmacyStore;
use App\Models\Tenant\StoreMedicine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

/**
 * Pharmacy Phase 2 — stores, the default store, and what each stocks.
 *
 * Asserted: one default per branch whatever happens to the stores; a store
 * is reachable only by someone working at its branch, with the capability
 * held there (PharmacyStorePolicy); removing and restoring say why; and a
 * store's medicine levels stay inside what the table accepts.
 */
class PharmacyStoreTest extends TenantTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The pharmacy modules arrive the way a deploy adds them.
        $this->artisan('modules:sync');
    }

    /**
     * An organization with the pharmacy, two branches, and the staff member
     * working at the first. Signed in as the owner.
     *
     * @return array{0: Organization, 1: int, 2: int}
     */
    private function network(): array
    {
        $organization = $this->provisionOrganization('S');
        $this->grantModule($organization, 'medicines');
        $this->grantModule($organization, 'pharmacy');

        [$here, $there] = $this->onTenant($organization, fn () => [
            Location::on('organization')->create([
                'name' => 'Noida', 'code' => 'S-NOI', 'type' => Location::CLINIC, 'is_active' => true,
            ])->id,
            Location::on('organization')->create([
                'name' => 'Delhi', 'code' => 'S-DEL', 'type' => Location::CLINIC, 'is_active' => true,
            ])->id,
        ]);

        $this->placeStaffAt($organization, $here);
        $this->signInAsOwner($organization);

        return [$organization, $here, $there];
    }

    private function addStore(int $locationId, array $overrides = []): array
    {
        return $this->postJson('/api/v1/tenant/pharmacy-stores', array_merge([
            'location_id' => $locationId,
            'name' => 'Main Counter',
            'code' => 'MC-'.$locationId.'-'.uniqid(),
            'store_type' => PharmacyStore::OPD_COUNTER,
        ], $overrides))->assertCreated()->json('data');
    }

    /** @return array<int, bool> store id => is_default, for one branch's live stores */
    private function defaults(Organization $organization, int $locationId): array
    {
        return $this->onTenant($organization, fn () => PharmacyStore::on('organization')
            ->where('location_id', $locationId)
            ->orderBy('id')
            ->pluck('is_default', 'id')
            ->all());
    }

    private function medicine(Organization $organization, string $generic = 'Paracetamol'): int
    {
        return $this->onTenant($organization, fn () => Medicine::on('organization')->create([
            'generic_name' => $generic,
            'strength' => '500 mg',
            'dosage_form' => 'tablet',
            'base_unit' => 'tablet',
        ])->id);
    }

    /*
    |--------------------------------------------------------------------------
    | Entitlement
    |--------------------------------------------------------------------------
    */

    public function test_without_the_pharmacy_module_stores_are_refused(): void
    {
        $organization = $this->provisionOrganization('S');
        $this->grantModule($organization, 'medicines');
        $this->signInAsOwner($organization);

        $this->getJson('/api/v1/tenant/pharmacy-stores')->assertForbidden();
        $this->postJson('/api/v1/tenant/pharmacy-stores', [])->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | The default store
    |--------------------------------------------------------------------------
    */

    public function test_the_first_store_at_a_branch_becomes_its_default(): void
    {
        [, $here, $there] = $this->network();

        $first = $this->addStore($here);
        $second = $this->addStore($here, ['name' => 'Store Room', 'store_type' => PharmacyStore::CENTRAL]);
        $elsewhere = $this->addStore($there);

        $this->assertTrue($first['is_default']);
        $this->assertFalse($second['is_default']);
        // Another branch has its own default.
        $this->assertTrue($elsewhere['is_default']);
    }

    public function test_making_another_store_the_default_takes_it_from_the_old_one(): void
    {
        [$organization, $here] = $this->network();

        $first = $this->addStore($here);
        $second = $this->addStore($here, ['name' => 'Store Room']);

        // The log cannot be emptied, so only what the move writes is counted.
        $mark = (int) $this->onTenant($organization, fn () => ActivityLog::on('organization')->max('id'));

        $this->putJson("/api/v1/tenant/pharmacy-stores/{$second['id']}", ['is_default' => true])
            ->assertOk()
            ->assertJsonPath('data.is_default', true);

        $this->assertSame([$first['id'] => false, $second['id'] => true], $this->defaults($organization, $here));

        // Both halves of the move are on the stores' histories.
        $moves = $this->onTenant($organization, fn () => ActivityLog::on('organization')
            ->where('id', '>', $mark)
            ->where('entity_type', 'PharmacyStore')
            ->where('event', 'updated')
            ->orderBy('id')
            ->get(['entity_id', 'after'])
            ->map(fn (ActivityLog $log) => [$log->entity_id, $log->after['is_default'] ?? null])
            ->all());

        $this->assertSame([[$first['id'], false], [$second['id'], true]], $moves);
    }

    public function test_the_default_cannot_simply_be_switched_off_or_deactivated(): void
    {
        [, $here] = $this->network();

        $store = $this->addStore($here);

        $this->putJson("/api/v1/tenant/pharmacy-stores/{$store['id']}", ['is_default' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_default');

        $this->putJson("/api/v1/tenant/pharmacy-stores/{$store['id']}", ['is_active' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_active');
    }

    public function test_removing_the_default_hands_it_to_the_next_active_store(): void
    {
        [$organization, $here] = $this->network();

        $first = $this->addStore($here);
        $second = $this->addStore($here, ['name' => 'Store Room']);

        $this->deleteJson("/api/v1/tenant/pharmacy-stores/{$first['id']}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->deleteJson("/api/v1/tenant/pharmacy-stores/{$first['id']}", ['reason' => 'Counter closed'])
            ->assertOk();

        $this->assertSame([$second['id'] => true], $this->defaults($organization, $here));

        $listed = array_column($this->getJson('/api/v1/tenant/pharmacy-stores')->assertOk()->json('data'), 'id');
        $this->assertSame([$second['id']], $listed);
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    public function test_codes_are_unique_ignoring_case_and_types_come_from_the_list(): void
    {
        [, $here] = $this->network();

        $this->addStore($here, ['code' => 'OPD-1']);

        $this->postJson('/api/v1/tenant/pharmacy-stores', [
            'location_id' => $here,
            'name' => 'Second',
            'code' => 'OPD-2',
            'store_type' => 'kiosk',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['store_type']);

        $this->postJson('/api/v1/tenant/pharmacy-stores', [
            'location_id' => $here,
            'name' => 'Second',
            'code' => 'opd-1',
            'store_type' => PharmacyStore::RETAIL,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    public function test_a_store_stays_at_its_branch(): void
    {
        [, $here, $there] = $this->network();

        $store = $this->addStore($here);

        $this->putJson("/api/v1/tenant/pharmacy-stores/{$store['id']}", ['location_id' => $there])
            ->assertStatus(422)
            ->assertJsonValidationErrors('location_id');
    }

    /*
    |--------------------------------------------------------------------------
    | The Policy: the store's own branch
    |--------------------------------------------------------------------------
    */

    public function test_staff_see_only_the_stores_at_their_own_branch(): void
    {
        [$organization, $here, $there] = $this->network();

        $mine = $this->addStore($here);
        $theirs = $this->addStore($there);

        $this->setStaffCapabilities($organization, ['pharmacy.view']);
        $this->signInAsStaff($organization);

        $listed = array_column($this->getJson('/api/v1/tenant/pharmacy-stores')->assertOk()->json('data'), 'id');
        $this->assertSame([$mine['id']], $listed);

        $this->getJson("/api/v1/tenant/pharmacy-stores/{$mine['id']}")->assertOk();
        $this->getJson("/api/v1/tenant/pharmacy-stores/{$theirs['id']}")->assertForbidden();
        $this->getJson("/api/v1/tenant/pharmacy-stores/{$theirs['id']}/medicines")->assertForbidden();
    }

    public function test_managing_stores_reaches_only_the_managers_own_branch(): void
    {
        [$organization, $here, $there] = $this->network();

        $mine = $this->addStore($here);
        $theirs = $this->addStore($there);

        $this->setStaffCapabilities($organization, ['pharmacy.view', 'pharmacy.stores']);
        $this->signInAsStaff($organization);

        $this->putJson("/api/v1/tenant/pharmacy-stores/{$mine['id']}", ['name' => 'Renamed'])->assertOk();
        $this->putJson("/api/v1/tenant/pharmacy-stores/{$theirs['id']}", ['name' => 'Renamed'])->assertForbidden();

        $this->postJson('/api/v1/tenant/pharmacy-stores', [
            'location_id' => $there,
            'name' => 'Not mine',
            'code' => 'NM-1',
            'store_type' => PharmacyStore::RETAIL,
        ])->assertForbidden();
    }

    /** A branch that switched the pharmacy off has no stores to reach, even for the owner. */
    public function test_a_branch_without_the_pharmacy_refuses_its_stores(): void
    {
        [$organization, , $there] = $this->network();

        $store = $this->addStore($there);
        $this->disableModuleAtBranch($organization, $there, 'pharmacy');

        $this->getJson("/api/v1/tenant/pharmacy-stores/{$store['id']}")->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Restore
    |--------------------------------------------------------------------------
    */

    public function test_a_removed_store_is_restored_with_a_reason(): void
    {
        [$organization, $here] = $this->network();

        $first = $this->addStore($here);
        $this->addStore($here, ['name' => 'Store Room']);

        $this->deleteJson("/api/v1/tenant/pharmacy-stores/{$first['id']}", ['reason' => 'Renovation'])
            ->assertOk();

        $removed = $this->getJson('/api/v1/tenant/pharmacy-stores/removed')->assertOk()->json('data.0');
        $this->assertSame('Renovation', $removed['deletion_reason']);

        $this->postJson("/api/v1/tenant/pharmacy-stores/{$first['id']}/restore", ['reason' => 'Reopened'])
            ->assertOk()
            // The branch has a default again, so this one comes back without it.
            ->assertJsonPath('data.is_default', false)
            ->assertJsonPath('data.deleted_at', null);

        $this->assertCount(1, array_filter($this->defaults($organization, $here)));
    }

    public function test_a_restore_is_refused_when_its_code_was_taken(): void
    {
        [, $here] = $this->network();

        $store = $this->addStore($here, ['code' => 'OPD-1']);

        $this->deleteJson("/api/v1/tenant/pharmacy-stores/{$store['id']}", ['reason' => 'Closed'])->assertOk();

        $this->addStore($here, ['code' => 'opd-1']);

        $this->postJson("/api/v1/tenant/pharmacy-stores/{$store['id']}/restore", ['reason' => 'Back'])
            ->assertStatus(409);
    }

    /*
    |--------------------------------------------------------------------------
    | What a store stocks
    |--------------------------------------------------------------------------
    */

    public function test_a_store_is_given_levels_for_the_medicines_it_stocks(): void
    {
        [$organization, $here] = $this->network();

        $store = $this->addStore($here);
        $paracetamol = $this->medicine($organization);
        $cetirizine = $this->medicine($organization, 'Cetirizine');

        $this->putJson("/api/v1/tenant/pharmacy-stores/{$store['id']}/medicines", ['medicines' => [
            ['medicine_id' => $paracetamol, 'reorder_level' => 100, 'minimum_stock_level' => 50, 'maximum_stock_level' => 500],
            ['medicine_id' => $cetirizine, 'reorder_level' => 20, 'minimum_stock_level' => 10, 'maximum_stock_level' => null],
        ]])->assertOk();

        $this->getJson("/api/v1/tenant/pharmacy-stores/{$store['id']}/medicines")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // Saving one again updates it rather than adding a second row.
        $this->putJson("/api/v1/tenant/pharmacy-stores/{$store['id']}/medicines", ['medicines' => [
            ['medicine_id' => $paracetamol, 'reorder_level' => 120, 'minimum_stock_level' => 50, 'maximum_stock_level' => 500],
        ]])->assertOk()->assertJsonPath('data.0.reorder_level', 120);

        $this->assertSame(2, $this->onTenant($organization, fn () => StoreMedicine::on('organization')->count()));

        $this->getJson("/api/v1/tenant/pharmacy-stores/{$store['id']}/medicines?search=cetiri")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_levels_stay_inside_what_the_table_accepts(): void
    {
        [$organization, $here] = $this->network();

        $store = $this->addStore($here);
        $medicine = $this->medicine($organization);

        $this->putJson("/api/v1/tenant/pharmacy-stores/{$store['id']}/medicines", ['medicines' => [
            ['medicine_id' => $medicine, 'reorder_level' => -1, 'minimum_stock_level' => 50, 'maximum_stock_level' => 10],
        ]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['medicines.0.reorder_level', 'medicines.0.maximum_stock_level']);

        // A removed medicine cannot be newly stocked.
        $this->onTenant($organization, fn () => Medicine::on('organization')->findOrFail($medicine)->delete());

        $this->putJson("/api/v1/tenant/pharmacy-stores/{$store['id']}/medicines", ['medicines' => [
            ['medicine_id' => $medicine, 'reorder_level' => 1, 'minimum_stock_level' => 0],
        ]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('medicines.0.medicine_id');
    }

    public function test_a_medicine_removed_from_a_store_comes_back_as_the_same_row(): void
    {
        [$organization, $here] = $this->network();

        $store = $this->addStore($here);
        $medicine = $this->medicine($organization);

        $row = $this->putJson("/api/v1/tenant/pharmacy-stores/{$store['id']}/medicines", ['medicines' => [
            ['medicine_id' => $medicine, 'reorder_level' => 10, 'minimum_stock_level' => 5],
        ]])->assertOk()->json('data.0');

        $this->deleteJson("/api/v1/tenant/pharmacy-stores/{$store['id']}/medicines/{$row['id']}", ['reason' => 'Not sold here'])
            ->assertOk();

        $this->getJson("/api/v1/tenant/pharmacy-stores/{$store['id']}/medicines")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $again = $this->putJson("/api/v1/tenant/pharmacy-stores/{$store['id']}/medicines", ['medicines' => [
            ['medicine_id' => $medicine, 'reorder_level' => 30, 'minimum_stock_level' => 5],
        ]])->assertOk()->json('data.0');

        $this->assertSame($row['id'], $again['id']);
        $this->assertSame(30, $again['reorder_level']);

        $this->onTenant($organization, function () use ($row) {
            $events = ActivityLog::on('organization')
                ->where('entity_type', 'StoreMedicine')
                ->where('entity_id', $row['id'])
                ->orderBy('id')
                ->get(['event', 'after']);

            $this->assertSame(['reason' => 'Not sold here'], $events->firstWhere('event', 'deleted')->after);
            $this->assertSame(['reason' => 'Added back to this store'], $events->firstWhere('event', 'restored')->after);
        });
    }

    public function test_another_stores_row_is_not_reachable_through_this_store(): void
    {
        [$organization, $here] = $this->network();

        $one = $this->addStore($here);
        $two = $this->addStore($here, ['name' => 'Store Room']);
        $medicine = $this->medicine($organization);

        $row = $this->putJson("/api/v1/tenant/pharmacy-stores/{$two['id']}/medicines", ['medicines' => [
            ['medicine_id' => $medicine, 'reorder_level' => 10, 'minimum_stock_level' => 5],
        ]])->assertOk()->json('data.0');

        $this->deleteJson("/api/v1/tenant/pharmacy-stores/{$one['id']}/medicines/{$row['id']}", ['reason' => 'x'])
            ->assertNotFound();
    }
}
