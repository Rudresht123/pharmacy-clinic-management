<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\InvoiceItem;
use App\Models\Tenant\InvoicePayment;
use App\Models\Tenant\Location;
use App\Services\Billing\BillingService;
use App\Services\Clinic\ClinicEvent;
use App\Services\Documents\DocumentAutomation;
use App\Services\Tenancy\TenantConnectionService;
use App\Support\Clinic\ClinicEvents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\RecordingDocumentAutomation;
use Tests\TenantTestCase;

/**
 * Step 2 — invoice, receipt and refund numbers, per branch and per year.
 *
 * What is asserted is what a register has to promise: each branch counts from
 * one on its own; receipts and refunds are their own series; every series
 * starts again on 1 April; a payment that rolls back hands its number back;
 * the number is on disk before anything is told the payment happened; and no
 * two series can ever mint the same number, whatever anybody types as a
 * prefix.
 */
class BranchNumberingTest extends TenantTestCase
{
    use RefreshDatabase;

    /** Inside FY 2026-27. */
    private const A_DAY = '2026-09-07 11:00:00';

    private Organization $organization;

    private int $gurgaon;

    private int $delhi;

    private int $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(self::A_DAY);

        $this->organization = $this->provisionOrganization('NB');

        $this->onTenant($this->organization, function () {
            $this->gurgaon = Location::create([
                'name' => 'Gurgaon', 'code' => 'GGN', 'type' => Location::CLINIC, 'is_active' => true,
            ])->id;

            $this->delhi = Location::create([
                'name' => 'Delhi', 'code' => 'DEL', 'type' => Location::CLINIC, 'is_active' => true,
            ])->id;

            $this->customer = Customer::create([
                'name' => 'Asha Verma',
                'mobile' => '9876543210',
                'location_id' => $this->gurgaon,
            ])->id;
        });
    }

    private function bill(int $branch, float $price = 500): Invoice
    {
        return app(BillingService::class)->draw(
            [
                'location_id' => $branch,
                'customer_id' => $this->customer,
                'trigger' => 'manual',
                'kind' => Invoice::KIND_MANUAL,
            ],
            [['source_type' => InvoiceItem::SOURCE_CUSTOM, 'description' => 'Consultation', 'unit_price' => $price]],
        );
    }

    private function pay(Invoice $invoice, float $amount): InvoicePayment
    {
        app(BillingService::class)->recordPayment($invoice->fresh(), ['method' => 'cash', 'amount' => $amount]);

        return InvoicePayment::query()->where('is_refund', false)->latest('id')->firstOrFail();
    }

    public function test_each_branch_numbers_its_own_invoices_from_one(): void
    {
        $this->onTenant($this->organization, function () {
            $this->assertSame('GGN/INV/26-27/00001', $this->bill($this->gurgaon)->invoice_number);
            $this->assertSame('GGN/INV/26-27/00002', $this->bill($this->gurgaon)->invoice_number);

            // Delhi's register has no holes where Gurgaon's bills sat.
            $this->assertSame('DEL/INV/26-27/00001', $this->bill($this->delhi)->invoice_number);
        });
    }

    public function test_payments_and_refunds_are_numbered_in_series_of_their_own(): void
    {
        $this->onTenant($this->organization, function () {
            $invoice = $this->bill($this->gurgaon);

            $first = $this->pay($invoice, 200);
            $second = $this->pay($invoice, 300);

            $this->assertSame('GGN/RCP/26-27/00001', $first->receipt_number);
            $this->assertSame('GGN/RCP/26-27/00002', $second->receipt_number);

            app(BillingService::class)->refund($invoice->fresh(), $first, 50, 'Overcharged');

            $refund = InvoicePayment::query()->where('is_refund', true)->sole();

            $this->assertSame('GGN/RFD/26-27/00001', $refund->receipt_number);
        });
    }

    public function test_every_series_starts_again_on_1_april(): void
    {
        $this->onTenant($this->organization, function () {
            $this->travelTo('2027-03-31 23:50:00');
            $this->assertSame('GGN/INV/26-27/00001', $this->bill($this->gurgaon)->invoice_number);

            // Ten minutes later, in India, it is a new financial year.
            $this->travelTo('2027-04-01 00:05:00');
            $this->assertSame('GGN/INV/27-28/00001', $this->bill($this->gurgaon)->invoice_number);
            $this->assertSame('GGN/INV/27-28/00002', $this->bill($this->gurgaon)->invoice_number);
        });
    }

    public function test_a_payment_that_rolls_back_hands_its_number_back(): void
    {
        $this->onTenant($this->organization, function () {
            $invoice = $this->bill($this->gurgaon);

            try {
                DB::connection(TenantConnectionService::CONNECTION)->transaction(function () use ($invoice) {
                    $this->pay($invoice, 200);

                    throw new RuntimeException('the work it paid for failed');
                });
            } catch (RuntimeException) {
                // expected
            }

            // No gap: the next receipt is the first, not the second.
            $this->assertSame('GGN/RCP/26-27/00001', $this->pay($invoice, 200)->receipt_number);
        });
    }

    public function test_the_receipt_number_is_on_disk_before_automation_hears_of_the_payment(): void
    {
        $this->onTenant($this->organization, function () {
            $seen = new class extends RecordingDocumentAutomation
            {
                /** @var list<string|null> */
                public array $receipts = [];

                public function handle(ClinicEvent $event): void
                {
                    parent::handle($event);

                    if ($event->key === ClinicEvents::PAYMENT_RECEIVED) {
                        $this->receipts[] = InvoicePayment::query()
                            ->whereKey($event->meta['payment_id'])
                            ->value('receipt_number');
                    }
                }
            };

            $this->app->instance(DocumentAutomation::class, $seen);

            $payment = $this->pay($this->bill($this->gurgaon), 500);

            $this->assertSame([$payment->receipt_number], $seen->receipts);
            $this->assertSame('GGN/RCP/26-27/00001', $payment->receipt_number);
        });
    }

    public function test_the_preview_is_the_number_actually_issued(): void
    {
        $this->signInAsOwner($this->organization);

        $this->onTenant($this->organization, fn () => $this->bill($this->gurgaon));

        $gurgaon = collect($this->getJson('/api/v1/tenant/billing/numbering')->assertOk()->json('data.branches'))
            ->firstWhere('location_id', $this->gurgaon);

        $this->assertSame('GGN/INV/26-27/00002', $gurgaon['series']['invoice']['next']);
        $this->assertSame('GGN/RCP/26-27/00001', $gurgaon['series']['receipt']['next']);

        $issued = $this->onTenant($this->organization, fn () => $this->bill($this->gurgaon)->invoice_number);

        $this->assertSame($gurgaon['series']['invoice']['next'], $issued);
    }

    public function test_a_new_prefix_applies_from_the_next_bill_and_the_count_carries_on(): void
    {
        $this->signInAsOwner($this->organization);

        $first = $this->onTenant($this->organization, fn () => $this->bill($this->gurgaon));

        $this->putJson('/api/v1/tenant/billing/numbering', ['series' => [
            ['location_id' => $this->gurgaon, 'document_type' => 'invoice', 'prefix' => 'GUR/OPD'],
        ]])->assertOk();

        $this->onTenant($this->organization, function () use ($first) {
            $this->assertSame('GUR/OPD/26-27/00002', $this->bill($this->gurgaon)->invoice_number);

            // Nothing already issued is renumbered.
            $this->assertSame('GGN/INV/26-27/00001', $first->fresh()->invoice_number);
        });
    }

    public function test_two_series_cannot_share_a_prefix(): void
    {
        $this->signInAsOwner($this->organization);

        // Gurgaon has not billed yet, but GGN/INV is still its prefix-to-be.
        $this->putJson('/api/v1/tenant/billing/numbering', ['series' => [
            ['location_id' => $this->delhi, 'document_type' => 'invoice', 'prefix' => 'GGN/INV'],
        ]])->assertStatus(422)->assertJsonValidationErrors('series.0.prefix');

        // Nor may a branch's receipts borrow its own invoices' prefix.
        $this->putJson('/api/v1/tenant/billing/numbering', ['series' => [
            ['location_id' => $this->delhi, 'document_type' => 'receipt', 'prefix' => 'DEL/INV'],
        ]])->assertStatus(422)->assertJsonValidationErrors('series.0.prefix');
    }

    public function test_a_prefix_given_up_is_not_free_for_another_branch(): void
    {
        $this->signInAsOwner($this->organization);

        $this->onTenant($this->organization, fn () => $this->bill($this->gurgaon));

        $this->putJson('/api/v1/tenant/billing/numbering', ['series' => [
            ['location_id' => $this->gurgaon, 'document_type' => 'invoice', 'prefix' => 'GUR/INV'],
        ]])->assertOk();

        // GGN/INV/26-27/00001 is on paper; Delhi would mint it again.
        $this->putJson('/api/v1/tenant/billing/numbering', ['series' => [
            ['location_id' => $this->delhi, 'document_type' => 'invoice', 'prefix' => 'GGN/INV'],
        ]])->assertStatus(422)->assertJsonValidationErrors('series.0.prefix');
    }

    public function test_a_prefix_is_kept_to_what_prints_cleanly(): void
    {
        $this->signInAsOwner($this->organization);

        foreach (['ggn/inv', 'GGN/', '/GGN', 'GGN INV', 'GGN%'] as $bad) {
            $this->putJson('/api/v1/tenant/billing/numbering', ['series' => [
                ['location_id' => $this->gurgaon, 'document_type' => 'invoice', 'prefix' => $bad],
            ]])->assertStatus(422)->assertJsonValidationErrors('series.0.prefix');
        }
    }

    public function test_only_billing_settings_may_change_a_prefix(): void
    {
        $this->signInAsStaff($this->organization);

        $this->putJson('/api/v1/tenant/billing/numbering', ['series' => [
            ['location_id' => $this->gurgaon, 'document_type' => 'invoice', 'prefix' => 'GUR/INV'],
        ]])->assertForbidden();
    }
}
