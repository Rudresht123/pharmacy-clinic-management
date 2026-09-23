<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Location;
use App\Models\Tenant\Medicine;
use App\Models\Tenant\MedicineBatch;
use App\Models\Tenant\PharmacySale;
use App\Models\Tenant\PharmacySetting;
use App\Models\Tenant\PharmacyStore;
use App\Models\Tenant\StockMovement;
use App\Models\Tenant\Supplier;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Pharmacy Phase B — the counter.
 *
 * What is asserted is what a bill has to promise: the stock it took is gone
 * from the shelf and explained in the ledger; expired stock is never sold;
 * the oldest stock leaves first; the money on the bill adds up and is split
 * out of a tax-inclusive MRP; a double-tap bills once; a cancelled bill puts
 * the stock back and stays on the record; and a standalone store can sell to
 * somebody who is not a patient.
 */
class PharmacySalesTest extends TenantTestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private int $branch;

    private int $store;

    private int $medicine;

    private int $otc;

    private int $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');

        $this->organization = $this->provisionOrganization('S');
        $this->grantModule($this->organization, 'medicines');
        $this->grantModule($this->organization, 'pharmacy');

        $this->onTenant($this->organization, function () {
            $this->branch = Location::on('organization')->create([
                'name' => 'Pratapgarh', 'code' => 'S-PBH', 'type' => Location::RETAIL_STORE, 'is_active' => true,
            ])->id;

            $this->store = PharmacyStore::on('organization')->create([
                'location_id' => $this->branch,
                'name' => 'Front Counter',
                'code' => 'PBH-A',
                'store_type' => PharmacyStore::RETAIL,
                'is_active' => true,
                'is_default' => true,
            ])->id;

            // A strip of 10 tablets, prescription-only, 12% GST.
            $this->medicine = Medicine::on('organization')->create([
                'generic_name' => 'Amoxicillin',
                'brand_name' => 'Mox 500',
                'strength' => '500 mg',
                'dosage_form' => 'tablet',
                'base_unit' => 'tablet',
                'pack_size' => 10,
                'prescription_required' => true,
                'tax_rate' => 12,
                'hsn_code' => '3004',
            ])->id;

            // Anybody may buy this one.
            $this->otc = Medicine::on('organization')->create([
                'generic_name' => 'Paracetamol',
                'brand_name' => 'Dolo 650',
                'strength' => '650 mg',
                'dosage_form' => 'tablet',
                'base_unit' => 'tablet',
                'pack_size' => 10,
                'prescription_required' => false,
                'tax_rate' => 12,
                'hsn_code' => '3004',
            ])->id;

            $this->supplier = Supplier::on('organization')->create([
                'name' => 'Micro Distributors', 'is_active' => true,
            ])->id;
        });

        $this->placeStaffAt($this->organization, $this->branch);
        $this->signInAsOwner($this->organization);
    }

    /**
     * Stock on the shelf: one batch, as an invoice reads it (in packs).
     *
     * @return int the batch id
     */
    private function stock(string $batchNumber, string $expiry, int $packs = 10, float $mrp = 112, ?int $medicine = null): int
    {
        $this->postJson("/api/v1/tenant/pharmacy-stores/{$this->store}/inwards", [
            'inward_type' => 'purchase',
            'supplier_id' => $this->supplier,
            'supplier_invoice_no' => 'GRN-'.Str::random(6),
            'received_date' => now()->toDateString(),
            'items' => [[
                'medicine_id' => $medicine ?? $this->medicine,
                'batch_number' => $batchNumber,
                'expiry_date' => $expiry,
                'quantity' => $packs,
                'purchase_price' => 70,
                'mrp' => $mrp,
            ]],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        return (int) $this->onTenant($this->organization, fn () => MedicineBatch::on('organization')
            ->where('pharmacy_store_id', $this->store)
            ->where('batch_number', $batchNumber)
            ->value('id'));
    }

    /** @param  array<string, mixed>  $data */
    private function sell(array $data = [], ?string $key = null)
    {
        return $this->postJson("/api/v1/tenant/pharmacy-stores/{$this->store}/sales", [
            'items' => [['medicine_id' => $this->otc, 'quantity' => 10]],
            'payments' => [['method' => 'cash', 'amount' => 112]],
            ...$data,
        ], ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    private function available(int $batchId): int
    {
        return (int) $this->onTenant($this->organization, fn () => MedicineBatch::on('organization')
            ->whereKey($batchId)
            ->value('quantity_available'));
    }

    public function test_a_counter_sale_takes_the_stock_and_explains_it_in_the_ledger(): void
    {
        $batch = $this->stock('P1', now()->addYear()->toDateString(), packs: 10, medicine: $this->otc);

        $sale = $this->sell()->assertCreated()->json('data');

        // 100 tablets came in; 10 went out.
        $this->assertSame(90, $this->available($batch));
        $this->assertSame(PharmacySale::COMPLETED, $sale['status']);
        $this->assertSame(PharmacySale::PAID, $sale['payment_status']);
        $this->assertStringStartsWith('INV-', $sale['sale_number']);

        $this->onTenant($this->organization, function () use ($batch) {
            $movement = StockMovement::on('organization')
                ->where('medicine_batch_id', $batch)
                ->where('movement_type', StockMovement::SALE)
                ->firstOrFail();

            $this->assertSame(-10, $movement->quantity);
            $this->assertSame(100, $movement->quantity_before);
            $this->assertSame(90, $movement->quantity_after);
            $this->assertSame('pharmacy_sale_item', $movement->reference_type);
        });
    }

    /** Indian MRP includes GST, so the bill splits it out rather than adding it on. */
    public function test_the_money_adds_up_and_the_tax_comes_out_of_the_price(): void
    {
        $this->stock('P1', now()->addYear()->toDateString(), packs: 10, medicine: $this->otc);

        // 10 tablets at ₹11.20 = ₹112, of which ₹12 is 12% GST.
        $sale = $this->sell()->assertCreated()->json('data');

        $this->assertEqualsWithDelta(100, $sale['subtotal'], 0.01);
        $this->assertEqualsWithDelta(12, $sale['tax_amount'], 0.01);
        $this->assertEqualsWithDelta(112, $sale['total_amount'], 0.01);
        $this->assertEqualsWithDelta(0, $sale['amount_due'], 0.01);

        $line = $this->getJson("/api/v1/tenant/sales/{$sale['id']}")->assertOk()->json('data.items.0');

        $this->assertSame('3004', $line['hsn_code'] ?? null);
        $this->assertSame('P1', $line['batch_number']);
        $this->assertEqualsWithDelta(11.2, $line['unit_price'], 0.01);
        $this->assertEqualsWithDelta(112, $line['line_total'], 0.01);
    }

    public function test_the_oldest_stock_leaves_first_and_a_line_can_span_batches(): void
    {
        $soon = $this->stock('OLD', now()->addMonths(2)->toDateString(), packs: 1, medicine: $this->otc);
        $later = $this->stock('NEW', now()->addYears(2)->toDateString(), packs: 5, medicine: $this->otc);

        // 10 in the batch that expires first, 25 asked for.
        $sale = $this->sell([
            'items' => [['medicine_id' => $this->otc, 'quantity' => 25]],
            'payments' => [['method' => 'cash', 'amount' => 280]],
        ])->assertCreated()->json('data');

        $this->assertSame(0, $this->available($soon));
        $this->assertSame(35, $this->available($later));

        $items = $this->getJson("/api/v1/tenant/sales/{$sale['id']}")->assertOk()->json('data.items');

        // One bill line per batch, each printing its own batch and expiry.
        $this->assertCount(2, $items);
        $this->assertSame(['OLD', 'NEW'], array_column($items, 'batch_number'));
        $this->assertSame([10, 15], array_column($items, 'quantity'));
    }

    public function test_expired_stock_is_never_sold(): void
    {
        $batch = $this->stock('P1', now()->addMonths(2)->toDateString(), packs: 2, medicine: $this->otc);

        // The calendar moves; the batch does not.
        $this->travel(90)->days();

        $this->sell([
            'items' => [['medicine_id' => $this->otc, 'medicine_batch_id' => $batch, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 11.2]],
        ])->assertStatus(409);

        // And it is not quietly filled from somewhere else either.
        $this->sell([
            'items' => [['medicine_id' => $this->otc, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 11.2]],
        ])->assertStatus(409);

        $this->assertSame(20, $this->available($batch));
    }

    public function test_a_bill_cannot_take_more_than_the_shelf_holds(): void
    {
        $batch = $this->stock('P1', now()->addYear()->toDateString(), packs: 1, medicine: $this->otc);

        $this->sell([
            'items' => [['medicine_id' => $this->otc, 'quantity' => 11]],
            'payments' => [['method' => 'cash', 'amount' => 123.2]],
        ])->assertStatus(409);

        // Nothing moved: the whole bill failed, not part of it.
        $this->assertSame(10, $this->available($batch));

        $this->onTenant($this->organization, fn () => $this->assertSame(
            0,
            PharmacySale::on('organization')->count(),
        ));
    }

    public function test_a_double_tap_bills_once(): void
    {
        $batch = $this->stock('P1', now()->addYear()->toDateString(), packs: 10, medicine: $this->otc);

        $key = (string) Str::uuid();

        $first = $this->sell(key: $key)->assertCreated()->json('data');
        $again = $this->sell(key: $key)->assertOk()->json('data');

        $this->assertSame($first['id'], $again['id']);
        $this->assertSame(90, $this->available($batch));
    }

    public function test_a_prescription_only_medicine_is_refused_without_one(): void
    {
        $this->stock('M1', now()->addYear()->toDateString(), packs: 5);

        $this->sell([
            'items' => [['medicine_id' => $this->medicine, 'quantity' => 10]],
            'payments' => [['method' => 'cash', 'amount' => 112]],
        ])->assertStatus(409);

        // The store that records the prescription later switches the rule off.
        $this->putJson('/api/v1/tenant/pharmacy/settings', ['require_prescription' => false])->assertOk();

        $this->sell([
            'items' => [['medicine_id' => $this->medicine, 'quantity' => 10]],
            'payments' => [['method' => 'cash', 'amount' => 112]],
        ])->assertCreated();
    }

    /** A medical store sells to whoever walks in; a bill still names them. */
    public function test_a_walk_in_sale_needs_no_patient_record(): void
    {
        $this->stock('P1', now()->addYear()->toDateString(), packs: 5, medicine: $this->otc);

        $sale = $this->sell(['walk_in_name' => 'Ramesh', 'walk_in_phone' => '9876543210'])
            ->assertCreated()->json('data');

        $this->assertTrue($sale['is_walk_in']);
        $this->assertSame('Ramesh', $sale['customer_name']);

        $this->onTenant($this->organization, fn () => $this->assertSame(
            0,
            Customer::on('organization')->count(),
        ));

        // An organisation that registers every buyer switches walk-ins off.
        $this->putJson('/api/v1/tenant/pharmacy/settings', ['allow_walk_in' => false])->assertOk();

        $this->sell()->assertStatus(409);
    }

    public function test_an_unpaid_bill_needs_credit_switched_on_and_a_customer_to_owe_it(): void
    {
        $this->stock('P1', now()->addYear()->toDateString(), packs: 5, medicine: $this->otc);

        // Credit is off by default.
        $this->sell(['payments' => []])->assertStatus(409);

        $this->putJson('/api/v1/tenant/pharmacy/settings', ['credit_sales_enabled' => true])->assertOk();

        // Still refused: a walk-in cannot be chased for it.
        $this->sell(['payments' => []])->assertStatus(409);

        $customer = $this->onTenant($this->organization, fn () => Customer::on('organization')->create([
            'name' => 'Sunita Devi', 'phone' => '9876500000', 'code' => 'C-1', 'is_active' => true,
        ])->id);

        $sale = $this->sell([
            'customer_id' => $customer,
            'payments' => [['method' => 'cash', 'amount' => 50]],
        ])->assertCreated()->json('data');

        $this->assertSame(PharmacySale::PARTIAL, $sale['payment_status']);
        $this->assertEqualsWithDelta(62, $sale['amount_due'], 0.01);

        // More than the bill comes to is a mistake, not change.
        $this->sell(['customer_id' => $customer, 'payments' => [['method' => 'cash', 'amount' => 500]]])
            ->assertStatus(409);
    }

    public function test_cancelling_puts_the_stock_back_and_keeps_the_bill(): void
    {
        $batch = $this->stock('P1', now()->addYear()->toDateString(), packs: 10, medicine: $this->otc);

        $sale = $this->sell()->assertCreated()->json('data');
        $this->assertSame(90, $this->available($batch));

        $this->postJson("/api/v1/tenant/sales/{$sale['id']}/cancel")
            ->assertStatus(422)->assertJsonValidationErrors('reason');

        $cancelled = $this->postJson("/api/v1/tenant/sales/{$sale['id']}/cancel", ['reason' => 'Wrong customer'])
            ->assertOk()->json('data');

        $this->assertSame(PharmacySale::CANCELLED, $cancelled['status']);
        $this->assertSame('Wrong customer', $cancelled['cancellation_reason']);
        $this->assertSame(100, $this->available($batch));

        // Twice is refused, and the bill is still there to be read.
        $this->postJson("/api/v1/tenant/sales/{$sale['id']}/cancel", ['reason' => 'Again'])->assertStatus(409);
        $this->getJson("/api/v1/tenant/sales/{$sale['id']}")->assertOk();

        // A bill is a financial record: the database itself refuses to lose one.
        $this->onTenant($this->organization, function () use ($sale) {
            $this->expectException(QueryException::class);

            PharmacySale::on('organization')->whereKey($sale['id'])->delete();
        });
    }

    public function test_selling_and_cancelling_are_separate_permissions(): void
    {
        $this->stock('P1', now()->addYear()->toDateString(), packs: 5, medicine: $this->otc);

        $this->setStaffCapabilities($this->organization, ['pharmacy.view', 'pharmacy.sell']);
        $this->signInAsStaff($this->organization);

        $sale = $this->sell()->assertCreated()->json('data');

        // A cashier sells; putting stock back is a supervisor's decision.
        $this->postJson("/api/v1/tenant/sales/{$sale['id']}/cancel", ['reason' => 'Mistake'])
            ->assertForbidden();

        $this->setStaffCapabilities($this->organization, ['pharmacy.view']);
        $this->signInAsStaff($this->organization);

        $this->sell()->assertForbidden();
        $this->getJson("/api/v1/tenant/pharmacy-stores/{$this->store}/sales")->assertOk();
    }

    /** The bill number follows the organisation's own prefix. */
    public function test_bills_are_numbered_the_way_the_organisation_bills(): void
    {
        $this->stock('P1', now()->addYear()->toDateString(), packs: 10, medicine: $this->otc);

        $this->putJson('/api/v1/tenant/pharmacy/settings', ['invoice_prefix' => 'BILL'])->assertOk();

        $first = $this->sell()->assertCreated()->json('data.sale_number');
        $second = $this->sell()->assertCreated()->json('data.sale_number');

        $this->assertStringStartsWith('BILL-', $first);
        $this->assertNotSame($first, $second);

        // How it was priced is copied onto the bill, not looked up later.
        $sale = $this->getJson("/api/v1/tenant/sales/{$this->onTenant($this->organization, fn () => PharmacySale::on('organization')->latest('id')->value('id'))}")
            ->assertOk()->json('data');

        $this->assertSame(PharmacySetting::PRICE_MRP, $sale['price_basis']);
        $this->assertTrue($sale['prices_include_tax']);
    }
}
