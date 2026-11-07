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
 * The billing dashboard's two tables, paged on their own.
 *
 * What is asserted: the invoice table shows the chosen window's bills and no
 * others, newest first, a page at a time, with the lines its Type column
 * reads; the outstanding table pages through everybody who owes, whatever
 * the window; and a range that ends before it starts is refused rather than
 * crashing.
 */
class BillingOverviewTest extends TenantTestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private int $branch;

    /** @var list<int> */
    private array $patients = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->provisionOrganization('BO');

        $this->onTenant($this->organization, function () {
            $this->branch = Location::create([
                'name' => 'Gurgaon', 'code' => 'GGN', 'type' => Location::CLINIC, 'is_active' => true,
            ])->id;

            foreach (['Asha Verma', 'Ravi Kumar', 'Meena Iyer'] as $name) {
                $this->patients[] = Customer::create(['name' => $name, 'location_id' => $this->branch])->id;
            }
        });

        $this->signInAsOwner($this->organization);
    }

    private function billOn(string $day, int $patient, float $price = 500): Invoice
    {
        $this->travelTo($day.' 11:00:00');

        return $this->onTenant($this->organization, fn () => app(BillingService::class)->draw(
            [
                'location_id' => $this->branch,
                'customer_id' => $patient,
                'trigger' => 'manual',
                'kind' => Invoice::KIND_MANUAL,
            ],
            [['source_type' => InvoiceItem::SOURCE_CUSTOM, 'description' => 'Consultation', 'unit_price' => $price]],
        ));
    }

    private function read(string $uri, array $query = [])
    {
        return $this->withHeader('X-Branch-Id', (string) $this->branch)
            ->getJson('/api/v1/tenant/'.$uri.'?'.http_build_query($query));
    }

    public function test_the_invoice_table_pages_through_the_windows_bills_newest_first(): void
    {
        $outside = $this->billOn('2026-08-20', $this->patients[0]);

        $inside = [];
        foreach (['2026-09-01', '2026-09-03', '2026-09-03', '2026-09-05', '2026-09-06', '2026-09-07'] as $day) {
            $inside[] = $this->billOn($day, $this->patients[1]);
        }

        $window = ['from' => '2026-09-01', 'to' => '2026-09-07', 'per_page' => 4];

        $first = $this->read('billing/overview/invoices', $window)->assertOk();
        $second = $this->read('billing/overview/invoices', $window + ['page' => 2])->assertOk();

        $this->assertSame(6, $first->json('meta.total'));
        $this->assertSame(2, $first->json('meta.last_page'));

        $ids = [...array_column($first->json('data'), 'id'), ...array_column($second->json('data'), 'id')];

        // Newest first; the two bills of the 3rd by id, so the page break is stable.
        $this->assertSame(array_reverse(array_map(fn (Invoice $invoice) => $invoice->id, $inside)), $ids);
        $this->assertNotContains($outside->id, $ids);

        // The Type column reads the lines.
        $this->assertSame('custom', $first->json('data.0.items.0.source_type'));
    }

    public function test_the_outstanding_table_pages_through_everybody_who_owes(): void
    {
        $this->billOn('2026-06-01', $this->patients[0]);
        $this->billOn('2026-09-02', $this->patients[1]);
        $this->billOn('2026-09-03', $this->patients[2]);
        // A second unpaid bill is still one person to call.
        $this->billOn('2026-09-04', $this->patients[2]);

        $first = $this->read('billing/overview/outstanding', ['per_page' => 2])->assertOk();

        $this->assertSame(3, $first->json('meta.total'));
        $this->assertSame(['Meena Iyer', 'Ravi Kumar'], array_column($first->json('data'), 'patient'));
        $this->assertSame(1000.0, (float) $first->json('data.0.due'));

        // June's debt is still here, whatever window the page above is showing.
        $this->assertSame(['Asha Verma'], array_column(
            $this->read('billing/overview/outstanding', ['per_page' => 2, 'page' => 2])->assertOk()->json('data'),
            'patient',
        ));
    }

    public function test_the_overview_itself_still_answers_for_a_custom_window(): void
    {
        $this->billOn('2026-09-02', $this->patients[0], 300);
        $this->billOn('2026-09-04', $this->patients[1], 700);

        $overview = $this->read('billing/overview', ['from' => '2026-09-01', 'to' => '2026-09-05'])->assertOk();

        $this->assertSame('2026-09-01', $overview->json('data.from'));
        $this->assertSame(2, $overview->json('data.totals.invoiced_count'));
        $this->assertCount(2, $overview->json('data.outstanding_patients_list'));
    }

    public function test_the_cards_compare_with_the_window_the_compare_menu_picks(): void
    {
        // Oldest first: the reads below run at the last bill's time.
        $this->billOn('2025-09-05', $this->patients[2], 250);   // a year back
        $this->billOn('2026-08-03', $this->patients[0], 400);   // last month, same dates
        $this->billOn('2026-08-30', $this->patients[1], 900);   // the seven days just before

        $window = ['from' => '2026-09-01', 'to' => '2026-09-07'];
        $previous = fn (array $query) => $this->read('billing/overview', $query)->assertOk()->json('data.previous');

        $period = $previous($window);
        $this->assertSame(['2026-08-25', '2026-08-31', 900.0], [$period['from'], $period['to'], (float) $period['invoiced']]);

        $month = $previous($window + ['compare' => 'previous_month']);
        $this->assertSame(['2026-08-01', '2026-08-07', 400.0], [$month['from'], $month['to'], (float) $month['invoiced']]);

        $year = $previous($window + ['compare' => 'previous_year']);
        $this->assertSame(['2025-09-01', '2025-09-07', 250.0], [$year['from'], $year['to'], (float) $year['invoiced']]);

        // A whole calendar month reads against the whole of the other one — all 31 days of August.
        $whole = $previous(['from' => '2026-09-01', 'to' => '2026-09-30', 'compare' => 'previous_month']);
        $this->assertSame(['2026-08-01', '2026-08-31', 1300.0], [$whole['from'], $whole['to'], (float) $whole['invoiced']]);

        $this->read('billing/overview', $window + ['compare' => 'last_decade'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['compare']);
    }

    public function test_a_range_that_ends_before_it_starts_is_refused(): void
    {
        $this->read('billing/overview/invoices', ['from' => '2026-09-10', 'to' => '2026-09-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to']);

        $this->read('billing/overview', ['from' => 'not-a-date'])->assertUnprocessable();
    }
}
