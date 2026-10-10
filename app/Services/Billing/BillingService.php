<?php

namespace App\Services\Billing;

use App\Models\Tenant\Appointment;
use App\Models\Tenant\BillingSetting;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\InvoiceItem;
use App\Models\Tenant\InvoicePayment;
use App\Models\Tenant\User;
use App\Services\Clinic\ClinicEvent;
use App\Services\Clinic\ClinicEventDispatcher;
use App\Services\Opd\VisitWorkflow;
use App\Support\Clinic\ClinicEvents;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;

/**
 * Drawing an invoice, taking payment, cancelling and refunding — one place.
 *
 * The same shape as SalesService: synchronous, wrapped in a transaction, and
 * the only thing that writes to `invoices`, `invoice_items` and
 * `invoice_payments`. Scattering these across controllers is how a bill and
 * its payments come to disagree.
 *
 * Every department that draws an invoice — the OPD for a visit, the lab for
 * an order, the desk for a manual bill — draws it here. The pharmacy does
 * not: its bill is the sale, and SalesService owns it.
 *
 * IDEMPOTENCY. Drawing is idempotent by SOURCE: a consultation billed twice
 * names a source the invoice already carries, and the partial unique indexes
 * (one line per source per invoice, one live invoice per visit and per lab
 * order) catch a second attempt at the database. Taking money is idempotent
 * by KEY: a collection carrying an Idempotency-Key the register has already
 * seen answers with the bill as that first collection left it.
 *
 * WHAT A BILL WAS PRICED UNDER is copied onto it as it is drawn — currency
 * and whether prices include tax — and every later re-sum reads the bill's
 * own copy. Changing the settings never re-reads an issued bill.
 */
class BillingService
{
    public function __construct(
        private readonly InvoicePricing $pricing,
        /*
         * Raised after the transaction commits, never inside it — see
         * ClinicEventDispatcher. Required rather than nullable: unlike the
         * visit workflow below there is no construction cycle here, and a
         * silently absent dispatcher would mean paperwork that simply never
         * happens, found by a clinic wondering where their receipts went.
         */
        private readonly ClinicEventDispatcher $events,
        /*
         * Nullable to avoid a construction cycle: VisitWorkflow depends on
         * BillingTriggerResolver, which depends on this. Resolved at the
         * moment of use when absent — see refreshVisit().
         */
        private readonly ?VisitWorkflow $visits = null,
    ) {}

    /**
     * Draw an invoice from an ordered set of lines.
     *
     * `header` names the customer/visit/branch and the trigger; `lines` is
     * what to charge for. Returns the persisted invoice with `items` and
     * `payments` loaded, so a caller can hand it straight back to the client.
     *
     * A VISIT invoice opens as a DRAFT and is finalized by whoever drew it —
     * BillingTriggerResolver does both in one transaction, so a draft is
     * never left behind. Every other kind (registration, manual, lab) is
     * payable the moment it exists.
     *
     * @param  array{
     *     location_id: int,
     *     customer_id?: int|null,
     *     walk_in_name?: string|null,
     *     walk_in_phone?: string|null,
     *     appointment_id?: int|null,
     *     consultation_id?: int|null,
     *     lab_order_id?: int|null,
     *     doctor_id?: int|null,
     *     trigger: string,
     *     kind?: string,
     *     notes?: string|null,
     * }  $header
     * @param  list<array<string, mixed>>  $lines
     */
    public function draw(array $header, array $lines): Invoice
    {
        return $this->transaction(function () use ($header, $lines) {
            $settings = BillingSetting::current();

            // The rule this bill is priced by, fixed now and kept.
            $taxInclusive = (bool) $settings->prices_include_tax;

            $priced = $this->price($lines, $settings, $taxInclusive);
            $totals = $this->pricing->totals($priced, $taxInclusive);

            $actor = Auth::guard('web')->user();

            $kind = $header['kind'] ?? Invoice::KIND_VISIT;

            $status = $kind === Invoice::KIND_VISIT
                ? Invoice::STATUS_DRAFT
                : Invoice::STATUS_PENDING;

            $invoice = Invoice::create([
                'location_id' => $header['location_id'],
                'customer_id' => $header['customer_id'] ?? null,
                'walk_in_name' => $header['walk_in_name'] ?? null,
                'walk_in_phone' => $header['walk_in_phone'] ?? null,
                'appointment_id' => $header['appointment_id'] ?? null,
                'consultation_id' => $header['consultation_id'] ?? null,
                'lab_order_id' => $header['lab_order_id'] ?? null,
                'doctor_id' => $header['doctor_id'] ?? null,
                'invoice_date' => now(),
                'status' => $status,
                'kind' => $kind,
                'trigger' => $header['trigger'],
                'currency_code' => $settings->currency_code,
                'prices_include_tax' => $taxInclusive,
                ...$totals,
                'paid_amount' => 0,
                'refunded_amount' => 0,
                'payment_status' => Invoice::UNPAID,
                'notes' => $header['notes'] ?? null,
                'terms' => $settings->terms,
                'created_by' => $actor instanceof User ? $actor->id : null,
                'created_by_name' => $actor instanceof User ? $actor->name : null,
                'finalized_at' => $status === Invoice::STATUS_PENDING ? now() : null,
                'finalized_by' => $status === Invoice::STATUS_PENDING && $actor instanceof User
                    ? $actor->id
                    : null,
            ]);

            // The number is the database's, taken as the row went in.
            $invoice->refresh();

            foreach ($priced as $line) {
                InvoiceItem::create(['invoice_id' => $invoice->id, ...$line]);
            }

            /*
             * A bill payable from the start became a bill now. A visit draft
             * raises the same event from finalize() instead — one
             * `invoice_created` per invoice, at whichever point it actually
             * became something a patient could be handed.
             */
            if ($invoice->isFinalized()) {
                // A bill for nothing owes nothing, and must not sit "pending".
                $this->settle($invoice);

                $this->events->dispatch(
                    ClinicEvent::for(ClinicEvents::INVOICE_CREATED, $invoice, [
                        'kind' => $invoice->kind,
                    ]),
                );
            }

            return $invoice->load(['items', 'payments', 'customer']);
        });
    }

    /**
     * Rewrite an invoice's lines — staff correcting a bill.
     *
     * WHO MAY, AND WHEN:
     *
     *   - a DRAFT is a running tab, and may always be corrected;
     *   - an ISSUED bill may be corrected only while nothing has been paid
     *     against it and the billing settings allow editing before payment;
     *   - a bill any money has moved against — paid, part paid, or paid and
     *     refunded — is never edited. The correction for that is a refund
     *     (which credits the bill) or cancelling and raising a new one, both
     *     of which leave their own record.
     *
     * A line drawn from the workflow (a consultation, a lab test) may be kept
     * or dropped, but not invented: only a catalogue service, a procedure or
     * a typed line can be added by hand.
     *
     * Audited: the lines before and after are written to the activity log
     * against the invoice, alongside the totals the save itself records.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    public function rewrite(Invoice $invoice, array $lines, ?string $notes = null): Invoice
    {
        return $this->transaction(function () use ($invoice, $lines, $notes) {
            $locked = $this->lock($invoice);

            $this->assertEditable($locked);

            if (! $locked->isDraft() && ! BillingSetting::current()->allow_edit_before_payment) {
                throw BillingConflict::because(
                    "Invoice {$locked->invoice_number} has been issued, and your billing settings do not allow editing a bill after that. Cancel it and raise a corrected one."
                );
            }

            $this->assertSourcesBelong($locked, $lines);

            return $this->replace($locked, $lines, $notes ?? $locked->notes, null);
        });
    }

    /**
     * Bring a bill's lines back in step with what it was drawn from.
     *
     * The SYSTEM's correction, not staff's: a lab order whose tests changed
     * after its bill was drawn. The editing setting does not apply — nobody
     * is repricing anything, the order itself changed — but the money rule
     * does: once anything has been paid, the bill stands, and the difference
     * is somebody's to settle by hand.
     *
     * Null when the bill can no longer follow its source, so the caller can
     * say so rather than pretend it did.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    public function resync(Invoice $invoice, array $lines, string $reason): ?Invoice
    {
        return $this->transaction(function () use ($invoice, $lines, $reason) {
            $locked = $this->lock($invoice);

            if ($locked->isCancelled() || $locked->hasMoneyMoved()) {
                return null;
            }

            return $this->replace($locked, $lines, $locked->notes, $reason);
        });
    }

    /**
     * Swap a locked, editable invoice's lines and re-sum it.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function replace(Invoice $locked, array $lines, ?string $notes, ?string $reason): Invoice
    {
        $settings = BillingSetting::current();
        $taxInclusive = $locked->pricesIncludeTax();

        $before = $this->describe($locked->items()->orderBy('id')->get()->all());

        $priced = $this->price($lines, $settings, $taxInclusive);

        /*
         * The line guard's one door, opened for this statement and closed
         * again straight after. The database still refuses unless the bill
         * is uncancelled and no money has ever moved against it — the same
         * rule as assertEditable(), held where a bug cannot route around it.
         */
        $connection = $locked->getConnection();
        $connection->statement("SET LOCAL hms.invoice_rewrite = 'on'");
        InvoiceItem::query()->where('invoice_id', $locked->id)->delete();
        $connection->statement("SET LOCAL hms.invoice_rewrite = 'off'");

        foreach ($priced as $line) {
            InvoiceItem::create(['invoice_id' => $locked->id, ...$line]);
        }

        $after = $this->describe($priced);

        $locked->withHistoryNote($reason)
            ->forceFill([...$this->pricing->totals($priced, $taxInclusive), 'notes' => $notes])
            ->save();

        $locked->writeHistoryFor('items', $before, $after);

        /*
         * The status is left as it was, other than what the money now says:
         * a draft stays a draft (the visit may still be producing charges),
         * an issued bill stays payable — or becomes settled, if it now comes
         * to nothing.
         */
        if ($locked->isFinalized()) {
            $this->settle($locked);
        }

        $this->refreshVisit($locked);

        return $locked->load(['items', 'payments', 'customer']);
    }

    /**
     * Append charges to a bill that is still collecting them.
     *
     * Kept for a visit draft drawn before each department billed for itself:
     * the OPD's own charges are added to it and it is finalized, rather than
     * a second visit bill being drawn beside it.
     *
     * IDEMPOTENT BY SOURCE. A line naming a `source_type`/`source_id` this
     * invoice already carries is skipped rather than duplicated. The partial
     * unique index on `(invoice_id, source_type, source_id)` is the backstop.
     *
     * Refused once finalized: the bill has been presented, and a charge
     * appearing after the patient has read the total is how a counter loses
     * an argument it should never have had.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    public function addLines(Invoice $invoice, array $lines): Invoice
    {
        if ($lines === []) {
            return $invoice->load(['items', 'payments', 'customer']);
        }

        return $this->transaction(function () use ($invoice, $lines) {
            $locked = $this->lock($invoice);

            if ($locked->isCancelled()) {
                throw BillingConflict::because(
                    "Invoice {$locked->invoice_number} is cancelled, so nothing more can be charged to it."
                );
            }

            if (! $locked->acceptsMoreLines()) {
                throw BillingConflict::because(
                    "Invoice {$locked->invoice_number} has been finalized. Charges after that go on a new bill."
                );
            }

            $settings = BillingSetting::current();

            // What it already carries, so a repeat of the same source is a
            // no-op rather than a second charge.
            $already = InvoiceItem::query()
                ->where('invoice_id', $locked->id)
                ->whereNotNull('source_id')
                ->get(['source_type', 'source_id'])
                ->map(fn (InvoiceItem $item) => $item->source_type.':'.$item->source_id)
                ->all();

            $fresh = [];

            foreach ($lines as $line) {
                $key = ($line['source_type'] ?? '').':'.($line['source_id'] ?? '');

                if (($line['source_id'] ?? null) !== null && in_array($key, $already, true)) {
                    continue;
                }

                $fresh[] = $line;
                $already[] = $key;
            }

            foreach ($this->price($fresh, $settings, $locked->pricesIncludeTax()) as $line) {
                InvoiceItem::create(['invoice_id' => $locked->id, ...$line]);
            }

            if ($fresh !== []) {
                $this->recalculate($locked);
            }

            return $locked->load(['items', 'payments', 'customer']);
        });
    }

    /**
     * Declare the bill complete — it may now be paid.
     *
     * Idempotent: finalizing a finalized invoice is the same invoice back,
     * not an error, because two events racing to be "the last one" is normal.
     */
    public function finalize(Invoice $invoice): Invoice
    {
        return $this->transaction(function () use ($invoice) {
            $locked = $this->lock($invoice);

            if ($locked->isCancelled()) {
                throw BillingConflict::because("Invoice {$locked->invoice_number} is cancelled.");
            }

            if ($locked->isFinalized()) {
                return $locked->load(['items', 'payments', 'customer']);
            }

            // Totals are recomputed here rather than trusted: lines may have
            // been added by several different events since the last sum.
            $this->recalculate($locked);

            $actor = Auth::guard('web')->user();

            $locked->forceFill([
                'status' => Invoice::STATUS_PENDING,
                'finalized_at' => now(),
                'finalized_by' => $actor instanceof User ? $actor->id : null,
            ])->save();

            // A bill for nothing is settled the moment it exists.
            $this->settle($locked);

            // The visit can now report "awaiting payment" honestly — before
            // this it was a draft nobody could settle.
            $this->refreshVisit($locked);

            /*
             * The draft became a bill. Finalizing twice cannot raise this
             * twice: the guard above returns early on an already-finalized
             * invoice, so the event is as idempotent as the state change it
             * reports.
             */
            $this->events->dispatch(
                ClinicEvent::for(ClinicEvents::INVOICE_CREATED, $locked, [
                    'kind' => $locked->kind,
                ]),
            );

            return $locked->load(['items', 'payments', 'customer']);
        });
    }

    /**
     * Re-sum an invoice from its own lines, under its own tax rule.
     *
     * Expects the row locked and inside a transaction.
     */
    private function recalculate(Invoice $locked): void
    {
        $lines = InvoiceItem::query()
            ->where('invoice_id', $locked->id)
            ->get()
            ->map(fn (InvoiceItem $item) => [
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'discount_amount' => (float) $item->discount_amount,
                'tax_amount' => (float) $item->tax_amount,
                'line_total' => (float) $item->line_total,
            ])
            ->all();

        $locked->forceFill($this->pricing->totals($lines, $locked->pricesIncludeTax()))->save();
    }

    /**
     * Take one payment against an invoice.
     *
     * @param  array{method: string, amount: float|int, reference?: string|null, notes?: string|null}  $payment
     */
    public function recordPayment(Invoice $invoice, array $payment, ?string $key = null): Invoice
    {
        return $this->collect($invoice, [$payment], $key);
    }

    /**
     * Take money against an invoice — one tender or several, as one act.
     *
     * Several because a patient settling ₹500 as ₹300 cash and ₹200 UPI is
     * paying the bill once, and a clinic that takes bills in full must still
     * be able to take that. Each tender is its own row with its own receipt
     * number; the policy is asked of what they come to together.
     *
     * THE ORGANISATION'S RULES, enforced here rather than trusted to a
     * screen:
     *
     *   - full     what is taken has to clear the bill;
     *   - partial  any part of what is owed may be taken;
     *   - credit   the same, and the visit may close with the rest owing.
     *
     * The invoice's `paid_amount` and status are recomputed from the payment
     * rows — never trusted from the request.
     *
     * IDEMPOTENT BY KEY. A key the register has already seen answers with the
     * bill as that collection left it, recording nothing. Asked again under
     * the row lock, so a double-tap that arrives while the first is still
     * saving waits for it and then finds it.
     *
     * @param  list<array{method: string, amount: float|int, reference?: string|null, notes?: string|null}>  $tenders
     */
    public function collect(Invoice $invoice, array $tenders, ?string $key = null): Invoice
    {
        if ($key !== null && ($replay = $this->replayed($invoice, $key))) {
            return $replay;
        }

        try {
            return $this->transaction(function () use ($invoice, $tenders, $key) {
                $locked = $this->lock($invoice);

                if ($key !== null && ($replay = $this->replayed($locked, $key))) {
                    return $replay;
                }

                if ($locked->isCancelled()) {
                    throw BillingConflict::because("Invoice {$locked->invoice_number} is cancelled — nothing to pay.");
                }

                /*
                 * A draft is a running tab, not a bill. Taking money against
                 * one would be asking the patient to pay before the last
                 * charge has landed.
                 */
                if ($locked->isDraft()) {
                    throw BillingConflict::because(
                        "Invoice {$locked->invoice_number} is still collecting charges for this visit. Finalize it before taking payment."
                    );
                }

                if ($tenders === []) {
                    throw BillingConflict::because('Say how much was paid, and how.');
                }

                $settings = BillingSetting::current();
                $amounts = [];

                foreach ($tenders as $tender) {
                    $amount = round((float) $tender['amount'], 2);

                    if ($amount <= 0) {
                        throw BillingConflict::because('A payment has to be a positive amount.');
                    }

                    if (! $settings->accepts((string) $tender['method'])) {
                        throw BillingConflict::because(sprintf(
                            'This clinic does not take %s. Change payment settings to add it.',
                            str_replace('_', ' ', (string) $tender['method']),
                        ));
                    }

                    $amounts[] = $amount;
                }

                $total = round(array_sum($amounts), 2);
                $outstanding = $locked->outstanding();

                if ($total > $outstanding + 0.001) {
                    throw BillingConflict::because(sprintf(
                        'That is more than the invoice owes. Outstanding is %.2f.',
                        $outstanding,
                    ));
                }

                if (! $settings->allowsPartialPayment() && $total < $outstanding - 0.001) {
                    throw BillingConflict::because(sprintf(
                        'This clinic takes bills in full. Take %.2f — split it across payment methods if you need to.',
                        $outstanding,
                    ));
                }

                $actor = Auth::guard('web')->user();
                $records = [];

                foreach (array_values($tenders) as $index => $tender) {
                    $records[] = InvoicePayment::create([
                        'invoice_id' => $locked->id,
                        'method' => $tender['method'],
                        'amount' => $amounts[$index],
                        'reference' => $tender['reference'] ?? null,
                        'notes' => $tender['notes'] ?? null,
                        'paid_at' => now(),
                        'created_by' => $actor instanceof User ? $actor->id : null,
                        'is_refund' => false,
                        // The first tender carries the key; the rest are told apart from it.
                        'idempotency_key' => $key === null ? null : ($index === 0 ? $key : "{$key}#{$index}"),
                    ]);
                }

                $this->settle($locked);

                // Tell the visit the till has moved, so its own status column
                // stops reading `awaiting_payment` the moment the balance is nil.
                $this->refreshVisit($locked);

                /*
                 * TWO EVENTS PER PAYMENT, NOT ONE.
                 *
                 * `payment_received` always fires — the fact that money
                 * changed hands, and a clinic that receipts every payment
                 * wants exactly this. The second says what the payment did to
                 * the bill, because "a receipt for each instalment" and
                 * "settled in full only once" are different pieces of paper.
                 * Only the last tender of a collection can be the one that
                 * settled it.
                 */
                $settled = $locked->outstanding() <= 0.001;
                $last = count($records) - 1;

                foreach ($records as $index => $record) {
                    $meta = ['payment_id' => $record->id, 'method' => $record->method];

                    $this->events->dispatch(
                        ClinicEvent::for(ClinicEvents::PAYMENT_RECEIVED, $locked, $meta),
                    );

                    $this->events->dispatch(ClinicEvent::for(
                        $settled && $index === $last
                            ? ClinicEvents::PAYMENT_FULLY_RECEIVED
                            : ClinicEvents::PAYMENT_PARTIALLY_RECEIVED,
                        $locked,
                        $meta,
                    ));
                }

                return $locked->load(['items', 'payments', 'customer']);
            });
        } catch (UniqueConstraintViolationException $e) {
            // Two taps raced past the lock check — the winner's row is the answer.
            if ($key !== null && ($replay = $this->replayed($invoice, $key))) {
                return $replay;
            }

            throw $e;
        }
    }

    /**
     * Refund a payment.
     *
     * A refund CREDITS THE BILL: money handed back reduces what the bill is
     * for, so a bill paid in full and refunded in full owes nothing — it
     * reads `refunded`, not `pending` — and a partial refund leaves a bill
     * `partially_refunded`. The refund row carries the method of the payment
     * it undoes, and its own number from the branch's refund series.
     *
     * Idempotent by key, like a payment.
     */
    public function refund(Invoice $invoice, InvoicePayment $payment, float $amount, ?string $reason = null, ?string $key = null): Invoice
    {
        if ($key !== null && ($replay = $this->replayed($invoice, $key))) {
            return $replay;
        }

        try {
            return $this->transaction(function () use ($invoice, $payment, $amount, $reason, $key) {
                $locked = $this->lock($invoice);

                if ($key !== null && ($replay = $this->replayed($locked, $key))) {
                    return $replay;
                }

                if ($payment->invoice_id !== $locked->id) {
                    throw BillingConflict::because('That payment is not against this invoice.');
                }

                if ($payment->is_refund) {
                    throw BillingConflict::because('That row is already a refund — you cannot refund a refund.');
                }

                $amount = round($amount, 2);

                if ($amount <= 0) {
                    throw BillingConflict::because('A refund has to be a positive amount.');
                }

                $alreadyRefunded = (float) InvoicePayment::query()
                    ->where('refunds_payment_id', $payment->id)
                    ->where('is_refund', true)
                    ->sum('amount');

                $refundable = round((float) $payment->amount - $alreadyRefunded, 2);

                if ($amount > $refundable + 0.001) {
                    throw BillingConflict::because(sprintf(
                        'That payment has %.2f left to refund; %.2f was asked for.',
                        $refundable,
                        $amount,
                    ));
                }

                $actor = Auth::guard('web')->user();

                $record = InvoicePayment::create([
                    'invoice_id' => $locked->id,
                    'method' => $payment->method,
                    'amount' => $amount,
                    'reference' => $payment->reference,
                    'notes' => $reason,
                    'paid_at' => now(),
                    'created_by' => $actor instanceof User ? $actor->id : null,
                    'is_refund' => true,
                    'refunds_payment_id' => $payment->id,
                    'idempotency_key' => $key,
                ]);

                $this->settle($locked);

                $this->refreshVisit($locked);

                /*
                 * Money went back. Deliberately NOT also raising a payment
                 * event: a refund is its own document, and a clinic whose
                 * rule says "receipt on payment_received" must not have one
                 * printed when it gives money back.
                 */
                $this->events->dispatch(
                    ClinicEvent::for(ClinicEvents::REFUND_PROCESSED, $locked, [
                        'refund_id' => $record->id,
                        'refunds_payment_id' => $payment->id,
                    ]),
                );

                return $locked->load(['items', 'payments', 'customer']);
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($key !== null && ($replay = $this->replayed($invoice, $key))) {
                return $replay;
            }

            throw $e;
        }
    }

    /**
     * Cancel an invoice.
     *
     * Refused while money taken against it is still held — refund it first,
     * so the cancellation and the money going back are both on the record —
     * but allowed while there is only outstanding balance.
     */
    public function cancel(Invoice $invoice, string $reason): Invoice
    {
        return $this->transaction(function () use ($invoice, $reason) {
            $locked = $this->lock($invoice);

            if ($locked->isCancelled()) {
                throw BillingConflict::because("Invoice {$locked->invoice_number} is already cancelled.");
            }

            if ((float) $locked->paid_amount > 0) {
                throw BillingConflict::because(
                    "Invoice {$locked->invoice_number} has been paid. Refund the payment before cancelling the invoice."
                );
            }

            $actor = Auth::guard('web')->user();

            $locked->forceFill([
                'status' => Invoice::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $actor instanceof User ? $actor->id : null,
                'cancellation_reason' => $reason,
            ])->save();

            // A cancelled invoice no longer owes money, so the visit can
            // close if this was the last thing outstanding.
            $this->refreshVisit($locked);

            return $locked->load(['items', 'payments', 'customer']);
        });
    }

    /**
     * Whether a live invoice already exists for a given source — a
     * consultation, an appointment, whatever.
     */
    public function existingFor(string $sourceType, int $sourceId, ?int $locationId = null): ?Invoice
    {
        $query = Invoice::query()
            ->whereHas('items', function ($q) use ($sourceType, $sourceId) {
                $q->where('source_type', $sourceType)->where('source_id', $sourceId);
            })
            ->whereNot('status', Invoice::STATUS_CANCELLED);

        if ($locationId !== null) {
            $query->where('location_id', $locationId);
        }

        return $query->with(['items', 'payments'])->first();
    }

    /**
     * Recompute the money columns and status from the payment rows.
     *
     *   paid_amount      money taken, less money handed back — what is held
     *   refunded_amount  money handed back, which also credits the bill
     *   outstanding      total − paid − refunded = total − everything taken
     *
     * Status, for a bill that is not cancelled or a draft:
     *
     *   still owes        → partially_paid if anything is held, else pending
     *   owes nothing      → paid; or refunded if everything taken went back;
     *                       or partially_refunded if some of it did
     *
     * Called inside every path that writes a payment or a refund, with the
     * row locked.
     */
    private function settle(Invoice $locked): void
    {
        $taken = (float) InvoicePayment::query()
            ->where('invoice_id', $locked->id)
            ->where('is_refund', false)
            ->sum('amount');

        $returned = (float) InvoicePayment::query()
            ->where('invoice_id', $locked->id)
            ->where('is_refund', true)
            ->sum('amount');

        $held = round(max(0.0, $taken - $returned), 2);
        $returned = round($returned, 2);
        $owed = round((float) $locked->total_amount - $held - $returned, 2);

        $paymentStatus = match (true) {
            $owed <= 0.001 => Invoice::PAID,
            $held > 0.001 => Invoice::PARTIAL,
            default => Invoice::UNPAID,
        };

        $status = match (true) {
            $locked->isCancelled() => Invoice::STATUS_CANCELLED,
            $locked->isDraft() => Invoice::STATUS_DRAFT,
            $owed > 0.001 && $held > 0.001 => Invoice::STATUS_PARTIALLY_PAID,
            $owed > 0.001 => Invoice::STATUS_PENDING,
            $returned <= 0.001 => Invoice::STATUS_PAID,
            $held <= 0.001 => Invoice::STATUS_REFUNDED,
            default => Invoice::STATUS_PARTIALLY_REFUNDED,
        };

        $locked->forceFill([
            'paid_amount' => $held,
            'refunded_amount' => $returned,
            'payment_status' => $paymentStatus,
            'status' => $status,
        ])->save();
    }

    /**
     * The bill as a previous collection left it, when this key has been seen.
     *
     * A key used against a DIFFERENT bill is refused rather than replayed:
     * answering with somebody else's invoice would be worse than an error.
     */
    private function replayed(Invoice $invoice, string $key): ?Invoice
    {
        $seen = InvoicePayment::query()->where('idempotency_key', $key)->first();

        if (! $seen) {
            return null;
        }

        if ((int) $seen->invoice_id !== (int) $invoice->id) {
            throw BillingConflict::because('That request was already used for a different invoice. Reload and try again.');
        }

        return Invoice::query()->with(['items', 'payments', 'customer'])->findOrFail($invoice->id);
    }

    /** Refuse an edit on a bill whose money has moved, or that is cancelled. */
    private function assertEditable(Invoice $locked): void
    {
        if ($locked->isCancelled()) {
            throw BillingConflict::because("Invoice {$locked->invoice_number} is cancelled and cannot be edited.");
        }

        if ($locked->hasMoneyMoved()) {
            throw BillingConflict::because(
                "Invoice {$locked->invoice_number} has had money taken against it, so it cannot be edited. Refund the payment, or cancel it and raise a corrected bill."
            );
        }
    }

    /**
     * A line from the workflow may be kept, but not typed in.
     *
     * Consultation, lab and pharmacy lines name a real record. Allowing them
     * through an edit would let a hand-typed line claim to be a consultation
     * — or a pharmacy sale already paid at the counter — so each one must
     * already be on this bill.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function assertSourcesBelong(Invoice $locked, array $lines): void
    {
        $carried = InvoiceItem::query()
            ->where('invoice_id', $locked->id)
            ->get(['source_type', 'source_id'])
            ->map(fn (InvoiceItem $item) => $item->source_type.':'.$item->source_id)
            ->all();

        foreach ($lines as $line) {
            $type = (string) ($line['source_type'] ?? '');

            if (in_array($type, InvoiceItem::MANUAL_SOURCES, true)) {
                continue;
            }

            if (! in_array($type.':'.($line['source_id'] ?? ''), $carried, true)) {
                throw BillingConflict::because(
                    'Only services, procedures and typed lines can be added to a bill by hand.'
                );
            }
        }
    }

    /**
     * Price a set of lines under one tax rule.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function price(array $lines, BillingSetting $settings, bool $taxInclusive): array
    {
        return array_map(
            fn (array $line) => [
                'source_type' => $line['source_type'],
                'source_id' => $line['source_id'] ?? null,
                'billable_service_id' => $line['billable_service_id'] ?? null,
                'hsn_code' => $line['hsn_code'] ?? null,
                'description' => (string) $line['description'],
                ...$this->pricing->line([
                    'quantity' => $line['quantity'] ?? 1,
                    'unit_price' => $line['unit_price'] ?? 0,
                    'discount_percent' => $line['discount_percent'] ?? 0,
                    'discount_amount' => $line['discount_amount'] ?? 0,
                    'tax_percent' => $line['tax_percent'] ?? $settings->default_tax_percent,
                ], $taxInclusive),
            ],
            array_values($lines),
        );
    }

    /**
     * Lines as the activity log shows them: "Consultation × 1 = 500.00".
     *
     * @param  list<InvoiceItem|array<string, mixed>>  $lines
     * @return list<string>
     */
    private function describe(array $lines): array
    {
        return array_map(function ($line) {
            $line = $line instanceof InvoiceItem ? $line->toArray() : $line;

            return sprintf(
                '%s × %s = %.2f',
                $line['description'],
                rtrim(rtrim(number_format((float) $line['quantity'], 2, '.', ''), '0'), '.'),
                (float) $line['line_total'],
            );
        }, $lines);
    }

    /**
     * Tell the visit workflow that something on this invoice moved.
     *
     * A no-op for a bill with no visit behind it.
     */
    private function refreshVisit(Invoice $locked): void
    {
        if (! $locked->appointment_id) {
            return;
        }

        $appointment = Appointment::query()->find($locked->appointment_id);

        /*
         * Resolved here, not trusted to the constructor: Laravel 12 leaves an
         * optional `?VisitWorkflow $visits = null` at its default unless it is
         * bound, so the visit never heard it had been paid and stayed
         * "awaiting payment". Resolving at the moment of use also sidesteps
         * the construction cycle the parameter was made nullable for.
         */
        if ($appointment) {
            ($this->visits ?? app(VisitWorkflow::class))->refresh($appointment);
        }
    }

    /** The row, held against everybody else until this transaction commits. */
    private function lock(Invoice $invoice): Invoice
    {
        return Invoice::query()
            ->whereKey($invoice->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function transaction(callable $callback): mixed
    {
        return (new Invoice)->getConnection()->transaction($callback);
    }
}
