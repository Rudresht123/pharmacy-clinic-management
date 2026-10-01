<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\InvoiceItem;
use App\Models\Tenant\InvoicePayment;
use App\Models\Tenant\Location;
use App\Services\Billing\BillingConflict;
use App\Services\Billing\BillingService;
use App\Services\Clinic\ClinicEvent;
use App\Services\Clinic\ClinicEventDispatcher;
use App\Services\Documents\DocumentAutomation;
use App\Services\Documents\DocumentRule;
use App\Services\Documents\DocumentRules;
use App\Services\Tenancy\TenantConnectionService;
use App\Support\Clinic\ClinicEvents;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\RecordingDocumentAutomation;
use Tests\TenantTestCase;

/**
 * Step 1 — the wiring between a committed transaction and the paperwork.
 *
 * What is asserted is the set of promises the rest of the feature will be
 * built on: an event reaches automation only after the money is on disk;
 * a rolled-back transaction reaches it never; automation that blows up
 * leaves the payment standing; a payment says both that it happened and what
 * it did to the bill; a replayed state change does not raise a second event;
 * and an event can never execute against a tenant database other than the one
 * it was raised in.
 */
class ClinicEventTest extends TenantTestCase
{
    use RefreshDatabase;

    private const PATIENT_NAME = 'Asha Verma';

    private const PATIENT_MOBILE = '9876543210';

    private Organization $organization;

    private int $branch;

    private int $customer;

    private RecordingDocumentAutomation $recorder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->provisionOrganization('EV');

        $this->onTenant($this->organization, function () {
            $this->branch = Location::query()->value('id')
                ?? Location::create([
                    'name' => 'Main',
                    'code' => 'MAIN',
                    'type' => 'CLINIC',
                    'is_active' => true,
                ])->id;

            $this->customer = Customer::create([
                'name' => self::PATIENT_NAME,
                'mobile' => self::PATIENT_MOBILE,
                'location_id' => $this->branch,
            ])->id;
        });
    }

    /**
     * Swap the automation for one that remembers what it was handed.
     *
     * Bound before anything resolves BillingService, so the dispatcher that
     * service receives is built around this recorder.
     */
    private function record(): RecordingDocumentAutomation
    {
        $this->recorder = new RecordingDocumentAutomation;
        $this->app->instance(DocumentAutomation::class, $this->recorder);

        return $this->recorder;
    }

    /**
     * Give the tenant connection the transaction manager production runs.
     *
     * RefreshDatabase installs a testing manager that treats transaction
     * level 1 as its own wrapper and fires after-commit callbacks when
     * anything commits back down to it. That is right for the connection it
     * wraps and wrong for the tenant connection, which it does not: there,
     * level 1 is a real caller's transaction, and a payment nested inside it
     * would be "committed" at the savepoint. A test about nesting needs the
     * real rule — callbacks wait for the outermost COMMIT.
     *
     * Call inside onTenant(): connecting builds a fresh connection, which
     * would pick the testing manager back up.
     */
    private function transactionsAsInProduction(): void
    {
        DB::connection(TenantConnectionService::CONNECTION)
            ->setTransactionManager(new DatabaseTransactionsManager);
    }

    /** @param list<array<string, mixed>> $lines */
    private function bill(array $lines = [['description' => 'Consultation', 'unit_price' => 500]]): Invoice
    {
        return app(BillingService::class)->draw(
            [
                'location_id' => $this->branch,
                'customer_id' => $this->customer,
                'trigger' => 'manual',
                'kind' => Invoice::KIND_MANUAL,
            ],
            array_map(fn (array $line) => $line + ['source_type' => InvoiceItem::SOURCE_CUSTOM], $lines),
        );
    }

    /** @return array{method: string, amount: int} */
    private function cash(int $amount): array
    {
        return ['method' => 'cash', 'amount' => $amount];
    }

    public function test_an_event_reaches_automation_only_after_the_transaction_commits(): void
    {
        $this->onTenant($this->organization, function () {
            $recorder = $this->record();

            DB::connection(TenantConnectionService::CONNECTION)->transaction(function () use ($recorder) {
                app(ClinicEventDispatcher::class)->dispatch(
                    ClinicEvent::for(ClinicEvents::PAYMENT_RECEIVED, Customer::find($this->customer)),
                );

                // Still inside. The money is not on disk, so nothing may have
                // been told about it.
                $this->assertSame([], $recorder->seen);
            });

            $this->assertSame([ClinicEvents::PAYMENT_RECEIVED], $recorder->seen);
        });
    }

    public function test_a_payment_reaches_automation_once_it_is_on_disk_carrying_only_references(): void
    {
        $this->onTenant($this->organization, function () {
            $recorder = $this->record();

            $invoice = $this->bill();
            $recorder->forget();

            app(BillingService::class)->recordPayment($invoice, $this->cash(500));

            $payment = InvoicePayment::query()->sole();

            $this->assertSame([
                ClinicEvents::PAYMENT_RECEIVED,
                ClinicEvents::PAYMENT_FULLY_RECEIVED,
            ], $recorder->seen);

            $this->assertSame(
                [0, 0],
                $recorder->levels,
                'Automation ran while the payment could still have rolled back.',
            );

            foreach ($recorder->events as $event) {
                $this->assertSame($this->organization->database_name, $event->database);
                $this->assertSame($invoice->id, $event->subjectId);
                $this->assertSame($this->customer, $event->customerId);
                $this->assertSame($this->branch, $event->locationId);
                $this->assertSame($payment->id, $event->meta['payment_id']);
            }
        });
    }

    public function test_a_payment_inside_a_larger_transaction_waits_for_the_outermost_commit(): void
    {
        $this->onTenant($this->organization, function () {
            $this->transactionsAsInProduction();
            $recorder = $this->record();

            $invoice = $this->bill();
            $recorder->forget();

            // The shape a dispensing takes: the sale's transaction wraps the
            // billing service's, so recordPayment() commits to a savepoint.
            DB::connection(TenantConnectionService::CONNECTION)->transaction(function () use ($invoice, $recorder) {
                app(BillingService::class)->recordPayment($invoice, $this->cash(500));

                $this->assertSame([], $recorder->seen, 'A savepoint is not a commit.');
            });

            $this->assertSame([
                ClinicEvents::PAYMENT_RECEIVED,
                ClinicEvents::PAYMENT_FULLY_RECEIVED,
            ], $recorder->seen);
            $this->assertSame([0, 0], $recorder->levels);
        });
    }

    public function test_a_rolled_back_transaction_raises_nothing(): void
    {
        $this->onTenant($this->organization, function () {
            $recorder = $this->record();

            try {
                DB::connection(TenantConnectionService::CONNECTION)->transaction(function () {
                    app(ClinicEventDispatcher::class)->dispatch(
                        ClinicEvent::for(ClinicEvents::PAYMENT_RECEIVED, Customer::find($this->customer)),
                    );

                    throw new RuntimeException('the card machine died');
                });
            } catch (RuntimeException) {
                // expected
            }

            $this->assertSame([], $recorder->seen, 'A payment that rolled back must not be receipted.');
        });
    }

    public function test_a_payment_rolled_back_by_its_caller_leaves_no_payment_and_raises_nothing(): void
    {
        $this->onTenant($this->organization, function () {
            $this->transactionsAsInProduction();
            $recorder = $this->record();

            $invoice = $this->bill();
            $recorder->forget();

            try {
                DB::connection(TenantConnectionService::CONNECTION)->transaction(function () use ($invoice) {
                    // The payment is written and both events are registered...
                    app(BillingService::class)->recordPayment($invoice, $this->cash(500));

                    // ...then the work it was part of fails.
                    throw new RuntimeException('the stock movement it paid for was refused');
                });
            } catch (RuntimeException) {
                // expected
            }

            $this->assertDatabaseCount('invoice_payments', 0, TenantConnectionService::CONNECTION);
            $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
            $this->assertSame([], $recorder->seen, 'A payment that rolled back must not be receipted.');
        });
    }

    public function test_automation_that_fails_leaves_the_payment_standing(): void
    {
        $this->onTenant($this->organization, function () {
            $recorder = $this->record();
            $recorder->fail = new RuntimeException('whatsapp is down');

            Log::spy();

            $invoice = $this->bill();

            // The act under test: it must not throw, even though automation does.
            $settled = app(BillingService::class)->recordPayment($invoice, $this->cash(500));

            $this->assertSame(Invoice::STATUS_PAID, $settled->status);
            $this->assertEqualsWithDelta(500.0, (float) $settled->paid_amount, 0.001);
            $this->assertDatabaseCount('invoice_payments', 1, TenantConnectionService::CONNECTION);

            Log::shouldHaveReceived('error')->withArgs(
                fn (string $message) => $message === 'clinic-event.failed',
            );
        });
    }

    public function test_a_partial_payment_says_both_that_it_happened_and_that_money_is_still_owed(): void
    {
        $this->onTenant($this->organization, function () {
            $recorder = $this->record();

            $invoice = $this->bill();
            $recorder->forget(); // drop the invoice_created from drawing it

            app(BillingService::class)->recordPayment($invoice, $this->cash(200));

            $this->assertSame([
                ClinicEvents::PAYMENT_RECEIVED,
                ClinicEvents::PAYMENT_PARTIALLY_RECEIVED,
            ], $recorder->seen);

            $this->assertSame(Invoice::STATUS_PARTIALLY_PAID, $invoice->fresh()->status);
        });
    }

    public function test_a_settling_payment_reports_the_bill_as_fully_received(): void
    {
        $this->onTenant($this->organization, function () {
            $recorder = $this->record();

            $invoice = $this->bill();
            app(BillingService::class)->recordPayment($invoice, $this->cash(200));
            $recorder->forget();

            app(BillingService::class)->recordPayment($invoice->fresh(), $this->cash(300));

            $this->assertSame([
                ClinicEvents::PAYMENT_RECEIVED,
                ClinicEvents::PAYMENT_FULLY_RECEIVED,
            ], $recorder->seen);
        });
    }

    public function test_a_refund_is_its_own_event_and_never_a_payment(): void
    {
        $this->onTenant($this->organization, function () {
            $recorder = $this->record();

            $paid = app(BillingService::class)->recordPayment($this->bill(), $this->cash(500));
            $payment = $paid->payments->sole();
            $recorder->forget();

            app(BillingService::class)->refund($paid, $payment, 200, 'Charged for a test not done');

            // A clinic whose rule is "receipt on payment_received" must not
            // print one when money goes back.
            $this->assertSame([ClinicEvents::REFUND_PROCESSED], $recorder->seen);

            $refund = InvoicePayment::query()->where('is_refund', true)->sole();
            $this->assertSame($refund->id, $recorder->events[0]->meta['refund_id']);
            $this->assertSame($payment->id, $recorder->events[0]->meta['refunds_payment_id']);
        });
    }

    public function test_a_repeated_state_change_does_not_raise_a_second_event(): void
    {
        $this->onTenant($this->organization, function () {
            $recorder = $this->record();

            // A visit bill opens as a draft, so it becomes a bill at finalize.
            $invoice = app(BillingService::class)->draw(
                [
                    'location_id' => $this->branch,
                    'customer_id' => $this->customer,
                    'trigger' => 'consultation',
                    'kind' => Invoice::KIND_VISIT,
                ],
                [['source_type' => InvoiceItem::SOURCE_CUSTOM, 'description' => 'Consultation', 'unit_price' => 500]],
            );

            $this->assertSame([], $recorder->seen, 'A draft is not yet a bill.');

            app(BillingService::class)->finalize($invoice);
            app(BillingService::class)->finalize($invoice->fresh());

            $this->assertSame(
                [ClinicEvents::INVOICE_CREATED],
                $recorder->seen,
                'Finalizing an already-final invoice must not raise it again.',
            );
        });
    }

    public function test_a_resubmitted_settling_payment_neither_takes_the_money_twice_nor_raises_twice(): void
    {
        $this->onTenant($this->organization, function () {
            $recorder = $this->record();

            $invoice = $this->bill();
            $recorder->forget();

            app(BillingService::class)->recordPayment($invoice, $this->cash(500));

            // The same request again — a double-click, a retry on a bad line.
            try {
                app(BillingService::class)->recordPayment($invoice->fresh(), $this->cash(500));
                $this->fail('The same bill was paid twice.');
            } catch (BillingConflict) {
                // expected: nothing is outstanding
            }

            $this->assertDatabaseCount('invoice_payments', 1, TenantConnectionService::CONNECTION);
            $this->assertSame([
                ClinicEvents::PAYMENT_RECEIVED,
                ClinicEvents::PAYMENT_FULLY_RECEIVED,
            ], $recorder->seen);
        });
    }

    /**
     * The event's own identity: two instalments are two events, and the same
     * payment raised again — a replayed job, a retried callback — is the same
     * one. What the automation files is keyed on the record rather than on
     * this; DocumentRulesTest pins that.
     */
    public function test_one_payment_has_one_identity_however_often_it_is_raised(): void
    {
        $this->onTenant($this->organization, function () {
            $recorder = $this->record();

            $invoice = $this->bill();
            app(BillingService::class)->recordPayment($invoice, $this->cash(200));
            app(BillingService::class)->recordPayment($invoice->fresh(), $this->cash(300));

            [$first, $second] = $recorder->of(ClinicEvents::PAYMENT_RECEIVED);

            $this->assertNotSame($first->idempotencyKey(), $second->idempotencyKey());

            $replayed = ClinicEvent::for(ClinicEvents::PAYMENT_RECEIVED, $invoice->fresh(), $first->meta);

            $this->assertSame($first->idempotencyKey(), $replayed->idempotencyKey());
        });
    }

    public function test_a_configured_rule_runs_through_real_automation_and_logs_no_patient_details(): void
    {
        // The real automation files a real PDF, which needs the module and a disk.
        Storage::fake('local');
        $this->artisan('modules:sync');
        $this->grantModule($this->organization, 'documents');

        $this->onTenant($this->organization, function () {
            // What the rules table would return: this clinic receipts payments.
            $this->app->instance(DocumentRules::class, new class extends DocumentRules
            {
                public function for(ClinicEvent $event): array
                {
                    return $event->key === ClinicEvents::PAYMENT_RECEIVED
                        ? [new DocumentRule('clinic_receipt', whatsapp: true)]
                        : [];
                }
            });

            $invoice = $this->bill();

            $logged = [];
            Log::listen(function (MessageLogged $entry) use (&$logged) {
                $logged[] = ['message' => $entry->message, 'context' => $entry->context];
            });

            app(BillingService::class)->recordPayment($invoice, $this->cash(500));

            $messages = array_column($logged, 'message');

            foreach ([
                'clinic-event.received',
                'document-automation.rules-found',
                'document-automation.started',
                'document-automation.generated',
                'document-automation.completed',
            ] as $expected) {
                $this->assertContains($expected, $messages);
            }

            // payment_fully_received has no rule here — a quiet no, not an error.
            $this->assertContains('document-automation.no-rules', $messages);
            $this->assertNotContains('clinic-event.failed', $messages);

            $started = $logged[array_search('document-automation.started', $messages, true)]['context'];
            $this->assertSame('clinic_receipt', $started['document_type']);
            $this->assertSame(ClinicEvents::PAYMENT_RECEIVED, $started['event']);

            $completed = $logged[array_search('document-automation.completed', $messages, true)]['context'];
            $this->assertSame(0, $completed['failed']);

            // References only — never who the patient is.
            $flat = json_encode($logged);
            $this->assertStringNotContainsString(self::PATIENT_NAME, $flat);
            $this->assertStringNotContainsString(self::PATIENT_MOBILE, $flat);
        });
    }

    public function test_an_event_cannot_execute_against_another_organisations_database(): void
    {
        $other = $this->provisionOrganization('EV2');

        $recorder = $this->record();

        // Raised while connected to the first organisation...
        $event = $this->onTenant($this->organization, fn () => ClinicEvent::for(
            ClinicEvents::PAYMENT_RECEIVED,
            Customer::find($this->customer),
        ));

        $this->assertSame($this->organization->database_name, $event->database);

        // ...and dispatched while connected to the second.
        $this->onTenant($other, function () use ($event) {
            Log::spy();

            app(ClinicEventDispatcher::class)->dispatch($event);

            Log::shouldHaveReceived('warning')->withArgs(
                fn (string $message) => $message === 'clinic-event.tenant-mismatch',
            );
        });

        $this->assertSame(
            [],
            $recorder->seen,
            'An event raised in one tenant must never run against another.',
        );
    }

    public function test_the_registry_refuses_an_event_nobody_dispatches(): void
    {
        $this->onTenant($this->organization, function () {
            $this->expectException(\InvalidArgumentException::class);

            ClinicEvent::for('lab_report_finalized', Customer::find($this->customer));
        });
    }
}
