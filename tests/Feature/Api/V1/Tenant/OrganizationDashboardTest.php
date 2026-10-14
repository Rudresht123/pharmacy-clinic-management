<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\Location;
use App\Models\Tenant\Medicine;
use App\Models\Tenant\PharmacySale;
use App\Models\Tenant\PharmacySetting;
use App\Models\Tenant\PharmacyStore;
use App\Models\Tenant\Supplier;
use App\Services\Billing\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Head office's dashboard — the network view.
 *
 * One organisation, two branches, one September:
 *
 *   Gurgaon  OPD bill: consultation ₹500 + procedure ₹300
 *            a cancelled bill ₹999, which earned nothing
 *            pharmacy sale ₹200
 *            stock bought in: ₹700
 *   Delhi    a bill for a lab test ₹400
 *
 *   revenue    500 + 300 + 200 + 400 = 1400
 *   purchases  700
 *   profit     700
 */
class OrganizationDashboardTest extends TenantTestCase
{
    use RefreshDatabase;

    private const A_DAY = '2026-09-15 11:00:00';

    private Organization $organization;

    private int $gurgaon;

    private int $delhi;

    private int $patient;

    private int $store;

    private int $medicine;

    private int $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(self::A_DAY);
        $this->artisan('modules:sync');

        config(['hms.dashboard_demo' => false]);

        $this->organization = $this->provisionOrganization('OD');
        $this->grantModule($this->organization, 'medicines');
        $this->grantModule($this->organization, 'pharmacy');
        $this->grantModule($this->organization, 'appointments');

        $this->onTenant($this->organization, function () {
            $this->gurgaon = Location::on('organization')->create([
                'name' => 'Gurgaon', 'code' => 'OD-GGN', 'city' => 'Gurugram', 'type' => Location::CLINIC, 'is_active' => true,
            ])->id;
            $this->delhi = Location::on('organization')->create([
                'name' => 'Delhi', 'code' => 'OD-DEL', 'city' => 'Delhi', 'type' => Location::CLINIC, 'is_active' => true,
            ])->id;
            $this->patient = Customer::on('organization')->create([
                'name' => 'Kiran Rao', 'phone' => '9810000051', 'is_active' => true, 'registered_location_id' => $this->gurgaon,
            ])->id;
            $this->store = PharmacyStore::on('organization')->create([
                'location_id' => $this->gurgaon, 'name' => 'Counter', 'code' => 'OD-A',
                'store_type' => PharmacyStore::RETAIL, 'is_active' => true, 'is_default' => true,
            ])->id;
            $this->medicine = Medicine::on('organization')->create([
                'generic_name' => 'Paracetamol', 'brand_name' => 'Dolo 650', 'strength' => '650 mg',
                'dosage_form' => 'tablet', 'base_unit' => 'tablet', 'pack_size' => 10,
                'prescription_required' => false, 'tax_rate' => 12, 'hsn_code' => '3004',
            ])->id;
            $this->supplier = Supplier::on('organization')->create([
                'name' => 'Micro Distributors', 'is_active' => true,
            ])->id;

            $billing = app(BillingService::class);

            $this->invoice($this->gurgaon, [
                ['source_type' => 'consultation', 'description' => 'Consultation', 'unit_price' => 500],
                ['source_type' => 'procedure', 'description' => 'Dressing', 'unit_price' => 300],
            ]);

            $billing->cancel(
                $this->invoice($this->gurgaon, [['source_type' => 'custom', 'description' => 'Typo', 'unit_price' => 999]]),
                'Raised in error',
            );

            $this->invoice($this->delhi, [['source_type' => 'lab_test', 'description' => 'CBC', 'unit_price' => 400]]);

            PharmacySale::on('organization')->create([
                'pharmacy_store_id' => $this->store,
                'location_id' => $this->gurgaon,
                'customer_id' => $this->patient,
                'sale_date' => now(),
                'price_basis' => PharmacySetting::PRICE_MRP,
                'prices_include_tax' => true,
                'subtotal' => 200,
                'total_amount' => 200,
                'paid_amount' => 200,
                'payment_status' => PharmacySale::PAID,
            ]);
        });

        $this->signInAsOwner($this->organization);

        // ₹700 of stock: ten packs at ₹70.
        $this->stock('P1', now()->addYear()->toDateString());
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function invoice(int $branch, array $lines): Invoice
    {
        return app(BillingService::class)->draw(
            ['location_id' => $branch, 'customer_id' => $this->patient, 'trigger' => 'manual', 'kind' => Invoice::KIND_MANUAL],
            $lines,
        );
    }

    private function stock(string $batch, string $expiry, int $packs = 10): void
    {
        $this->postJson("/api/v1/tenant/pharmacy-stores/{$this->store}/inwards", [
            'inward_type' => 'purchase',
            'supplier_id' => $this->supplier,
            'supplier_invoice_no' => 'GRN-'.Str::random(6),
            'received_date' => now()->toDateString(),
            'items' => [[
                'medicine_id' => $this->medicine,
                'batch_number' => $batch,
                'expiry_date' => $expiry,
                'quantity' => $packs,
                'purchase_price' => 70,
                'mrp' => 112,
            ]],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
    }

    /** @return array<string, mixed> */
    private function dashboard(string $query = ''): array
    {
        return $this->getJson('/api/v1/tenant/dashboard'.$query)->assertOk()->json('data');
    }

    public function test_head_office_gets_the_network_panels(): void
    {
        $data = $this->dashboard();

        $this->assertSame('organization', $data['context']);

        foreach (['overview', 'kpis', 'revenue_overview', 'branch_performance', 'visit_trends', 'top_services', 'stock_alerts', 'profit_loss', 'branch_activity'] as $panel) {
            $this->assertArrayHasKey($panel, $data, "{$panel} is missing");
        }

        // The month by default, compared with the month before.
        $this->assertSame(['from' => '2026-09-01', 'to' => '2026-09-30', 'compare' => 'vs last month'], $data['scope']['window']);

        // The branches a card can narrow to.
        $this->assertSame(['Delhi', 'Gurgaon'], array_column($data['scope']['options'], 'name'));

        $this->assertSame(2, $data['overview']['branches']);
    }

    public function test_revenue_purchases_and_profit_add_up(): void
    {
        $data = $this->dashboard();

        $kpis = collect($data['kpis'])->keyBy('key');

        $this->assertEquals(1400, $kpis['revenue']['value'], 'The cancelled bill earned nothing.');
        $this->assertEquals(700, $kpis['purchases']['value']);
        $this->assertEquals(700, $kpis['profit']['value']);

        // A day per point across the month, the money landing on the 15th.
        $this->assertCount(30, $kpis['revenue']['series']);
        $this->assertEquals(1400, $kpis['revenue']['series'][14]);

        // August had nothing, so there is no percentage to print.
        $this->assertNull($kpis['revenue']['change']);
    }

    public function test_the_revenue_chart_splits_by_department(): void
    {
        $series = $this->dashboard()['revenue_overview']['series'];

        // Twelve months, ending with the window's own.
        $this->assertCount(12, $series);

        $september = end($series);

        $this->assertSame('2026-09', $september['key']);
        $this->assertEquals(500, $september['opd']);
        $this->assertEquals(300, $september['procedures']);
        $this->assertEquals(400, $september['laboratory']);
        $this->assertEquals(200, $september['pharmacy']);
        $this->assertEquals(1400, $september['total']);
    }

    public function test_services_are_ranked_by_what_they_earned(): void
    {
        $services = $this->dashboard()['top_services'];

        $this->assertEquals(1400, $services['total']);
        $this->assertSame(
            ['consultation', 'laboratory', 'procedures', 'pharmacy'],
            array_column($services['rows'], 'key'),
        );
    }

    public function test_branches_are_compared_by_revenue_and_profit(): void
    {
        $rows = $this->dashboard()['branch_performance']['rows'];

        $this->assertSame(['Gurgaon', 'Delhi'], array_column($rows, 'name'));

        // Gurgaon: 500 + 300 + 200 earned, 700 spent on stock.
        $this->assertEquals(1000, $rows[0]['revenue']);
        $this->assertEquals(300, $rows[0]['profit']);
        $this->assertSame(1, $rows[0]['patients']);

        $this->assertEquals(400, $rows[1]['revenue']);
        $this->assertEquals(400, $rows[1]['profit']);
    }

    public function test_profit_and_loss_covers_the_year(): void
    {
        $pnl = $this->dashboard()['profit_loss'];

        $this->assertEquals(1400, $pnl['revenue']['value']);
        $this->assertEquals(700, $pnl['expenses']['value']);
        $this->assertEquals(700, $pnl['profit']['value']);

        // January to this month.
        $this->assertCount(9, $pnl['revenue']['series']);
    }

    public function test_stock_alerts_list_what_needs_acting_on(): void
    {
        // 100 tablets on the shelf against a reorder level of 150.
        $this->putJson("/api/v1/tenant/pharmacy-stores/{$this->store}/medicines", [
            'medicines' => [['medicine_id' => $this->medicine, 'minimum_stock_level' => 0, 'reorder_level' => 150]],
        ])->assertOk();

        $alerts = $this->dashboard()['stock_alerts'];

        $this->assertSame(['expired' => 0, 'out' => 0, 'low' => 1], $alerts['counts']);
        $this->assertSame('low', $alerts['rows'][0]['status']);
        $this->assertSame(100, $alerts['rows'][0]['quantity']);
        $this->assertSame('tablets', $alerts['rows'][0]['unit']);
        $this->assertSame('Gurgaon', $alerts['rows'][0]['branch']);
    }

    public function test_visits_are_split_into_new_and_returning(): void
    {
        $this->onTenant($this->organization, function () {
            $doctor = Doctor::on('organization')->create(['name' => 'Dr. Mehta', 'is_active' => true])->id;

            $visit = fn (string $date, string $status) => Appointment::on('organization')->create([
                'customer_id' => $this->patient,
                'doctor_id' => $doctor,
                'location_id' => $this->gurgaon,
                'appointment_date' => $date,
                'type' => Appointment::BOOKED,
                'status' => $status,
            ]);

            // First time here, then back a week later; a booking still to
            // come is not a visit yet.
            $visit('2026-09-03', Appointment::STATUS_CHECKED_IN);
            $visit('2026-09-10', Appointment::STATUS_CHECKED_IN);
            $visit('2026-09-20', Appointment::STATUS_BOOKED);
        });

        $trends = $this->dashboard()['visit_trends'];

        $this->assertSame(1, $trends['new']);
        $this->assertSame(1, $trends['returning']);
        $this->assertSame(1, $trends['series'][2]['new']);
        $this->assertSame(1, $trends['series'][9]['returning']);
    }

    public function test_a_card_can_be_reasked_with_its_own_filter(): void
    {
        $weekly = $this->getJson('/api/v1/tenant/dashboard/revenue_overview?granularity=weekly')
            ->assertOk()
            ->json('data');

        $this->assertSame('weekly', $weekly['granularity']);
        $this->assertCount(12, $weekly['series']);

        $delhi = $this->getJson("/api/v1/tenant/dashboard/revenue_overview?location_id={$this->delhi}")
            ->assertOk()
            ->json('data');

        $this->assertEquals(400, array_sum(array_column($delhi['series'], 'total')));

        $this->getJson('/api/v1/tenant/dashboard/top_services?period=last_month')
            ->assertOk()
            ->assertJsonPath('data.rows', []);

        // Only the filterable cards can be asked for, and only with real filters.
        $this->getJson('/api/v1/tenant/dashboard/kpis')->assertNotFound();
        $this->getJson('/api/v1/tenant/dashboard/revenue_overview?granularity=hourly')->assertUnprocessable();
        $this->getJson('/api/v1/tenant/dashboard/revenue_overview?location_id=999999')->assertUnprocessable();
    }

    public function test_the_window_can_be_chosen(): void
    {
        $data = $this->dashboard('?from=2026-09-10&to=2026-09-20');

        $this->assertSame('vs previous period', $data['scope']['window']['compare']);
        $this->assertCount(11, collect($data['kpis'])->firstWhere('key', 'revenue')['series']);

        $this->getJson('/api/v1/tenant/dashboard?from=2026-09-20&to=2026-09-10')->assertUnprocessable();
    }

    /** Money is `billing.finance`; somebody without it sees none of it. */
    public function test_money_needs_the_finance_capability(): void
    {
        $this->setStaffCapabilities($this->organization, ['customers.view', 'branches.view']);
        $this->signInAsStaff($this->organization);

        $data = $this->dashboard();

        $this->assertSame('organization', $data['context']);

        foreach (['revenue_overview', 'top_services', 'profit_loss', 'stock_alerts', 'branch_activity'] as $panel) {
            $this->assertArrayNotHasKey($panel, $data, "{$panel} should be absent");
        }

        $this->assertSame(['patients'], array_column($data['kpis'], 'key'));

        // The branch table is there, its money columns empty rather than zero.
        foreach ($data['branch_performance']['rows'] as $row) {
            $this->assertNull($row['revenue']);
            $this->assertNull($row['profit']);
        }

        // And re-asking a card is refused the same way.
        $this->getJson('/api/v1/tenant/dashboard/revenue_overview')->assertNotFound();
    }

    /** Somebody standing in a branch gets that branch's day, never the network. */
    public function test_a_branch_dashboard_never_carries_the_network(): void
    {
        $this->setStaffCapabilities($this->organization, ['customers.view', 'branches.view', 'billing.finance']);
        $this->placeStaffAt($this->organization, $this->gurgaon);
        $this->signInAsStaff($this->organization);

        $data = $this->dashboard();

        $this->assertSame('branch', $data['context']);

        foreach (['overview', 'kpis', 'revenue_overview', 'branch_performance', 'top_services', 'profit_loss'] as $panel) {
            $this->assertArrayNotHasKey($panel, $data);
        }

        $this->getJson('/api/v1/tenant/dashboard/revenue_overview')->assertNotFound();
    }
}
