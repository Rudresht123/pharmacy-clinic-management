<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\Customer;
use App\Models\Tenant\DocumentRule;
use App\Models\Tenant\DocumentTemplate;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\InvoiceItem;
use App\Models\Tenant\InvoicePayment;
use App\Models\Tenant\Location;
use App\Models\Tenant\PatientDocument;
use App\Services\Billing\BillingService;
use App\Services\Clinic\ClinicEvent;
use App\Services\Clinic\ClinicEventDispatcher;
use App\Support\Clinic\ClinicEvents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TenantTestCase;

/**
 * Step 3 — the rules a clinic sets, and the documents they make.
 *
 * What is asserted: a clinic with no rules files nothing; a rule files the
 * document on the event it names, at the branches it names; one record gets
 * one automatic copy however often its event is raised or however many rules
 * ask for it — and a copy somebody removed is not quietly printed again; a
 * bill, which changes as it is paid, gets a copy per payment; a document that
 * cannot be made leaves the payment standing; a branch with the module off
 * gets nothing; pressing Print on a receipt already made automatically opens
 * that one rather than filing a second; and the settings screen offers, saves
 * and refuses exactly what the automation can do, for the people whose call
 * it is.
 */
class DocumentRulesTest extends TenantTestCase
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

        $this->organization = $this->provisionOrganization('DR');
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

    private function rule(string $event, string $document, ?int $branch = null): void
    {
        $this->onTenant($this->organization, fn () => DocumentRule::create([
            'event_key' => $event,
            'document_type' => $document,
            'location_id' => $branch,
        ]));
    }

    private function bill(?int $branch = null, float $price = 500): Invoice
    {
        return $this->onTenant($this->organization, fn () => app(BillingService::class)->draw(
            [
                'location_id' => $branch ?? $this->gurgaon,
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
            app(BillingService::class)->recordPayment($invoice->fresh(), ['method' => 'cash', 'amount' => $amount]);

            return InvoicePayment::query()->where('is_refund', false)->latest('id')->firstOrFail();
        });
    }

    /** @return list<array{title: string|null, number: string|null}> */
    private function filed(bool $withRemoved = false): array
    {
        return $this->onTenant($this->organization, fn () => PatientDocument::query()
            ->when($withRemoved, fn ($query) => $query->withTrashed())
            ->orderBy('id')
            ->get()
            ->map(fn (PatientDocument $document) => [
                'title' => $document->title,
                'number' => $document->document_number,
            ])
            ->all());
    }

    private function api(string $method, string $uri, array $data = [])
    {
        return $this->withHeader('X-Branch-Id', (string) $this->gurgaon)
            ->json($method, '/api/v1/tenant/'.$uri, $data);
    }

    /* ── The automation ─────────────────────────────────────────────── */

    public function test_a_clinic_with_no_rules_files_nothing(): void
    {
        $this->pay($this->bill(), 500);

        $this->assertSame([], $this->filed());
    }

    public function test_a_rule_for_every_branch_files_the_receipt_when_money_is_taken(): void
    {
        $this->rule(ClinicEvents::PAYMENT_RECEIVED, 'clinic_receipt');

        $payment = $this->pay($this->bill(), 200);

        $this->assertSame([
            ['title' => 'Clinic payment receipt GGN/RCP/26-27/00001', 'number' => 'GGN/RCP/26-27/00001'],
        ], $this->filed());

        $document = $this->onTenant($this->organization, fn () => PatientDocument::query()->sole());

        // Filed where a printed one would be, and marked as the automation's.
        $this->assertSame($this->customer, $document->customer_id);
        $this->assertSame($this->gurgaon, $document->location_id);
        $this->assertSame(PatientDocument::GENERATED, $document->source);
        $this->assertSame('clinic_receipt|'.(new InvoicePayment)->getMorphClass().'#'.$payment->id, $document->idempotency_key);
    }

    public function test_the_same_event_raised_again_files_nothing_new(): void
    {
        $this->rule(ClinicEvents::PAYMENT_RECEIVED, 'clinic_receipt');

        $invoice = $this->bill();
        $payment = $this->pay($invoice, 500);

        // A replayed job, a retried callback.
        $this->onTenant($this->organization, fn () => app(ClinicEventDispatcher::class)->dispatch(
            ClinicEvent::for(ClinicEvents::PAYMENT_RECEIVED, $invoice->fresh(), ['payment_id' => $payment->id]),
        ));

        $this->assertCount(1, $this->filed());
    }

    public function test_two_rules_firing_on_one_payment_file_one_receipt(): void
    {
        // The payment that settles a bill raises both.
        $this->rule(ClinicEvents::PAYMENT_RECEIVED, 'clinic_receipt');
        $this->rule(ClinicEvents::PAYMENT_FULLY_RECEIVED, 'clinic_receipt');
        // And the same rule again for one branch is the same wish, not a second one.
        $this->rule(ClinicEvents::PAYMENT_RECEIVED, 'clinic_receipt', $this->gurgaon);

        $this->pay($this->bill(), 500);

        $this->assertCount(1, $this->filed());
    }

    public function test_a_bill_copy_is_filed_for_each_payment_because_the_bill_changes(): void
    {
        $this->rule(ClinicEvents::PAYMENT_RECEIVED, 'clinic_invoice');

        $invoice = $this->bill();
        $this->pay($invoice, 200);
        $this->pay($invoice, 300);

        $this->assertSame([$invoice->invoice_number, $invoice->invoice_number], array_column($this->filed(), 'number'));
    }

    public function test_a_branch_rule_applies_at_that_branch_only(): void
    {
        $this->rule(ClinicEvents::PAYMENT_RECEIVED, 'clinic_receipt', $this->delhi);

        $this->pay($this->bill($this->gurgaon), 500);
        $this->assertSame([], $this->filed());

        $this->pay($this->bill($this->delhi), 500);
        $this->assertSame(['DEL/RCP/26-27/00001'], array_column($this->filed(), 'number'));
    }

    public function test_a_refund_rule_files_the_refund_slip(): void
    {
        $this->rule(ClinicEvents::REFUND_PROCESSED, 'clinic_refund');

        $invoice = $this->bill();
        $payment = $this->pay($invoice, 500);

        $this->onTenant($this->organization, fn () => app(BillingService::class)
            ->refund($invoice->fresh(), $payment, 100, 'Overcharged'));

        $this->assertSame(['GGN/RFD/26-27/00001'], array_column($this->filed(), 'number'));
    }

    public function test_a_removed_copy_is_not_quietly_printed_again(): void
    {
        $this->rule(ClinicEvents::PAYMENT_RECEIVED, 'clinic_receipt');

        $invoice = $this->bill();
        $payment = $this->pay($invoice, 500);

        $this->onTenant($this->organization, fn () => PatientDocument::query()->sole()->delete());

        $this->onTenant($this->organization, fn () => app(ClinicEventDispatcher::class)->dispatch(
            ClinicEvent::for(ClinicEvents::PAYMENT_RECEIVED, $invoice->fresh(), ['payment_id' => $payment->id]),
        ));

        $this->assertSame([], $this->filed());
        $this->assertCount(1, $this->filed(withRemoved: true));
    }

    public function test_a_document_that_cannot_be_made_leaves_the_payment_standing(): void
    {
        $this->rule(ClinicEvents::PAYMENT_RECEIVED, 'clinic_receipt');

        // Nothing to print from.
        $this->onTenant($this->organization, fn () => DocumentTemplate::query()
            ->where('document_type', 'clinic_receipt')
            ->get()
            ->each->delete());

        $invoice = $this->bill();
        $this->pay($invoice, 500);

        $this->assertSame([], $this->filed());
        $this->assertSame(Invoice::PAID, $this->onTenant($this->organization, fn () => $invoice->fresh()->payment_status));
    }

    public function test_a_branch_with_documents_switched_off_gets_nothing(): void
    {
        $this->rule(ClinicEvents::PAYMENT_RECEIVED, 'clinic_receipt');
        $this->disableModuleAtBranch($this->organization, $this->gurgaon, 'documents');

        $this->pay($this->bill($this->gurgaon), 500);
        $this->assertSame([], $this->filed());

        $this->pay($this->bill($this->delhi), 500);
        $this->assertCount(1, $this->filed());
    }

    /* ── Printing by hand beside it ─────────────────────────────────── */

    private function printByHand(string $type, int $subjectId)
    {
        return $this->api('POST', 'documents/generate', ['document_type' => $type, 'subject_id' => $subjectId]);
    }

    public function test_printing_a_receipt_already_made_automatically_opens_that_copy(): void
    {
        $this->rule(ClinicEvents::PAYMENT_RECEIVED, 'clinic_receipt');

        $payment = $this->pay($this->bill(), 500);
        $automatic = $this->onTenant($this->organization, fn () => PatientDocument::query()->sole());

        $printed = $this->printByHand('clinic_receipt', $payment->id)->assertOk()->json('data');

        $this->assertSame($automatic->id, $printed['id']);
        $this->assertCount(1, $this->filed());
    }

    public function test_a_bill_is_printed_as_it_stands_even_beside_an_automatic_copy(): void
    {
        $this->rule(ClinicEvents::INVOICE_CREATED, 'clinic_invoice');

        $invoice = $this->bill();
        $this->pay($invoice, 500);

        // The copy made when it was drawn said UNPAID; this one is the bill now.
        $this->printByHand('clinic_invoice', $invoice->id)->assertCreated();

        $this->assertCount(2, $this->filed());
    }

    public function test_a_removed_automatic_copy_is_printed_afresh(): void
    {
        $this->rule(ClinicEvents::PAYMENT_RECEIVED, 'clinic_receipt');

        $payment = $this->pay($this->bill(), 500);
        $this->onTenant($this->organization, fn () => PatientDocument::query()->sole()->delete());

        $this->printByHand('clinic_receipt', $payment->id)->assertCreated();

        $this->assertCount(1, $this->filed());
        $this->assertCount(2, $this->filed(withRemoved: true));
    }

    public function test_without_a_rule_every_print_is_its_own_copy(): void
    {
        $payment = $this->pay($this->bill(), 500);

        $this->printByHand('clinic_receipt', $payment->id)->assertCreated();
        $this->printByHand('clinic_receipt', $payment->id)->assertCreated();

        $this->assertCount(2, $this->filed());
    }

    /* ── The settings screen ────────────────────────────────────────── */

    public function test_the_screen_offers_only_what_each_event_can_make(): void
    {
        $events = collect($this->api('GET', 'document-rules')->assertOk()->json('data.events'))->keyBy('key');

        $this->assertSame(
            ['clinic_receipt', 'clinic_invoice'],
            array_column($events[ClinicEvents::PAYMENT_RECEIVED]['documents'], 'key'),
        );
        $this->assertSame(['clinic_refund'], array_column($events[ClinicEvents::REFUND_PROCESSED]['documents'], 'key'));

        // No pharmacy here, so nothing about sales is offered.
        $this->assertFalse($events->has(ClinicEvents::PHARMACY_SALE_COMPLETED));
    }

    public function test_saving_replaces_the_set_for_that_scope_and_no_other(): void
    {
        $this->api('PUT', 'document-rules', [
            'location_id' => null,
            'rules' => [
                ['event_key' => ClinicEvents::PAYMENT_RECEIVED, 'document_type' => 'clinic_receipt'],
                ['event_key' => ClinicEvents::INVOICE_CREATED, 'document_type' => 'clinic_invoice'],
            ],
        ])->assertOk();

        $this->api('PUT', 'document-rules', [
            'location_id' => $this->delhi,
            'rules' => [['event_key' => ClinicEvents::REFUND_PROCESSED, 'document_type' => 'clinic_refund']],
        ])->assertOk();

        // Untick one organisation-wide rule; Delhi's own is not touched.
        $rules = $this->api('PUT', 'document-rules', [
            'location_id' => null,
            'rules' => [['event_key' => ClinicEvents::PAYMENT_RECEIVED, 'document_type' => 'clinic_receipt']],
        ])->assertOk()->json('data.rules');

        $this->assertEqualsCanonicalizing([
            [ClinicEvents::PAYMENT_RECEIVED, 'clinic_receipt', null],
            [ClinicEvents::REFUND_PROCESSED, 'clinic_refund', $this->delhi],
        ], array_map(fn (array $rule) => [$rule['event_key'], $rule['document_type'], $rule['location_id']], $rules));
    }

    public function test_a_document_the_event_cannot_make_is_refused(): void
    {
        $this->api('PUT', 'document-rules', [
            'location_id' => null,
            'rules' => [['event_key' => ClinicEvents::PAYMENT_RECEIVED, 'document_type' => 'prescription']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['rules.0.document_type']);

        $this->api('PUT', 'document-rules', [
            'location_id' => null,
            'rules' => [['event_key' => 'lab_report_finalized', 'document_type' => 'clinic_receipt']],
        ])->assertUnprocessable();

        $this->assertSame(0, $this->onTenant($this->organization, fn () => DocumentRule::query()->count()));
    }

    public function test_the_rules_are_the_organisations_call(): void
    {
        $this->setStaffCapabilities($this->organization, ['documents.template_view', 'documents.template_edit']);
        $this->signInAsStaff($this->organization);

        $this->api('GET', 'document-rules')->assertForbidden();
        $this->api('PUT', 'document-rules', ['location_id' => null, 'rules' => []])->assertForbidden();
    }
}
