<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\Customer;
use App\Models\Tenant\DocumentTemplate;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\InvoiceItem;
use App\Models\Tenant\InvoicePayment;
use App\Models\Tenant\Location;
use App\Models\Tenant\PatientDocument;
use App\Services\Billing\BillingService;
use App\Services\Documents\DocumentHtml;
use App\Services\Documents\DocumentPayload;
use App\Support\Documents\Placeholders;
use App\Support\Documents\TemplateConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TenantTestCase;

/**
 * A receipt for one clinic payment — not the bill again — and a refund slip
 * for money given back.
 *
 * What is asserted: every payment prints its own receipt under its own
 * number; a receipt says what THAT payment was and what it left owing, even
 * when reprinted after later instalments; money given back is never printed
 * as money received, nor money received as money given back; a refund prints
 * under the branch's refund number, names the receipt it undid, and never
 * tells the patient they owe a balance; a slip from a branch somebody does
 * not work at is not theirs to print; and every clinic has a template for
 * both on day one.
 */
class ClinicReceiptTest extends TenantTestCase
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
        Storage::fake('local');

        $this->artisan('modules:sync');

        $this->organization = $this->provisionOrganization('CR');
        $this->grantModule($this->organization, 'documents');

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

        $this->signInAsOwner($this->organization);
    }

    private function bill(int $branch, float $price = 500): Invoice
    {
        return $this->onTenant($this->organization, fn () => app(BillingService::class)->draw(
            [
                'location_id' => $branch,
                'customer_id' => $this->customer,
                'trigger' => 'manual',
                'kind' => Invoice::KIND_MANUAL,
            ],
            [['source_type' => InvoiceItem::SOURCE_CUSTOM, 'description' => 'Consultation', 'unit_price' => $price]],
        ));
    }

    private function pay(Invoice $invoice, float $amount): InvoicePayment
    {
        return $this->onTenant($this->organization, function () use ($invoice, $amount) {
            app(BillingService::class)->recordPayment($invoice->fresh(), ['method' => 'upi', 'amount' => $amount, 'reference' => 'UPI-'.$amount]);

            return InvoicePayment::query()->where('is_refund', false)->latest('id')->firstOrFail();
        });
    }

    private function giveBack(Invoice $invoice, InvoicePayment $payment, float $amount, string $reason = 'Overcharged'): InvoicePayment
    {
        return $this->onTenant($this->organization, function () use ($invoice, $payment, $amount, $reason) {
            app(BillingService::class)->refund($invoice->fresh(), $payment, $amount, $reason);

            return InvoicePayment::query()->where('is_refund', true)->latest('id')->firstOrFail();
        });
    }

    private function printReceipt(int $paymentId, ?int $branch = null, string $type = 'clinic_receipt')
    {
        return $this->withHeader('X-Branch-Id', (string) ($branch ?? $this->gurgaon))
            ->postJson('/api/v1/tenant/documents/generate', [
                'document_type' => $type,
                'subject_id' => $paymentId,
            ]);
    }

    private function printRefund(int $refundId)
    {
        return $this->printReceipt($refundId, null, 'clinic_refund');
    }

    /** @return array{values: array<string, string>, tables: array<string, mixed>} */
    private function payloadFor(InvoicePayment $row, string $type): array
    {
        return $this->onTenant($this->organization, fn () => app(DocumentPayload::class)->build(
            $type,
            InvoicePayment::with(['invoice', 'refunded'])->findOrFail($row->id),
            Location::find($this->gurgaon),
            $this->organization,
        ));
    }

    /** @return array<string, string> */
    private function valuesFor(InvoicePayment $payment, string $type = 'clinic_receipt'): array
    {
        return $this->payloadFor($payment, $type)['values'];
    }

    private function htmlFor(InvoicePayment $row, string $type): string
    {
        $payload = $this->payloadFor($row, $type);

        return $this->onTenant($this->organization, fn () => app(DocumentHtml::class)->render(
            $type,
            TemplateConfig::defaultsFor($type),
            $payload,
            null,
        ));
    }

    public function test_each_payment_prints_its_own_receipt_under_its_own_number(): void
    {
        $invoice = $this->bill($this->gurgaon);

        $first = $this->pay($invoice, 200);
        $second = $this->pay($invoice, 300);

        $one = $this->printReceipt($first->id)->assertCreated()->json('data');
        $two = $this->printReceipt($second->id)->assertCreated()->json('data');

        $this->assertSame('GGN/RCP/26-27/00001', $one['document_number']);
        $this->assertSame('GGN/RCP/26-27/00002', $two['document_number']);
        $this->assertSame('Clinic payment receipt GGN/RCP/26-27/00001', $one['title']);

        // Filed with the bills, under the same reading rules.
        $this->assertSame('invoice', $one['category']);
        $this->assertSame('generated', $one['source']);
    }

    public function test_a_receipt_says_what_that_payment_left_owing_even_reprinted_later(): void
    {
        $invoice = $this->bill($this->gurgaon);

        $first = $this->pay($invoice, 200);
        $second = $this->pay($invoice, 300);

        // Reprinted after the bill was settled, it still says what it said.
        $then = $this->valuesFor($first);

        $this->assertSame('GGN/RCP/26-27/00001', $then['receipt_number']);
        $this->assertSame('₹200.00', $then['amount_paid']);
        $this->assertSame('₹300.00', $then['balance']);
        $this->assertSame('PART PAID', $then['payment_state']);
        $this->assertSame('Upi', $then['payment_method']);
        $this->assertSame('UPI-200', $then['payment_reference']);
        $this->assertSame($invoice->invoice_number, $then['invoice_number']);

        $last = $this->valuesFor($second);

        $this->assertSame('₹300.00', $last['amount_paid']);
        $this->assertSame('₹0.00', $last['balance']);
        $this->assertSame('PAID', $last['payment_state']);
    }

    public function test_the_printed_receipt_leads_with_its_own_number(): void
    {
        $payment = $this->pay($this->bill($this->gurgaon), 200);

        $html = $this->htmlFor($payment, 'clinic_receipt');

        $this->assertStringContainsString('Receipt No', $html);
        $this->assertStringContainsString('GGN/RCP/26-27/00001', $html);
        $this->assertStringContainsString('₹200.00', $html);
        $this->assertStringContainsString('PART PAID', $html);
    }

    public function test_money_given_back_is_never_printed_as_money_received(): void
    {
        $invoice = $this->bill($this->gurgaon);
        $payment = $this->pay($invoice, 500);

        $refund = $this->onTenant($this->organization, function () use ($invoice, $payment) {
            app(BillingService::class)->refund($invoice->fresh(), $payment, 100, 'Overcharged');

            return InvoicePayment::query()->where('is_refund', true)->sole();
        });

        $this->printReceipt($refund->id)->assertNotFound();
    }

    public function test_money_received_is_never_printed_as_money_given_back(): void
    {
        $payment = $this->pay($this->bill($this->gurgaon), 500);

        $this->printRefund($payment->id)->assertNotFound();
    }

    public function test_a_refund_prints_under_the_branchs_refund_number(): void
    {
        $invoice = $this->bill($this->gurgaon);
        $payment = $this->pay($invoice, 500);

        $first = $this->giveBack($invoice, $payment, 100);
        $second = $this->giveBack($invoice, $payment, 50);

        $one = $this->printRefund($first->id)->assertCreated()->json('data');
        $two = $this->printRefund($second->id)->assertCreated()->json('data');

        // Its own series, not the receipts' — a refund never takes a receipt number.
        $this->assertSame('GGN/RFD/26-27/00001', $one['document_number']);
        $this->assertSame('GGN/RFD/26-27/00002', $two['document_number']);
        $this->assertSame('Clinic refund receipt GGN/RFD/26-27/00001', $one['title']);
        $this->assertSame('invoice', $one['category']);
    }

    public function test_a_refund_slip_says_what_went_back_and_against_which_receipt(): void
    {
        $invoice = $this->bill($this->gurgaon);
        $payment = $this->pay($invoice, 500);
        $refund = $this->giveBack($invoice, $payment, 100, 'Procedure not performed');

        $values = $this->valuesFor($refund, 'clinic_refund');

        $this->assertSame('GGN/RFD/26-27/00001', $values['refund_number']);
        $this->assertSame('₹100.00', $values['refund_amount']);
        $this->assertSame('Upi', $values['refund_method']);
        $this->assertSame('Procedure not performed', $values['refund_reason']);
        $this->assertSame('GGN/RCP/26-27/00001', $values['refunded_receipt_number']);
        $this->assertSame($invoice->invoice_number, $values['invoice_number']);
        $this->assertSame('REFUNDED', $values['payment_state']);

        // Nothing about money coming in, and no balance for the patient to misread as owed.
        $this->assertSame('', $values['amount_paid']);
        $this->assertSame('', $values['balance']);
        $this->assertSame('', $values['payment_method']);
    }

    public function test_the_printed_refund_slip_leads_with_its_own_number_and_owes_nothing(): void
    {
        $invoice = $this->bill($this->gurgaon);
        $payment = $this->pay($invoice, 500);
        $refund = $this->giveBack($invoice, $payment, 100);

        $html = $this->htmlFor($refund, 'clinic_refund');

        $this->assertStringContainsString('Refund No', $html);
        $this->assertStringContainsString('GGN/RFD/26-27/00001', $html);
        $this->assertStringContainsString('Refund information', $html);
        $this->assertStringContainsString('Amount Refunded', $html);
        $this->assertStringContainsString('GGN/RCP/26-27/00001', $html);
        $this->assertStringContainsString('REFUNDED', $html);

        $this->assertStringNotContainsString('Amount Paid', $html);
        $this->assertStringNotContainsString('Balance due', $html);
        $this->assertStringNotContainsString('Receipt No', $html);
    }

    public function test_a_refund_from_another_branch_is_not_theirs_to_print(): void
    {
        $invoice = $this->bill($this->gurgaon);
        $refund = $this->giveBack($invoice, $this->pay($invoice, 200), 50);

        $this->placeStaffAt($this->organization, $this->delhi);
        $this->setStaffCapabilities($this->organization, ['billing.view', 'documents.generate']);
        $this->signInAsStaff($this->organization);

        $this->printReceipt($refund->id, $this->delhi, 'clinic_refund')->assertNotFound();
    }

    public function test_a_receipt_from_another_branch_is_not_theirs_to_print(): void
    {
        $payment = $this->pay($this->bill($this->gurgaon), 200);

        $this->placeStaffAt($this->organization, $this->delhi);
        $this->setStaffCapabilities($this->organization, ['billing.view', 'documents.generate']);
        $this->signInAsStaff($this->organization);

        $this->printReceipt($payment->id, $this->delhi)->assertNotFound();
    }

    /**
     * One sheet each. On A5 the payment panel — what a receipt is FOR — went
     * over onto a second page, which nothing else here would have noticed.
     */
    public function test_a_receipt_and_a_refund_slip_each_print_on_one_sheet(): void
    {
        $invoice = $this->bill($this->gurgaon);
        $payment = $this->pay($invoice, 500);
        $refund = $this->giveBack($invoice, $payment, 100, 'Overcharged');

        foreach ([
            $this->printReceipt($payment->id)->assertCreated()->json('data.id'),
            $this->printRefund($refund->id)->assertCreated()->json('data.id'),
        ] as $documentId) {
            $pdf = $this->onTenant($this->organization, fn () => Storage::disk('local')->get(
                PatientDocument::with('file')->findOrFail($documentId)->file->file_path,
            ));

            $this->assertSame(1, preg_match_all('/\/Type\s*\/Page[^s]/', $pdf), "Document #{$documentId} runs onto a second sheet.");
        }
    }

    public function test_every_clinic_can_print_a_receipt_and_a_refund_slip_from_day_one(): void
    {
        foreach (['clinic_receipt', 'clinic_refund'] as $type) {
            $template = $this->onTenant($this->organization, fn () => DocumentTemplate::query()
                ->whereNull('location_id')
                ->where('document_type', $type)
                ->first());

            $this->assertNotNull($template, "No {$type} template was seeded.");
            $this->assertSame(DocumentTemplate::ACTIVE, $template->status);
            $this->assertNotNull($template->active_version_id, "The seeded {$type} template is not published.");

            // And it asks for nothing the slip cannot fill.
            $this->assertSame([], Placeholders::unknownIn(TemplateConfig::defaultsFor($type), $type));
        }
    }
}
