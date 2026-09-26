<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\Location;
use App\Models\Tenant\Medicine;
use App\Models\Tenant\MedicineBatch;
use App\Models\Tenant\PharmacyStore;
use App\Models\Tenant\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * The pharmacy's reports, read both ways.
 *
 * The promise being tested is the one that makes a report worth trusting:
 * the summary and the table are the same query. A figure at the top has to
 * equal the sum of the rows underneath it — otherwise one of them is wrong
 * and nobody can tell which.
 *
 * Round figures throughout: a strip of 10 bought at ₹70 and sold at ₹112 MRP
 * is ₹7 a tablet against ₹11.20, so 10 tablets is ₹112 with ₹12 of GST inside
 * it and ₹30 of margin.
 */
class PharmacyReportsTest extends TenantTestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private int $branch;

    private int $store;

    private int $otc;

    private int $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');

        $this->organization = $this->provisionOrganization('R');
        $this->grantModule($this->organization, 'medicines');
        $this->grantModule($this->organization, 'pharmacy');
        // Reports is now sold separately from the rest of the pharmacy.
        $this->grantModule($this->organization, 'reports');

        $this->onTenant($this->organization, function () {
            $this->branch = Location::on('organization')->create([
                'name' => 'Pratapgarh', 'code' => 'R-PBH', 'type' => Location::RETAIL_STORE, 'is_active' => true,
            ])->id;

            $this->store = PharmacyStore::on('organization')->create([
                'location_id' => $this->branch,
                'name' => 'Front Counter',
                'code' => 'PBH-A',
                'store_type' => PharmacyStore::RETAIL,
                'is_active' => true,
                'is_default' => true,
            ])->id;

            $this->otc = Medicine::on('organization')->create([
                'generic_name' => 'Paracetamol',
                'brand_name' => 'Dolo 650',
                'strength' => '650 mg',
                'dosage_form' => 'tablet',
                'base_unit' => 'tablet',
                'pack_size' => 10,
                'prescription_required' => false,
                'category' => 'Analgesic',
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

    private function stock(string $batchNumber, string $expiry, int $packs = 10): int
    {
        $this->postJson("/api/v1/tenant/pharmacy-stores/{$this->store}/inwards", [
            'inward_type' => 'purchase',
            'supplier_id' => $this->supplier,
            'supplier_invoice_no' => 'GRN-'.Str::random(6),
            'received_date' => now()->toDateString(),
            'items' => [[
                'medicine_id' => $this->otc,
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

    private function sell(int $tablets = 10, string $method = 'cash')
    {
        return $this->postJson("/api/v1/tenant/pharmacy-stores/{$this->store}/sales", [
            'items' => [['medicine_id' => $this->otc, 'quantity' => $tablets]],
            'payments' => [['method' => $method, 'amount' => round(11.2 * $tablets, 2)]],
        ], ['Idempotency-Key' => (string) Str::uuid()]);
    }

    /** @return array<string, mixed> */
    private function summary(string $report): array
    {
        return $this->getJson("/api/v1/tenant/pharmacy-stores/{$this->store}/reports/{$report}/summary")
            ->assertOk()
            ->json('data');
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(string $report, string $query = ''): array
    {
        return $this->getJson("/api/v1/tenant/pharmacy-stores/{$this->store}/reports/{$report}?{$query}")
            ->assertOk()
            ->json('data');
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function sum(array $rows, string $column): float
    {
        return round(array_sum(array_column($rows, $column)), 2);
    }

    /** A figure from the top of a report, by its label. */
    private function figure(array $summary, string $label): float
    {
        return (float) collect($summary['figures'])->firstWhere('label', $label)['value'];
    }

    public function test_every_report_answers_in_both_shapes(): void
    {
        $this->stock('P1', now()->addYear()->toDateString());
        $this->sell();

        foreach (['sales', 'purchases', 'stock', 'expiry', 'profit', 'gst'] as $report) {
            $summary = $this->summary($report);

            $this->assertSame($report, $summary['report']);
            $this->assertNotEmpty($summary['figures'], "{$report} has no headline figures");
            // Every report divides its total up at least one way, and says
            // how that split should be drawn.
            $this->assertNotEmpty($summary['splits'], "{$report} has no splits");
            $this->assertArrayHasKey('rows', $summary['splits'][0]);
            $this->assertContains($summary['splits'][0]['kind'], ['donut', 'bars']);

            $this->getJson("/api/v1/tenant/pharmacy-stores/{$this->store}/reports/{$report}")
                ->assertOk()
                ->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'per_page', 'total']]);
        }

        // A report nobody wrote is not a report.
        $this->getJson("/api/v1/tenant/pharmacy-stores/{$this->store}/reports/invented/summary")
            ->assertNotFound();
    }

    /** The promise: the top of a report is the sum of what is under it. */
    public function test_the_summary_is_the_sum_of_the_rows(): void
    {
        $this->stock('P1', now()->addYear()->toDateString());

        $this->sell(10, 'cash');
        $this->sell(20, 'upi');

        $sales = $this->summary('sales');
        $rows = $this->rows('sales');

        $this->assertEqualsWithDelta(336, $this->figure($sales, 'Taken'), 0.01);
        $this->assertSame(2.0, $this->figure($sales, 'Bills'));
        $this->assertEqualsWithDelta($this->sum($rows, 'total'), $this->figure($sales, 'Taken'), 0.01);

        // 30 tablets at ₹10 of goods and ₹1.20 of tax apiece.
        $gst = $this->summary('gst');
        $gstRows = $this->rows('gst');

        $this->assertEqualsWithDelta(300, $this->figure($gst, 'Taxable value'), 0.01);
        $this->assertEqualsWithDelta(36, $this->figure($gst, 'GST collected'), 0.01);
        $this->assertEqualsWithDelta($this->sum($gstRows, 'tax'), $this->figure($gst, 'GST collected'), 0.01);
        $this->assertSame('3004', $gstRows[0]['hsn']);

        // ₹300 of goods against ₹210 of stock.
        $profit = $this->summary('profit');
        $profitRows = $this->rows('profit');

        $this->assertEqualsWithDelta(90, $this->figure($profit, 'Margin'), 0.01);
        $this->assertEqualsWithDelta(30, $this->figure($profit, 'Margin %'), 0.1);
        $this->assertEqualsWithDelta($this->sum($profitRows, 'margin'), $this->figure($profit, 'Margin'), 0.01);
    }

    /** A cancelled bill is reversed, not deleted — and never counted twice. */
    public function test_a_cancelled_bill_leaves_the_takings_and_the_margin(): void
    {
        $this->stock('P1', now()->addYear()->toDateString());

        $keep = $this->sell()->json('data.id');
        $undo = $this->sell()->json('data.id');

        $this->postJson("/api/v1/tenant/sales/{$undo}/cancel", ['reason' => 'Rung up twice'])->assertOk();

        $this->assertEqualsWithDelta(112, $this->figure($this->summary('sales'), 'Taken'), 0.01);
        $this->assertEqualsWithDelta(30, $this->figure($this->summary('profit'), 'Margin'), 0.01);

        $numbers = array_column($this->rows('sales'), 'id');

        $this->assertContains($keep, $numbers);
        $this->assertNotContains($undo, $numbers);
    }

    /**
     * Stock is what could be sold today; expiry is what is about to stop
     * being that. The two reports disagree on purpose.
     */
    public function test_expired_stock_is_valued_by_expiry_and_ignored_by_stock(): void
    {
        $fresh = $this->stock('FRESH', now()->addYear()->toDateString(), packs: 5);
        $short = $this->stock('SHORT', now()->addMonths(2)->toDateString(), packs: 2);

        $stock = $this->summary('stock');

        // 70 tablets at ₹7: everything received is still sellable.
        $this->assertEqualsWithDelta(490, $this->figure($stock, 'Value at cost'), 0.01);
        $this->assertEqualsWithDelta(784, $this->figure($stock, 'Value at MRP'), 0.01);

        // Only the short-dated batch is worth warning about.
        $expiry = $this->rows('expiry');

        $this->assertCount(1, $expiry);
        $this->assertSame('SHORT', $expiry[0]['batch']);
        $this->assertEqualsWithDelta(140, $expiry[0]['at_cost'], 0.01);

        // The calendar moves; the batch does not.
        $this->travel(70)->days();

        $this->assertEqualsWithDelta(350, $this->figure($this->summary('stock'), 'Value at cost'), 0.01);
        $this->assertEqualsWithDelta(140, $this->figure($this->summary('expiry'), 'Already expired'), 0.01);

        $this->assertSame([$fresh, $short], [$fresh, $short]);
    }

    public function test_the_range_and_the_search_narrow_the_same_rows(): void
    {
        $this->stock('P1', now()->addYear()->toDateString());
        $this->sell();

        // A window that ended before the sale holds nothing.
        $before = now()->subDays(10)->toDateString();
        $earlier = now()->subDays(20)->toDateString();

        $this->assertEqualsWithDelta(
            0,
            $this->getJson("/api/v1/tenant/pharmacy-stores/{$this->store}/reports/sales/summary?from={$earlier}&to={$before}")
                ->assertOk()
                ->json('data.figures.0.value'),
            0.01,
        );

        $this->assertCount(0, $this->rows('sales', "from={$earlier}&to={$before}"));
        $this->assertCount(1, $this->rows('sales'));

        // Searching a bill number nobody has issued finds nothing either.
        $this->assertCount(0, $this->rows('sales', 'search=INV-90909'));
    }

    /**
     * Each report is its own capability — NOT `pharmacy.view`, which also
     * opens the dashboard, the stock list and settings. Holding one report's
     * key must not open another: that split, sales visible and profit not,
     * is the entire reason six capabilities exist instead of one.
     */
    public function test_each_report_needs_its_own_capability(): void
    {
        // Holding the general pharmacy capability alone is not enough — it
        // used to be, and that was the bug this module exists to fix.
        $this->setStaffCapabilities($this->organization, ['pharmacy.view']);
        $this->signInAsStaff($this->organization);

        $this->getJson("/api/v1/tenant/pharmacy-stores/{$this->store}/reports/sales/summary")
            ->assertForbidden();

        // Sales specifically, and nothing else — profit stays shut.
        $this->setStaffCapabilities($this->organization, ['pharmacy.view', 'reports.sales']);
        $this->signInAsStaff($this->organization);

        $this->getJson("/api/v1/tenant/pharmacy-stores/{$this->store}/reports/sales/summary")->assertOk();
        $this->getJson("/api/v1/tenant/pharmacy-stores/{$this->store}/reports/sales")->assertOk();

        $this->getJson("/api/v1/tenant/pharmacy-stores/{$this->store}/reports/profit/summary")
            ->assertForbidden();
        $this->getJson("/api/v1/tenant/pharmacy-stores/{$this->store}/reports/profit")
            ->assertForbidden();

        // And with none of the six, every report is shut.
        $this->setStaffCapabilities($this->organization, ['pharmacy.view', 'customers.view']);
        $this->signInAsStaff($this->organization);

        $this->getJson("/api/v1/tenant/pharmacy-stores/{$this->store}/reports/sales/summary")
            ->assertForbidden();
        $this->getJson("/api/v1/tenant/pharmacy-stores/{$this->store}/reports/sales")
            ->assertForbidden();
    }

    /**
     * The Reports screen has to know which store it is reporting on before it
     * can ask for anything else — so a role holding only a report, and none of
     * `pharmacy.view`, must still be able to list the stores to pick from.
     * Everything else about a store — its detail, its medicines — stays shut,
     * because a report never asks either of those questions.
     */
    public function test_a_role_holding_only_a_report_can_still_list_stores_to_pick_one(): void
    {
        $this->setStaffCapabilities($this->organization, ['reports.sales']);
        $this->signInAsStaff($this->organization);

        $listed = array_column(
            $this->getJson('/api/v1/tenant/pharmacy-stores?all=1')->assertOk()->json('data'),
            'id',
        );
        $this->assertContains($this->store, $listed);

        $this->getJson("/api/v1/tenant/pharmacy-stores/{$this->store}")->assertForbidden();
        $this->getJson("/api/v1/tenant/pharmacy-stores/{$this->store}/medicines")->assertForbidden();
    }

    /** Sold separately: an organization without the module reaches no report at all. */
    public function test_reports_are_refused_without_their_own_module(): void
    {
        $bare = $this->provisionOrganization('RB');
        $this->grantModule($bare, 'medicines');
        $this->grantModule($bare, 'pharmacy');
        // Deliberately no `reports` grant.

        $branch = $this->onTenant($bare, fn () => Location::on('organization')->create([
            'name' => 'No Reports', 'code' => 'RB-1', 'type' => Location::RETAIL_STORE, 'is_active' => true,
        ])->id);

        $store = $this->onTenant($bare, fn () => PharmacyStore::on('organization')->create([
            'location_id' => $branch,
            'name' => 'Counter',
            'code' => 'RB-A',
            'store_type' => PharmacyStore::RETAIL,
            'is_active' => true,
            'is_default' => true,
        ])->id);

        // An owner bypasses roles entirely, so a 403 here can only be the module.
        $this->signInAsOwner($bare);

        $this->getJson("/api/v1/tenant/pharmacy-stores/{$store}/reports/sales/summary")
            ->assertForbidden();
    }
}
