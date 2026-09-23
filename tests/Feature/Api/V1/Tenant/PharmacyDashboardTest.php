<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Location;
use App\Models\Tenant\Medicine;
use App\Models\Tenant\MedicineBatch;
use App\Models\Tenant\PharmacyStore;
use App\Models\Tenant\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * The pharmacy dashboard — one store's day.
 *
 * What is asserted is that every figure on it is the same number the screen it
 * summarises would print: takings are completed bills only, a cancelled bill
 * leaves both the takings and the shelf value, profit is measured against the
 * cost copied onto each line when it was sold, a medicine the store keeps and
 * has none of is counted even though it has no batch to be counted from, and
 * the warning window is the organisation's own rather than a constant.
 *
 * The figures are deliberately round: a strip of 10 bought at ₹70 and sold at
 * ₹112 MRP is ₹7 a tablet against ₹11.20, so a bill of 10 tablets is ₹112 with
 * ₹12 of GST inside it and ₹30 of margin.
 */
class PharmacyDashboardTest extends TenantTestCase
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

        $this->organization = $this->provisionOrganization('D');
        $this->grantModule($this->organization, 'medicines');
        $this->grantModule($this->organization, 'pharmacy');

        $this->onTenant($this->organization, function () {
            $this->branch = Location::on('organization')->create([
                'name' => 'Pratapgarh', 'code' => 'D-PBH', 'type' => Location::RETAIL_STORE, 'is_active' => true,
            ])->id;

            $this->store = PharmacyStore::on('organization')->create([
                'location_id' => $this->branch,
                'name' => 'Front Counter',
                'code' => 'PBH-A',
                'store_type' => PharmacyStore::RETAIL,
                'is_active' => true,
                'is_default' => true,
            ])->id;

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
    private function stock(string $batchNumber, string $expiry, int $packs = 10, ?int $medicine = null): int
    {
        $this->postJson("/api/v1/tenant/pharmacy-stores/{$this->store}/inwards", [
            'inward_type' => 'purchase',
            'supplier_id' => $this->supplier,
            'supplier_invoice_no' => 'GRN-'.Str::random(6),
            'received_date' => now()->toDateString(),
            'items' => [[
                'medicine_id' => $medicine ?? $this->otc,
                'batch_number' => $batchNumber,
                'expiry_date' => $expiry,
                'quantity' => $packs,
                'purchase_price' => 70,
                'mrp' => 112,
            ]],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        return (int) $this->onTenant($this->organization, fn () => MedicineBatch::on('organization')
            ->where('pharmacy_store_id', $this->store)
            ->where('batch_number', $batchNumber)
            ->value('id'));
    }

    /** @param  array<string, mixed>  $data */
    private function sell(array $data = [])
    {
        return $this->postJson("/api/v1/tenant/pharmacy-stores/{$this->store}/sales", [
            'items' => [['medicine_id' => $this->otc, 'quantity' => 10]],
            'payments' => [['method' => 'cash', 'amount' => 112]],
            ...$data,
        ], ['Idempotency-Key' => (string) Str::uuid()]);
    }

    /** @return array<string, mixed> */
    private function dashboard(): array
    {
        return $this->getJson("/api/v1/tenant/pharmacy-stores/{$this->store}/dashboard")
            ->assertOk()
            ->json('data');
    }

    /** @param  array<string, mixed>  $levels */
    private function setLevel(int $medicine, array $levels): void
    {
        $this->putJson("/api/v1/tenant/pharmacy-stores/{$this->store}/medicines", [
            'medicines' => [[
                'medicine_id' => $medicine,
                'minimum_stock_level' => 0,
                ...$levels,
            ]],
        ])->assertOk();
    }

    public function test_the_day_adds_up_takings_purchases_and_what_the_selling_made(): void
    {
        // ₹700 of stock: 100 tablets at ₹7.
        $this->stock('P1', now()->addYear()->toDateString());
        $this->sell()->assertCreated();

        $data = $this->dashboard();

        $this->assertSame($this->store, $data['store']['id']);
        $this->assertSame('Pratapgarh', $data['store']['branch']);

        $this->assertEqualsWithDelta(112, $data['today']['sales'], 0.01);
        $this->assertSame(1, $data['today']['bills']);
        $this->assertEqualsWithDelta(700, $data['today']['purchases'], 0.01);

        // ₹100 taxable against ₹70 of stock.
        $this->assertEqualsWithDelta(30, $data['today']['gross_profit'], 0.01);

        // 90 tablets left on the shelf, at what they cost.
        $this->assertEqualsWithDelta(630, $data['stock']['value_at_cost'], 0.01);

        // Nothing to compare against: a rise from zero has no percentage.
        $this->assertSame(0, $data['yesterday']['bills']);
        $this->assertNull($data['change']);

        $bill = $data['recent_bills'][0];

        $this->assertStringStartsWith('INV-', $bill['sale_number']);
        $this->assertSame('cash', $bill['payment']);
        $this->assertSame(1, $bill['items_count']);
        $this->assertEqualsWithDelta(112, $bill['total_amount'], 0.01);
    }

    /** Takings are what the store kept, so a cancelled bill is not takings. */
    public function test_a_cancelled_bill_leaves_the_takings_and_puts_its_value_back(): void
    {
        $this->stock('P1', now()->addYear()->toDateString());

        $sale = $this->sell()->assertCreated()->json('data');

        $this->postJson("/api/v1/tenant/sales/{$sale['id']}/cancel", ['reason' => 'Wrong customer'])
            ->assertOk();

        $data = $this->dashboard();

        $this->assertEqualsWithDelta(0, $data['today']['sales'], 0.01);
        $this->assertSame(0, $data['today']['bills']);
        $this->assertEqualsWithDelta(0, $data['today']['gross_profit'], 0.01);
        $this->assertEqualsWithDelta(700, $data['stock']['value_at_cost'], 0.01);
        $this->assertSame([], $data['recent_bills']);
    }

    /**
     * The row somebody opens this screen to find is the one with no stock at
     * all — which has no batch, and so cannot be counted from batches.
     */
    public function test_low_and_out_of_stock_count_what_the_store_keeps(): void
    {
        $this->stock('P1', now()->addYear()->toDateString());

        $this->setLevel($this->otc, ['reorder_level' => 50]);
        // Kept, never received: nothing on the shelf and no batch to say so.
        $this->setLevel($this->medicine, ['reorder_level' => 5]);

        $data = $this->dashboard();

        $this->assertSame(2, $data['stock']['stocked']);
        $this->assertSame(1, $data['stock']['low']);
        $this->assertSame(1, $data['stock']['out']);

        // Emptiest first, and what has run out says so rather than showing 0.
        $this->assertCount(1, $data['low_stock']);
        $this->assertSame(0, $data['low_stock'][0]['on_hand']);
        $this->assertStringContainsString('Mox 500', $data['low_stock'][0]['name']);

        // 60 of the 100 tablets go; the rest is now under the reorder level.
        $this->sell([
            'items' => [['medicine_id' => $this->otc, 'quantity' => 60]],
            'payments' => [['method' => 'cash', 'amount' => 672]],
        ])->assertCreated();

        $data = $this->dashboard();

        $this->assertSame(2, $data['stock']['low']);
        $this->assertSame(1, $data['stock']['out']);
        $this->assertSame([0, 40], array_column($data['low_stock'], 'on_hand'));
    }

    public function test_expiry_alerts_follow_the_organisations_own_warning_window(): void
    {
        $this->stock('SOON', now()->addDays(20)->toDateString(), packs: 2);
        $this->stock('LATER', now()->addYears(2)->toDateString(), packs: 2);

        // 90 days by default, so only the near batch is a warning.
        $data = $this->dashboard();

        $this->assertSame(90, $data['stock']['expiry_warning_days']);
        $this->assertSame(1, $data['stock']['expiring']);
        $this->assertCount(1, $data['expiring']);
        $this->assertSame('SOON', $data['expiring'][0]['batch_number']);
        $this->assertSame(20, $data['expiring'][0]['days_left']);
        $this->assertSame(20, $data['expiring'][0]['quantity']);

        // A store that orders weekly warns later, and the screen follows it.
        $this->putJson('/api/v1/tenant/pharmacy/settings', ['expiry_warning_days' => 10])->assertOk();

        $data = $this->dashboard();

        $this->assertSame(0, $data['stock']['expiring']);
        $this->assertSame([], $data['expiring']);
    }

    public function test_outstanding_is_what_customers_still_owe_on_completed_bills(): void
    {
        $this->stock('P1', now()->addYear()->toDateString());

        $this->putJson('/api/v1/tenant/pharmacy/settings', ['credit_sales_enabled' => true])->assertOk();

        $customer = $this->onTenant($this->organization, fn () => Customer::on('organization')->create([
            'name' => 'Sunita Devi', 'phone' => '9876500000', 'code' => 'C-1', 'is_active' => true,
        ])->id);

        $this->sell([
            'customer_id' => $customer,
            'payments' => [['method' => 'cash', 'amount' => 50]],
        ])->assertCreated();

        $data = $this->dashboard();

        $this->assertEqualsWithDelta(62, $data['outstanding'], 0.01);
        $this->assertEqualsWithDelta(62, $data['recent_bills'][0]['amount_due'], 0.01);
        $this->assertSame('Sunita Devi', $data['recent_bills'][0]['customer_name']);
    }

    /** A quiet day is a day on the chart, not a day missing from it. */
    public function test_the_week_has_seven_days_whether_or_not_anything_happened(): void
    {
        $this->stock('P1', now()->addYear()->toDateString());
        $this->sell()->assertCreated();

        $data = $this->dashboard();

        $this->assertCount(7, $data['series']);
        $this->assertSame(now()->toDateString(), $data['series'][6]['date']);
        $this->assertSame(now()->subDays(6)->toDateString(), $data['series'][0]['date']);

        $this->assertEqualsWithDelta(112, $data['series'][6]['sales'], 0.01);
        $this->assertEqualsWithDelta(700, $data['series'][6]['purchases'], 0.01);
        $this->assertEqualsWithDelta(0, $data['series'][0]['sales'], 0.01);
    }

    /**
     * The week: what sold, what it was made of, and what paid for it.
     *
     * Ranked by what each item TOOK rather than by how many left the shelf —
     * a shop that ranks by count learns only that it sells paracetamol.
     */
    public function test_the_week_ranks_what_sells_and_splits_it_by_category_and_tender(): void
    {
        $this->onTenant($this->organization, fn () => Medicine::on('organization')
            ->whereKey([$this->otc, $this->medicine])
            ->update(['category' => 'Analgesic']));

        $this->onTenant($this->organization, fn () => Medicine::on('organization')
            ->whereKey($this->medicine)
            ->update(['category' => 'Antibiotic', 'prescription_required' => false]));

        $this->stock('P1', now()->addYear()->toDateString());
        $this->stock('M1', now()->addYear()->toDateString(), medicine: $this->medicine);

        // ₹112 of paracetamol, twice, against ₹224 of amoxicillin — so the
        // antibiotic tops the list on money while both sold 20 tablets.
        $this->sell()->assertCreated();
        $this->sell()->assertCreated();
        $this->sell([
            'items' => [['medicine_id' => $this->medicine, 'quantity' => 20]],
            'payments' => [['method' => 'upi', 'amount' => 224]],
        ])->assertCreated();

        $week = $this->dashboard()['week'];

        $this->assertEqualsWithDelta(448, $week['sold'], 0.01);
        $this->assertSame(3, $week['bills']);
        $this->assertSame(40, $week['items']);
        $this->assertEqualsWithDelta(149.33, $week['average_bill'], 0.01);

        // Money, not units: both sold 20, and the dearer one leads.
        $this->assertSame('Mox 500 (Amoxicillin) 500 mg tablet', $week['top_items'][0]['name']);
        $this->assertEqualsWithDelta(224, $week['top_items'][0]['revenue'], 0.01);
        $this->assertSame(20, $week['top_items'][0]['units']);

        $categories = collect($week['categories'])->pluck('value', 'label');

        $this->assertEqualsWithDelta(224, $categories['Antibiotic'], 0.01);
        $this->assertEqualsWithDelta(224, $categories['Analgesic'], 0.01);

        $tenders = collect($week['tenders'])->pluck('value', 'label');

        $this->assertEqualsWithDelta(224, $tenders['cash'], 0.01);
        $this->assertEqualsWithDelta(224, $tenders['upi'], 0.01);
        $this->assertArrayNotHasKey('On account', $tenders->all());
    }

    /** What was never paid is not a tender — it is the absence of one. */
    public function test_what_is_left_owed_shows_beside_the_tenders_rather_than_as_one(): void
    {
        $this->stock('P1', now()->addYear()->toDateString());

        $this->putJson('/api/v1/tenant/pharmacy/settings', ['credit_sales_enabled' => true])->assertOk();

        $customer = $this->onTenant($this->organization, fn () => Customer::on('organization')->create([
            'name' => 'Sunita Devi', 'phone' => '9876500000', 'code' => 'C-1', 'is_active' => true,
        ])->id);

        // ₹112 owed, of which ₹40 was paid in cash.
        $this->sell([
            'customer_id' => $customer,
            'payments' => [['method' => 'cash', 'amount' => 40]],
        ])->assertCreated();

        $tenders = collect($this->dashboard()['week']['tenders'])->keyBy('label');

        $this->assertEqualsWithDelta(40, $tenders['cash']['value'], 0.01);
        $this->assertEqualsWithDelta(72, $tenders['On account']['value'], 0.01);

        // Marked, so the chart reads it as an absence rather than a method.
        $this->assertTrue($tenders['On account']['muted']);
    }

    public function test_the_dashboard_is_behind_the_capability_that_reads_the_pharmacy(): void
    {
        $this->setStaffCapabilities($this->organization, ['pharmacy.sell']);
        $this->signInAsStaff($this->organization);

        $this->getJson("/api/v1/tenant/pharmacy-stores/{$this->store}/dashboard")->assertForbidden();

        $this->setStaffCapabilities($this->organization, ['pharmacy.view']);
        $this->signInAsStaff($this->organization);

        $this->getJson("/api/v1/tenant/pharmacy-stores/{$this->store}/dashboard")->assertOk();
    }
}
