<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\BranchMembership;
use App\Models\Tenant\BranchRoleCapability;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\InvoiceItem;
use App\Models\Tenant\InvoicePayment;
use App\Models\Tenant\Location;
use App\Models\Tenant\PharmacySale;
use App\Models\Tenant\PharmacySalePayment;
use App\Models\Tenant\PharmacySetting;
use App\Models\Tenant\PharmacyStore;
use App\Models\Tenant\Role;
use App\Models\Tenant\User as TenantUser;
use App\Services\Billing\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * Finance: every department's money, counted once.
 *
 * One organisation, two branches, and every way a figure could be counted
 * twice or not at all:
 *
 *   Gurgaon  OPD bill ₹500, paid in full, ₹100 refunded
 *            an older visit bill ₹612 carrying ₹112 of medicines, paid —
 *              and the pharmacy sale those medicines came from, marked
 *              `billed_via_invoice` (its value and money are on the invoice)
 *            a cancelled OPD bill ₹999 that nobody paid
 *            pharmacy sale ₹300, paid
 *            pharmacy sale ₹100 on credit, unpaid
 *            pharmacy sale ₹50 cancelled before cancellations recorded their
 *              refund — a payment row, and no refund row
 *   Delhi    OPD bill ₹200, unpaid
 *
 * So, across both branches:
 *
 *   billed       500 + 612 + 300 + 100 + 200       = 1712
 *   collected    500 + 612 + 300                   = 1412
 *   refunded     100
 *   net          1312
 *   outstanding  100 (pharmacy credit) + 200 (Delhi) = 300
 */
class FinanceReportTest extends TenantTestCase
{
    use RefreshDatabase;

    private const A_DAY = '2026-09-07 11:00:00';

    private Organization $organization;

    private int $gurgaon;

    private int $delhi;

    private int $patient;

    private int $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(self::A_DAY);
        $this->artisan('modules:sync');

        $this->organization = $this->provisionOrganization('FR');
        $this->grantModule($this->organization, 'medicines');
        $this->grantModule($this->organization, 'pharmacy');
        $this->allowPartPayments($this->organization);

        $this->onTenant($this->organization, function () {
            $this->gurgaon = Location::on('organization')->create([
                'name' => 'Gurgaon', 'code' => 'FR-GGN', 'type' => Location::CLINIC, 'is_active' => true,
            ])->id;
            $this->delhi = Location::on('organization')->create([
                'name' => 'Delhi', 'code' => 'FR-DEL', 'type' => Location::CLINIC, 'is_active' => true,
            ])->id;
            $this->patient = Customer::on('organization')->create([
                'name' => 'Kiran Rao', 'phone' => '9810000041', 'is_active' => true,
            ])->id;
            $this->store = PharmacyStore::on('organization')->create([
                'location_id' => $this->gurgaon, 'name' => 'Counter', 'code' => 'FR-A',
                'store_type' => PharmacyStore::RETAIL, 'is_active' => true, 'is_default' => true,
            ])->id;

            $billing = app(BillingService::class);

            // Gurgaon OPD: ₹500, paid, ₹100 back.
            $paid = $this->invoice($this->gurgaon, [['source_type' => 'service', 'description' => 'Consultation', 'unit_price' => 500]]);
            $billing->recordPayment($paid, ['method' => 'cash', 'amount' => 500]);
            $payment = InvoicePayment::on('organization')->where('invoice_id', $paid->id)->firstOrFail();
            $billing->refund($paid->fresh(), $payment, 100, 'Discount agreed');

            // An older visit bill that took a pharmacy sale's money over.
            $legacySale = $this->sale(112, 0, PharmacySale::BILLED_VIA_INVOICE);
            $visit = $billing->finalize($billing->draw(
                ['location_id' => $this->gurgaon, 'customer_id' => $this->patient, 'trigger' => 'pharmacy', 'kind' => Invoice::KIND_VISIT],
                [
                    ['source_type' => 'service', 'description' => 'Consultation', 'unit_price' => 500],
                    ['source_type' => InvoiceItem::SOURCE_PHARMACY_SALE_ITEM, 'source_id' => $legacySale, 'description' => 'Medicines', 'unit_price' => 112],
                ],
            ));
            $billing->recordPayment($visit, ['method' => 'upi', 'amount' => 612]);

            // Cancelled before anybody paid: raised nothing.
            $billing->cancel($this->invoice($this->gurgaon, [['source_type' => 'custom', 'description' => 'Typo', 'unit_price' => 999]]), 'Raised in error');

            // Delhi OPD: ₹200, unpaid.
            $this->invoice($this->delhi, [['source_type' => 'service', 'description' => 'Consultation', 'unit_price' => 200]]);

            // Pharmacy.
            $this->sale(300, 300, PharmacySale::PAID, 'cash');
            $this->sale(100, 0, PharmacySale::UNPAID);
            $this->sale(50, 50, PharmacySale::PAID, 'cash', cancelled: true);
        });

        $this->signInAsOwner($this->organization);
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function invoice(int $branch, array $lines): Invoice
    {
        return app(BillingService::class)->draw(
            ['location_id' => $branch, 'customer_id' => $this->patient, 'trigger' => 'manual', 'kind' => Invoice::KIND_MANUAL],
            $lines,
        );
    }

    private function sale(float $total, float $paid, string $status, ?string $method = null, bool $cancelled = false): int
    {
        $sale = PharmacySale::on('organization')->create([
            'pharmacy_store_id' => $this->store,
            'location_id' => $this->gurgaon,
            'customer_id' => $this->patient,
            'sale_date' => now(),
            'price_basis' => PharmacySetting::PRICE_MRP,
            'prices_include_tax' => true,
            'subtotal' => $total,
            'total_amount' => $total,
            'paid_amount' => $paid,
            'payment_status' => $status,
        ]);

        if ($method && $paid > 0) {
            PharmacySalePayment::on('organization')->create([
                'pharmacy_sale_id' => $sale->id, 'method' => $method, 'amount' => $paid, 'paid_at' => now(),
            ]);
        }

        if ($cancelled) {
            $sale->forceFill(['status' => PharmacySale::CANCELLED, 'cancelled_at' => now(), 'cancellation_reason' => 'Old'])->save();
        }

        return $sale->id;
    }

    private function summary(array $query = [])
    {
        return $this->getJson('/api/v1/tenant/finance/summary?'.http_build_query([
            'from' => '2026-09-01', 'to' => '2026-09-30', ...$query,
        ]));
    }

    public function test_every_figure_is_counted_once(): void
    {
        $data = $this->summary()->assertOk()->json('data');

        $this->assertEquals(1712, $data['totals']['billed']);
        $this->assertEquals(1412, $data['totals']['collected']);
        $this->assertEquals(100, $data['totals']['refunded']);
        $this->assertEquals(1312, $data['totals']['net_collected']);
        $this->assertEquals(300, $data['totals']['outstanding']);

        $modules = collect($data['modules'])->keyBy('key');

        $this->assertEquals(1312, $modules['opd']['billed']);
        $this->assertEquals(1112, $modules['opd']['collected']);
        $this->assertEquals(100, $modules['opd']['refunded']);
        $this->assertEquals(200, $modules['opd']['outstanding']);

        $this->assertEquals(400, $modules['pharmacy']['billed'], 'The sale an invoice took over is not billed twice.');
        $this->assertEquals(300, $modules['pharmacy']['collected'], 'The old cancelled sale is not money still held.');
        $this->assertEquals(100, $modules['pharmacy']['outstanding']);

        $this->assertEquals(0, $modules['laboratory']['billed']);
    }

    public function test_money_is_reported_by_branch_and_by_method(): void
    {
        $data = $this->summary()->assertOk()->json('data');

        $branches = collect($data['branches'])->keyBy('name');
        $this->assertEquals(1512, $branches['Gurgaon']['billed']);
        $this->assertEquals(200, $branches['Delhi']['billed']);
        $this->assertEquals(200, $branches['Delhi']['outstanding']);

        $methods = collect($data['methods'])->keyBy('method');
        // ₹500 at the OPD and ₹300 at the pharmacy, across both registers.
        $this->assertEquals(800, $methods['cash']['collected']);
        $this->assertEquals(100, $methods['cash']['refunded']);
        $this->assertEquals(612, $methods['upi']['net']);

        $this->assertEquals(1312, collect($data['series'])->sum('net'));
    }

    public function test_a_window_counts_only_what_happened_inside_it(): void
    {
        $data = $this->summary(['from' => '2026-10-01', 'to' => '2026-10-31'])->assertOk()->json('data');

        $this->assertEquals(0, $data['totals']['billed']);
        $this->assertEquals(0, $data['totals']['collected']);
        // What is owed is owed today, whenever it was billed.
        $this->assertEquals(300, $data['totals']['outstanding']);
    }

    public function test_one_list_holds_every_departments_bills(): void
    {
        $this->getJson('/api/v1/tenant/finance/documents')->assertOk()->assertJsonPath('data.meta.total', 8);

        $this->getJson('/api/v1/tenant/finance/documents?module=pharmacy')->assertOk()->assertJsonPath('data.meta.total', 4);

        $owing = $this->getJson('/api/v1/tenant/finance/documents?outstanding_only=1')->assertOk()->json('data');
        $this->assertSame(2, $owing['meta']['total']);
        $this->assertEqualsCanonicalizing([100, 200], array_column($owing['data'], 'outstanding'));
    }

    public function test_a_branch_reads_only_its_own_money(): void
    {
        $this->placeStaffAt($this->organization, $this->delhi);
        $this->setStaffCapabilities($this->organization, ['billing.view', 'billing.finance']);
        $this->signInAsStaff($this->organization);

        $data = $this->summary()->assertOk()->json('data');

        $this->assertEquals(200, $data['totals']['billed']);
        $this->assertEquals(0, $data['totals']['collected']);
        $this->assertCount(1, $data['branches']);

        $this->summary(['location_id' => $this->gurgaon])->assertForbidden();
    }

    public function test_finance_needs_its_own_permission(): void
    {
        $this->placeStaffAt($this->organization, $this->gurgaon);
        $this->setStaffCapabilities($this->organization, ['billing.view', 'billing.collect_payment']);
        $this->signInAsStaff($this->organization);

        $this->summary()->assertForbidden();
        $this->getJson('/api/v1/tenant/finance/documents')->assertForbidden();
    }

    /**
     * An organisation set up before Finance existed: its seeded roles were
     * written without the key, and the role seeder never edits a role.
     */
    public function test_existing_roles_that_run_the_money_are_given_finance(): void
    {
        $roles = $this->onTenant($this->organization, function () {
            // Seeded roles already exist; set each back to how it was before the release.
            $role = function (string $slug, array $capabilities) {
                $role = Role::on('organization')->firstOrCreate(
                    ['slug' => $slug],
                    ['name' => ucwords(str_replace('-', ' ', $slug)), 'scope' => Role::SCOPE_BRANCH],
                );
                $role->load('capabilities')->syncCapabilities($capabilities);

                return $role->id;
            };

            $ids = [
                'manager' => $role('branch-manager', ['billing.view', 'billing.create']),
                'accountant' => $role('accountant', ['billing.view']),
                // An owner took billing away from head office: it stays away.
                'head_office' => $role('head-office', ['customers.view']),
                // Written by hand: not this migration's to guess about.
                'cashier' => $role('cashier', ['billing.view', 'billing.collect_payment']),
            ];

            // Delhi took bills away from its branch managers.
            BranchRoleCapability::on('organization')->create([
                'location_id' => $this->delhi, 'role_id' => $ids['manager'], 'capability' => 'billing.view',
            ]);

            $migration = require base_path('database/migrations/organization/2026_10_22_000300_give_finance_to_the_roles_that_run_the_money.php');

            $previous = DB::getDefaultConnection();
            DB::setDefaultConnection('organization');

            try {
                $migration->up();
                $migration->up(); // replays cleanly
            } finally {
                DB::setDefaultConnection($previous);
            }

            return $ids;
        });

        $this->onTenant($this->organization, function () use ($roles) {
            $grants = fn (int $id) => Role::on('organization')->findOrFail($id)->grants('billing.finance');

            $this->assertTrue($grants($roles['manager']));
            $this->assertTrue($grants($roles['accountant']));
            $this->assertFalse($grants($roles['head_office']));
            $this->assertFalse($grants($roles['cashier']));

            $clipped = BranchRoleCapability::on('organization')
                ->where(['role_id' => $roles['manager'], 'capability' => 'billing.finance'])
                ->pluck('location_id')
                ->all();
            $this->assertSame([$this->delhi], $clipped, 'Taken away where bills were, and only there.');

            // A Gurgaon branch manager now opens the screen.
            $user = TenantUser::on('organization')->where('email', self::STAFF_EMAIL)->firstOrFail();
            BranchMembership::on('organization')->updateOrCreate(
                ['user_id' => $user->id, 'location_id' => $this->gurgaon],
                ['role_id' => $roles['manager']],
            );
            $user->forceFill(['role_id' => null])->save();
        });

        $this->signInAsStaff($this->organization);

        $this->assertEquals(1512, $this->summary()->assertOk()->json('data.totals.billed'));
    }

    public function test_another_organisation_sees_none_of_it(): void
    {
        $gurgaonBill = $this->onTenant($this->organization, fn () => Invoice::on('organization')->value('id'));

        $other = $this->provisionOrganization('FX');
        $this->signInAsOwner($other);

        $data = $this->summary()->assertOk()->json('data');
        $this->assertEquals(0, $data['totals']['billed']);
        $this->assertEquals(0, $data['totals']['outstanding']);

        $this->getJson('/api/v1/tenant/finance/documents')->assertOk()->assertJsonPath('data.meta.total', 0);
        $this->getJson("/api/v1/tenant/invoices/{$gurgaonBill}")->assertNotFound();
    }
}
