<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\InvoiceItem;
use App\Models\Tenant\Location;
use App\Services\Billing\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

/**
 * The Invoices screen: its list, its tab counts and cards, and its export.
 *
 * What is asserted: search finds a registered patient by name and phone, not
 * only a walk-in; the type filter finds a bill by what is on it; the list
 * sorts by what is owed and by patient; each row carries what its columns
 * read; the tab counts follow the filters while the cards describe the whole
 * register; and the export is the list on screen — or only the ticked rows.
 */
class InvoiceListTest extends TenantTestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private int $branch;

    /** @var array<string, int> */
    private array $patient = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-15 11:00:00');

        $this->organization = $this->provisionOrganization('IL');

        $this->onTenant($this->organization, function () {
            $this->branch = Location::create([
                'name' => 'Gurgaon', 'code' => 'GGN', 'type' => Location::CLINIC, 'is_active' => true,
            ])->id;

            foreach (['Asha Verma' => '9811100001', 'Ravi Kumar' => '9811100002'] as $name => $phone) {
                $this->patient[$name] = Customer::create(['name' => $name, 'phone' => $phone, 'location_id' => $this->branch])->id;
            }
        });

        $this->signInAsOwner($this->organization);
    }

    /** @param list<array{0: string, 1: float}> $lines  [source type, price] */
    private function bill(string $patient, array $lines, float $pay = 0): Invoice
    {
        return $this->onTenant($this->organization, function () use ($patient, $lines, $pay) {
            $billing = app(BillingService::class);

            $invoice = $billing->draw(
                [
                    'location_id' => $this->branch,
                    'customer_id' => $this->patient[$patient],
                    'trigger' => 'manual',
                    'kind' => Invoice::KIND_MANUAL,
                ],
                array_map(fn (array $line) => [
                    'source_type' => $line[0],
                    'description' => ucfirst($line[0]).' line',
                    'unit_price' => $line[1],
                ], $lines),
            );

            if ($pay > 0) {
                $billing->recordPayment($invoice->fresh(), ['method' => 'cash', 'amount' => $pay]);
            }

            return $invoice->fresh();
        });
    }

    private function list(array $query = [])
    {
        return $this->withHeader('X-Branch-Id', (string) $this->branch)
            ->getJson('/api/v1/tenant/invoices?'.http_build_query($query));
    }

    public function test_search_finds_a_registered_patient_by_name_and_phone(): void
    {
        $asha = $this->bill('Asha Verma', [[InvoiceItem::SOURCE_CUSTOM, 500]]);
        $this->bill('Ravi Kumar', [[InvoiceItem::SOURCE_CUSTOM, 300]]);

        $this->assertSame([$asha->id], array_column($this->list(['search' => 'asha'])->assertOk()->json('data'), 'id'));
        $this->assertSame([$asha->id], array_column($this->list(['search' => '9811100001'])->json('data'), 'id'));
    }

    public function test_each_row_carries_what_its_columns_read(): void
    {
        $this->bill('Asha Verma', [[InvoiceItem::SOURCE_LAB_TEST, 400], [InvoiceItem::SOURCE_CUSTOM, 100]]);

        $row = $this->list()->assertOk()->json('data.0');

        $this->assertSame(2, $row['items_count']);
        $this->assertSame('9811100001', $row['customer_phone']);
        $this->assertSame(['lab_test', 'custom'], array_column($row['items'], 'source_type'));
    }

    public function test_the_type_filter_finds_a_bill_by_what_is_on_it(): void
    {
        $lab = $this->bill('Asha Verma', [[InvoiceItem::SOURCE_LAB_TEST, 400], [InvoiceItem::SOURCE_CUSTOM, 100]]);
        $other = $this->bill('Ravi Kumar', [[InvoiceItem::SOURCE_CUSTOM, 300]]);

        $this->assertSame([$lab->id], array_column($this->list(['type' => 'lab_test'])->json('data'), 'id'));
        $this->assertEqualsCanonicalizing([$lab->id, $other->id], array_column($this->list(['type' => 'other'])->json('data'), 'id'));
    }

    public function test_the_list_sorts_by_what_is_owed_and_by_patient(): void
    {
        $small = $this->bill('Ravi Kumar', [[InvoiceItem::SOURCE_CUSTOM, 500]], pay: 400);   // owes 100
        $large = $this->bill('Asha Verma', [[InvoiceItem::SOURCE_CUSTOM, 900]]);             // owes 900

        $this->assertSame([$large->id, $small->id], array_column($this->list(['sort' => 'outstanding', 'direction' => 'desc'])->json('data'), 'id'));
        $this->assertSame([$large->id, $small->id], array_column($this->list(['sort' => 'patient', 'direction' => 'asc'])->json('data'), 'id'));
    }

    public function test_tab_counts_follow_the_filters_and_the_cards_describe_the_register(): void
    {
        $this->bill('Asha Verma', [[InvoiceItem::SOURCE_LAB_TEST, 400]], pay: 400);      // paid
        $this->bill('Asha Verma', [[InvoiceItem::SOURCE_CUSTOM, 600]], pay: 200);        // owes 400
        $this->bill('Ravi Kumar', [[InvoiceItem::SOURCE_CUSTOM, 300]]);                  // owes 300

        $summary = $this->withHeader('X-Branch-Id', (string) $this->branch)
            ->getJson('/api/v1/tenant/invoices/summary')->assertOk()->json('data');

        $this->assertSame(['outstanding' => 2, 'open' => 0, 'all' => 3, 'paid' => 1, 'cancelled' => 0], $summary['counts']);
        $this->assertSame(3, $summary['cards']['invoices']['value']);
        $this->assertSame(3, $summary['cards']['invoices']['this_month']);
        $this->assertSame(1300.0, (float) $summary['cards']['billed']['value']);
        $this->assertSame(700.0, (float) $summary['cards']['outstanding']['value']);
        $this->assertSame(2, $summary['cards']['outstanding']['invoices']);
        $this->assertSame(600.0, (float) $summary['cards']['collected']['value']);

        // Narrowed to Asha: the tabs count her bills; the cards do not move.
        $narrowed = $this->withHeader('X-Branch-Id', (string) $this->branch)
            ->getJson('/api/v1/tenant/invoices/summary?search=asha')->json('data');

        $this->assertSame(['outstanding' => 1, 'open' => 0, 'all' => 2, 'paid' => 1, 'cancelled' => 0], $narrowed['counts']);
        $this->assertSame(1300.0, (float) $narrowed['cards']['billed']['value']);
    }

    public function test_the_export_is_the_list_on_screen_or_only_the_ticked_rows(): void
    {
        $asha = $this->bill('Asha Verma', [[InvoiceItem::SOURCE_CUSTOM, 500]]);
        $ravi = $this->bill('Ravi Kumar', [[InvoiceItem::SOURCE_CUSTOM, 300]]);

        $all = $this->withHeader('X-Branch-Id', (string) $this->branch)
            ->get('/api/v1/tenant/invoices/export');
        $all->assertOk();
        $csv = $all->streamedContent();

        $this->assertStringContainsString('"Invoice No",Date,Patient', $csv);
        $this->assertStringContainsString($asha->invoice_number, $csv);
        $this->assertStringContainsString($ravi->invoice_number, $csv);

        $ticked = $this->withHeader('X-Branch-Id', (string) $this->branch)
            ->get('/api/v1/tenant/invoices/export?'.http_build_query(['ids' => [$ravi->id]]))
            ->streamedContent();

        $this->assertStringContainsString($ravi->invoice_number, $ticked);
        $this->assertStringNotContainsString($asha->invoice_number, $ticked);
    }
}
