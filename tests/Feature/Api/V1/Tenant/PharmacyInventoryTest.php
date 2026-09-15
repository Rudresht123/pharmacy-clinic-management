<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\ActivityLog;
use App\Models\Tenant\Location;
use App\Models\Tenant\Medicine;
use App\Models\Tenant\MedicineBatch;
use App\Models\Tenant\PharmacyStore;
use App\Models\Tenant\StockMovement;
use App\Models\Tenant\StoreMedicine;
use App\Models\Tenant\Supplier;
use App\Services\Pharmacy\Inventory\StockAdjustmentService;
use App\Services\Pharmacy\Inventory\StockConflict;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\TenantTestCase;

/**
 * Pharmacy Phase 3 — suppliers, batches, the ledger, and the documents that
 * move stock.
 *
 * What is asserted is the promise the ledger makes: every quantity is
 * explained by a movement, a movement can never be edited, stock never goes
 * below zero however two counters race for it, and a retried request never
 * moves stock twice.
 */
class PharmacyInventoryTest extends TenantTestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private int $here;

    private int $there;

    /** @var array{a: int, a2: int, b: int} two stores at this branch, one at the other */
    private array $stores;

    private int $medicine;

    private int $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');

        $this->organization = $this->provisionOrganization('I');
        $this->grantModule($this->organization, 'medicines');
        $this->grantModule($this->organization, 'pharmacy');

        $this->onTenant($this->organization, function () {
            $this->here = Location::on('organization')->create([
                'name' => 'Noida', 'code' => 'I-NOI', 'type' => Location::CLINIC, 'is_active' => true,
            ])->id;
            $this->there = Location::on('organization')->create([
                'name' => 'Delhi', 'code' => 'I-DEL', 'type' => Location::CLINIC, 'is_active' => true,
            ])->id;

            $store = fn (int $location, string $code) => PharmacyStore::on('organization')->create([
                'location_id' => $location,
                'name' => "Store {$code}",
                'code' => $code,
                'store_type' => PharmacyStore::OPD_COUNTER,
                'is_active' => true,
            ])->id;

            $this->stores = [
                'a' => $store($this->here, 'NOI-A'),
                'a2' => $store($this->here, 'NOI-B'),
                'b' => $store($this->there, 'DEL-A'),
            ];

            // A strip of 10 tablets.
            $this->medicine = Medicine::on('organization')->create([
                'generic_name' => 'Paracetamol',
                'brand_name' => 'Dolo 650',
                'strength' => '650 mg',
                'dosage_form' => 'tablet',
                'base_unit' => 'tablet',
                'pack_size' => 10,
            ])->id;

            $this->supplier = Supplier::on('organization')->create([
                'name' => 'Micro Distributors',
                'is_active' => true,
            ])->id;
        });

        $this->placeStaffAt($this->organization, $this->here);
        $this->signInAsOwner($this->organization);
    }

    /** A goods received note for one line, as an invoice reads: in packs. */
    private function receive(string $store = 'a', array $line = [], array $note = [], ?string $key = null)
    {
        return $this->postJson("/api/v1/tenant/pharmacy-stores/{$this->stores[$store]}/inwards", array_merge([
            'inward_type' => 'purchase',
            'supplier_id' => $this->supplier,
            'supplier_invoice_no' => 'INV-'.Str::random(6),
            'received_date' => now()->toDateString(),
            'items' => [array_merge([
                'medicine_id' => $this->medicine,
                'batch_number' => 'B2231',
                'expiry_date' => now()->addYear()->toDateString(),
                'quantity' => 5,
                'free_quantity' => 1,
                'purchase_price' => 30,
                'mrp' => 35,
            ], $line)],
        ], $note), ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    private function adjust(int $batchId, array $data, string $store = 'a', ?string $key = null)
    {
        return $this->postJson("/api/v1/tenant/pharmacy-stores/{$this->stores[$store]}/adjustments", array_merge([
            'medicine_batch_id' => $batchId,
            'reason' => 'Counted at close',
        ], $data), ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    private function transfer(int $batchId, int $quantity, string $from = 'a', string $to = 'a2')
    {
        return $this->postJson('/api/v1/tenant/stock-transfers', [
            'from_store_id' => $this->stores[$from],
            'to_store_id' => $this->stores[$to],
            'items' => [['batch_id' => $batchId, 'quantity' => $quantity]],
        ], ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function batch(string $store = 'a', string $number = 'B2231'): ?MedicineBatch
    {
        return $this->onTenant($this->organization, fn () => MedicineBatch::on('organization')
            ->where('pharmacy_store_id', $this->stores[$store])
            ->where('batch_number', $number)
            ->first());
    }

    /** @return list<array{0: string, 1: int, 2: int, 3: int}> type, quantity, before, after */
    private function ledger(int $batchId): array
    {
        return $this->onTenant($this->organization, fn () => StockMovement::on('organization')
            ->where('medicine_batch_id', $batchId)
            ->orderBy('id')
            ->get()
            ->map(fn (StockMovement $m) => [$m->movement_type, $m->quantity, $m->quantity_before, $m->quantity_after])
            ->all());
    }

    /*
    |--------------------------------------------------------------------------
    | Receiving goods
    |--------------------------------------------------------------------------
    */

    public function test_receiving_goods_creates_a_batch_and_a_ledger_row(): void
    {
        $note = $this->receive()->assertCreated()->json('data');

        $this->assertSame('GRN-00001', $note['inward_number']);
        $this->assertSame('150.00', $note['total_amount']);

        // Five strips and one free, of ten tablets: sixty tablets.
        $batch = $this->batch();
        $this->assertSame(60, $batch->quantity_available);
        $this->assertSame(60, $batch->quantity_received);
        $this->assertSame(MedicineBatch::ACTIVE, $batch->status);

        // Prices per tablet, to the paisa.
        $this->assertSame('3.00', $batch->purchase_price);
        $this->assertSame('3.50', $batch->mrp);
        $this->assertSame('3.50', $batch->selling_price);

        $this->assertSame([[StockMovement::PURCHASE, 60, 0, 60]], $this->ledger($batch->id));

        // Receiving it means the store stocks it.
        $this->assertTrue($this->onTenant($this->organization, fn () => StoreMedicine::on('organization')
            ->where('pharmacy_store_id', $this->stores['a'])
            ->where('medicine_id', $this->medicine)
            ->exists()));
    }

    public function test_a_second_delivery_of_the_same_batch_tops_it_up_only_if_it_is_the_same_lot(): void
    {
        $this->receive()->assertCreated();
        $this->receive(line: ['batch_number' => 'b2231'])->assertCreated();

        $batch = $this->batch();
        $this->assertSame(120, $batch->quantity_available);
        $this->assertSame(1, $this->onTenant($this->organization, fn () => MedicineBatch::on('organization')->count()));

        // The same number with another expiry is a typing mistake, not a second lot.
        $this->receive(line: ['expiry_date' => now()->addYears(2)->toDateString()])
            ->assertStatus(409)
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'different lot'));

        $this->assertSame(120, $this->batch()->quantity_available);
    }

    public function test_a_retried_request_receives_the_goods_once(): void
    {
        $key = (string) Str::uuid();

        $first = $this->receive(key: $key)->assertCreated()->json('data.id');
        $again = $this->receive(key: $key)->assertOk()->json('data.id');

        $this->assertSame($first, $again);
        $this->assertCount(1, $this->ledger($this->batch()->id));
    }

    public function test_what_is_received_is_checked_at_the_door(): void
    {
        $this->receive(line: ['expiry_date' => now()->toDateString()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.expiry_date');

        $this->receive(note: ['supplier_id' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors('supplier_id');

        $this->receive(line: ['selling_price' => 40])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.selling_price');

        // No key, no receipt: a retry must not be able to receive twice.
        $this->postJson("/api/v1/tenant/pharmacy-stores/{$this->stores['a']}/inwards", [
            'inward_type' => 'opening_balance',
            'received_date' => now()->toDateString(),
            'items' => [[
                'medicine_id' => $this->medicine, 'batch_number' => 'X1',
                'expiry_date' => now()->addYear()->toDateString(),
                'quantity' => 1, 'purchase_price' => 1, 'mrp' => 2,
            ]],
        ])->assertStatus(422)->assertJsonValidationErrors('idempotency_key');
    }

    /** An opening balance of loose tablets is counted in tablets, not strips. */
    public function test_an_opening_balance_can_be_counted_in_base_units(): void
    {
        $this->receive(line: ['in_packs' => false, 'quantity' => 37, 'free_quantity' => 0, 'purchase_price' => 3, 'mrp' => 3.5], note: [
            'inward_type' => 'opening_balance',
            'supplier_id' => null,
        ])->assertCreated();

        $batch = $this->batch();
        $this->assertSame(37, $batch->quantity_available);
        $this->assertSame([[StockMovement::OPENING_BALANCE, 37, 0, 37]], $this->ledger($batch->id));
    }

    /*
    |--------------------------------------------------------------------------
    | Adjustments, and the floor under stock
    |--------------------------------------------------------------------------
    */

    public function test_adjustments_move_stock_with_a_reason_and_never_below_zero(): void
    {
        $this->receive()->assertCreated();
        $batch = $this->batch();

        $this->adjust($batch->id, ['direction' => 'decrease', 'quantity' => 5, 'reason_code' => 'damage'])
            ->assertCreated()
            ->assertJsonPath('data.adjustment_number', 'ADJ-00001');

        $this->adjust($batch->id, ['direction' => 'decrease', 'quantity' => 100, 'reason_code' => 'loss'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Batch B2231 has 55 left; 100 were asked for.');

        $this->adjust($batch->id, ['direction' => 'increase', 'quantity' => 2, 'reason_code' => 'count_correction'])
            ->assertCreated();

        // An expiry write-off is only true of stock that has expired.
        $this->adjust($batch->id, ['direction' => 'decrease', 'quantity' => 1, 'reason_code' => 'expiry_writeoff'])
            ->assertStatus(409);

        // Damage never adds stock.
        $this->adjust($batch->id, ['direction' => 'increase', 'quantity' => 1, 'reason_code' => 'damage'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason_code');

        $batch = $this->batch();
        $this->assertSame(57, $batch->quantity_available);
        $this->assertSame(5, $batch->damaged_quantity);

        $this->assertSame([
            [StockMovement::PURCHASE, 60, 0, 60],
            [StockMovement::DAMAGE, -5, 60, 55],
            [StockMovement::ADJUSTMENT_INCREASE, 2, 55, 57],
        ], $this->ledger($batch->id));
    }

    public function test_the_ledger_cannot_be_edited_or_deleted(): void
    {
        $this->receive()->assertCreated();

        $this->onTenant($this->organization, function () {
            $movement = StockMovement::on('organization')->firstOrFail();

            foreach ([
                fn () => DB::connection('organization')->table('stock_movements')->where('id', $movement->id)->update(['quantity' => 999]),
                fn () => DB::connection('organization')->table('stock_movements')->where('id', $movement->id)->delete(),
                fn () => DB::connection('organization')->table('stock_inward_items')->delete(),
                fn () => DB::connection('organization')->table('stock_inwards')->delete(),
            ] as $attempt) {
                try {
                    $attempt();
                    $this->fail('An append-only table accepted a change.');
                } catch (QueryException $e) {
                    $this->assertStringContainsString('append-only', $e->getMessage());
                }
            }

            // And the model says so before the database has to.
            $this->expectException(LogicException::class);
            $movement->update(['reason' => 'tampered']);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Transfers
    |--------------------------------------------------------------------------
    */

    public function test_a_transfer_moves_stock_out_and_in_as_the_same_lot(): void
    {
        $this->receive()->assertCreated();
        $source = $this->batch();

        $this->transfer($source->id, 20)
            ->assertCreated()
            ->assertJsonPath('data.transfer_number', 'TRF-00001');

        $destination = $this->batch('a2');

        $this->assertSame(40, $this->batch()->quantity_available);
        $this->assertSame(20, $destination->quantity_available);
        $this->assertSame($source->expiry_date->toDateString(), $destination->expiry_date->toDateString());
        $this->assertSame($source->mrp, $destination->mrp);

        $this->assertSame([StockMovement::TRANSFER_OUT, -20, 60, 40], $this->ledger($source->id)[1]);
        $this->assertSame([[StockMovement::TRANSFER_IN, 20, 0, 20]], $this->ledger($destination->id));

        // More than there is.
        $this->transfer($source->id, 41)->assertStatus(409);
    }

    public function test_a_transfer_needs_the_capability_at_both_ends(): void
    {
        $this->receive()->assertCreated();
        $batch = $this->batch();

        $this->setStaffCapabilities($this->organization, ['pharmacy.view', 'pharmacy.transfer']);
        $this->signInAsStaff($this->organization);

        // The staff member works at Noida, not Delhi.
        $this->transfer($batch->id, 5, 'a', 'b')->assertForbidden();
        $this->transfer($batch->id, 5, 'a', 'a2')->assertCreated();

        // And cannot see Delhi's stock at all.
        $this->getJson("/api/v1/tenant/pharmacy-stores/{$this->stores['b']}/batches")->assertForbidden();
        $this->getJson("/api/v1/tenant/pharmacy-stores/{$this->stores['a']}/batches")->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | Batch status, expiry
    |--------------------------------------------------------------------------
    */

    public function test_a_blocked_batch_keeps_its_stock_but_cannot_move(): void
    {
        $this->receive()->assertCreated();
        $batch = $this->batch();

        $this->patchJson("/api/v1/tenant/batches/{$batch->id}/status", ['status' => 'blocked'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->patchJson("/api/v1/tenant/batches/{$batch->id}/status", [
            'status' => 'blocked',
            'reason' => 'Supplier query on the label',
        ])->assertOk()->assertJsonPath('data.status', 'blocked');

        $this->transfer($batch->id, 5)
            ->assertStatus(409)
            ->assertJsonPath('message', 'Batch B2231 is blocked, so no stock can leave it.');

        $this->patchJson("/api/v1/tenant/batches/{$batch->id}/status", [
            'status' => 'active',
            'reason' => 'Supplier confirmed the label',
        ])->assertOk()->assertJsonPath('data.status', 'active');

        $this->assertSame(60, $this->batch()->quantity_available);

        // Both decisions, with their reasons, on the batch's history.
        $reasons = $this->onTenant($this->organization, fn () => ActivityLog::on('organization')
            ->where('entity_type', 'MedicineBatch')
            ->where('entity_id', $batch->id)
            ->where('event', 'updated')
            ->orderBy('id')
            ->get()
            ->map(fn (ActivityLog $log) => $log->after['reason'] ?? null)
            ->filter()
            ->values()
            ->all());

        $this->assertSame(['Supplier query on the label', 'Supplier confirmed the label'], $reasons);
    }

    public function test_the_expiry_job_stops_a_batch_on_its_expiry_date(): void
    {
        $this->receive(line: ['expiry_date' => now()->addDays(2)->toDateString()])->assertCreated();

        $this->travelTo(now()->addDays(2));

        $this->artisan('pharmacy:expire-batches', ['--org' => $this->organization->uuid])->assertExitCode(0);

        $batch = $this->batch();
        $this->assertSame(MedicineBatch::EXPIRED, $batch->status);
        // Still on the books until someone writes it off.
        $this->assertSame(60, $batch->quantity_available);

        $this->transfer($batch->id, 1)->assertStatus(409);

        $this->adjust($batch->id, ['direction' => 'decrease', 'quantity' => 60, 'reason_code' => 'expiry_writeoff'])
            ->assertCreated();

        $this->assertSame(0, $this->batch()->quantity_available);
    }

    public function test_reconciliation_finds_a_quantity_the_ledger_does_not_explain(): void
    {
        $this->receive()->assertCreated();

        $this->artisan('pharmacy:reconcile-stock', ['--org' => $this->organization->uuid])->assertExitCode(0);

        // Written behind the service's back — the thing the job exists to catch.
        $this->onTenant($this->organization, fn () => DB::connection('organization')
            ->table('medicine_batches')
            ->update(['quantity_available' => 999]));

        $this->artisan('pharmacy:reconcile-stock', ['--org' => $this->organization->uuid])->assertExitCode(1);
    }

    /*
    |--------------------------------------------------------------------------
    | Cancelling a receipt
    |--------------------------------------------------------------------------
    */

    public function test_cancelling_a_receipt_reverses_it_until_its_stock_is_used(): void
    {
        $note = $this->receive()->assertCreated()->json('data');
        $batch = $this->batch();

        $this->postJson("/api/v1/tenant/inwards/{$note['id']}/cancel", ['reason' => 'Wrong invoice'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(0, $this->batch()->quantity_available);
        $this->assertSame(MedicineBatch::EXHAUSTED, $this->batch()->status);

        $ledger = $this->ledger($batch->id);
        $this->assertSame([StockMovement::CORRECTION, -60, 60, 0], $ledger[1]);

        // The correction points at the movement it undoes.
        $this->assertSame(
            $this->onTenant($this->organization, fn () => StockMovement::on('organization')->orderBy('id')->value('id')),
            $this->onTenant($this->organization, fn () => StockMovement::on('organization')->orderByDesc('id')->value('reverses_movement_id')),
        );

        $this->postJson("/api/v1/tenant/inwards/{$note['id']}/cancel", ['reason' => 'Again'])->assertStatus(409);

        // Once any of a receipt's stock has left, it can no longer be cancelled.
        $second = $this->receive(line: ['batch_number' => 'C77'])->assertCreated()->json('data');
        $this->adjust($this->batch('a', 'C77')->id, ['direction' => 'decrease', 'quantity' => 1, 'reason_code' => 'loss'])
            ->assertCreated();

        $this->postJson("/api/v1/tenant/inwards/{$second['id']}/cancel", ['reason' => 'Wrong invoice'])
            ->assertStatus(409);
    }

    /*
    |--------------------------------------------------------------------------
    | Stock protects what it depends on
    |--------------------------------------------------------------------------
    */

    public function test_a_medicine_or_a_store_holding_stock_cannot_be_removed(): void
    {
        $this->receive()->assertCreated();

        $this->deleteJson("/api/v1/tenant/medicines/{$this->medicine}", ['reason' => 'Discontinued'])
            ->assertStatus(409);

        $this->deleteJson("/api/v1/tenant/pharmacy-stores/{$this->stores['a']}", ['reason' => 'Closing'])
            ->assertStatus(409);

        // An empty batch can go; one with stock cannot.
        $batch = $this->batch();
        $this->deleteJson("/api/v1/tenant/batches/{$batch->id}", ['reason' => 'Mistake'])->assertStatus(409);
    }

    public function test_the_stock_view_shows_what_can_be_dispensed_and_what_is_low(): void
    {
        $this->putJson("/api/v1/tenant/pharmacy-stores/{$this->stores['a']}/medicines", ['medicines' => [
            ['medicine_id' => $this->medicine, 'reorder_level' => 100, 'minimum_stock_level' => 20],
        ]])->assertOk();

        $this->receive()->assertCreated();

        $row = $this->getJson("/api/v1/tenant/pharmacy-stores/{$this->stores['a']}/stock")
            ->assertOk()
            ->json('data.0');

        $this->assertSame(60, $row['on_hand']);
        $this->assertSame(60, $row['usable']);
        $this->assertTrue($row['is_low']);
    }

    /*
    |--------------------------------------------------------------------------
    | Two counters, one batch
    |--------------------------------------------------------------------------
    */

    /**
     * Two connections race for the last ten tablets; exactly one gets them.
     *
     * A second connection holds the batch row locked, as a counter mid-sale
     * would. The adjustment on the first connection must wait for that lock
     * rather than read a quantity that is about to change — so with a short
     * lock timeout it gives up instead of writing. The other side then takes
     * the ten and commits; trying again is refused with what is actually
     * left.
     */
    public function test_two_connections_racing_for_the_last_units_one_wins(): void
    {
        $this->receive(line: ['quantity' => 1, 'free_quantity' => 0])->assertCreated();
        $batchId = $this->batch()->id;

        $this->onTenant($this->organization, function () use ($batchId) {
            config(['database.connections.organization_race' => config('database.connections.organization')]);
            $race = DB::connection('organization_race');

            try {
                $race->beginTransaction();
                $race->table('medicine_batches')->where('id', $batchId)->lockForUpdate()->first();

                DB::connection('organization')->statement("SET lock_timeout = '500ms'");

                $batch = MedicineBatch::on('organization')->findOrFail($batchId);
                $service = app(StockAdjustmentService::class);
                $decrease = ['direction' => 'decrease', 'quantity' => 10, 'reason_code' => 'loss', 'reason' => 'Race'];

                try {
                    $service->adjust($batch, $decrease, (string) Str::uuid());
                    $this->fail('The adjustment did not wait for the lock.');
                } catch (QueryException $e) {
                    $this->assertStringContainsString('lock', strtolower($e->getMessage()));
                }

                // The other counter takes all ten, through the ledger, and commits.
                $race->table('stock_movements')->insert([
                    'location_id' => $batch->store->location_id,
                    'pharmacy_store_id' => $batch->pharmacy_store_id,
                    'medicine_id' => $batch->medicine_id,
                    'medicine_batch_id' => $batchId,
                    'movement_type' => StockMovement::DISPENSING,
                    'quantity' => -10,
                    'quantity_before' => 10,
                    'quantity_after' => 0,
                    'movement_date' => now(),
                ]);
                $race->table('medicine_batches')->where('id', $batchId)->update([
                    'quantity_available' => 0,
                    'status' => MedicineBatch::EXHAUSTED,
                ]);
                $race->commit();

                DB::connection('organization')->statement('SET lock_timeout = 0');

                try {
                    $service->adjust($batch->refresh(), $decrease, (string) Str::uuid());
                    $this->fail('Stock was taken twice.');
                } catch (StockConflict $e) {
                    $this->assertSame('Batch B2231 has 0 left; 10 were asked for.', $e->getMessage());
                }

                $this->assertSame(0, MedicineBatch::on('organization')->findOrFail($batchId)->quantity_available);
            } finally {
                if ($race->transactionLevel() > 0) {
                    $race->rollBack();
                }

                DB::disconnect('organization_race');
                DB::purge('organization_race');
            }
        });
    }
}
