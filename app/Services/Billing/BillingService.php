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
use Illuminate\Support\Facades\Auth;

/**
 * Drawing an invoice, taking payment, cancelling and refunding — one place.
 *
 * The same shape as SalesService: synchronous, wrapped in a transaction, and
 * the only thing that writes to `invoices`, `invoice_items` and
 * `invoice_payments`. Scattering these across controllers is how a bill and
 * its payments come to disagree.
 *
 * IDEMPOTENCY IS BY SOURCE, not by client key. A consultation billed twice
 * is a real error (the second attempt names a source the invoice already
 * carries), and the partial unique index on `(invoice_id, source_type,
 * source_id)` catches it at the database. Higher up, `existingFor()` asks
 * "does a live invoice already exist for this consultation" before drawing
 * one at all — the trigger resolver uses this to make "complete consultation"
 * safe to click again after a crash.
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
         * BillingTriggerResolver, which depends on this. In an ordinary HTTP
         * request the container resolves the pieces in the right order and
         * this arrives populated; in a test that constructs BillingService
         * directly it can be left out and payment recording still works —
         * the visit will just miss its status refresh, which no assertion
         * about the invoice itself cares about.
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
     * `kind` decides whether this is the consolidated visit bill (which opens
     * as a DRAFT and collects charges until something finalizes it) or a
     * standalone one — a registration fee taken at the desk, or a manual bill
     * — which is payable the moment it exists.
     *
     * @param  array{
     *     location_id: int,
     *     customer_id?: int|null,
     *     walk_in_name?: string|null,
     *     walk_in_phone?: string|null,
     *     appointment_id?: int|null,
     *     consultation_id?: int|null,
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

            $priced = array_map(
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
                    ], $settings),
                ],
                $lines,
            );

            $totals = $this->pricing->totals($priced, $settings);

            $actor = Auth::guard('web')->user();

            $kind = $header['kind'] ?? Invoice::KIND_VISIT;

            /*
             * A visit bill opens as a draft and stays one until something
             * finalizes it — the whole visit's charges land on it first.
             * A registration fee or a manual bill is payable immediately:
             * there is nothing else coming, and the patient is at the desk.
             */
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
                'doctor_id' => $header['doctor_id'] ?? null,
                'invoice_date' => now(),
                'status' => $status,
                'kind' => $kind,
                'trigger' => $header['trigger'],
                ...$totals,
                'paid_amount' => 0,
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
             * A registration fee or a manual bill opens payable, so this is
             * the moment it became a bill. A visit invoice opens as a draft
             * and raises the same event from finalize() instead — one
             * `invoice_created` per invoice, at whichever of the two points
             * it actually became something a patient could be handed.
             */
            if ($invoice->isFinalized()) {
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
     * Rewrite an unpaid invoice's lines — same shape as draw, in place.
     *
     * Refused once anything has been paid: correcting the total on a bill the
     * patient has partly settled is a different act (refund, then reissue).
     *
     * @param  list<array<string, mixed>>  $lines
     */
    public function rewrite(Invoice $invoice, array $lines, ?string $notes = null): Invoice
    {
        return $this->transaction(function () use ($invoice, $lines, $notes) {
            $locked = $this->lock($invoice);

            if ($locked->isCancelled()) {
                throw BillingConflict::because("Invoice {$locked->invoice_number} is cancelled and cannot be edited.");
            }

            if ((float) $locked->paid_amount > 0) {
                throw BillingConflict::because(
                    "Invoice {$locked->invoice_number} has a payment against it. Refund the payment before changing what was charged."
                );
            }

            $settings = BillingSetting::current();

            $priced = array_map(
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
                    ], $settings),
                ],
                $lines,
            );

            $totals = $this->pricing->totals($priced, $settings);

            // Old lines out, new lines in — inside the transaction, so the
            // invoice never reads mid-swap.
            $locked->items()->delete();

            foreach ($priced as $line) {
                InvoiceItem::create(['invoice_id' => $locked->id, ...$line]);
            }

            /*
             * The status is left exactly as it was. Rewriting a DRAFT's lines
             * must not finalize it — the visit may still be producing charges
             * — and rewriting a finalized one keeps it payable.
             */
            $locked->forceFill([
                ...$totals,
                'notes' => $notes ?? $locked->notes,
            ])->save();

            return $locked->load(['items', 'payments', 'customer']);
        });
    }

    /**
     * Append charges to a bill that is still collecting them.
     *
     * THE HEART OF CONSOLIDATION. A visit produces charges at different
     * moments — the doctor finishes, the lab signs off, the counter
     * dispenses — and each of those calls this rather than drawing an
     * invoice of its own. The patient is handed one document at the end.
     *
     * IDEMPOTENT BY SOURCE. A line naming a `source_type`/`source_id` this
     * invoice already carries is skipped rather than duplicated, so a lab
     * order completed, reopened and completed again does not charge twice.
     * The partial unique index on `(invoice_id, source_type, source_id)` is
     * the backstop underneath that.
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

            $added = 0;

            foreach ($lines as $line) {
                $key = ($line['source_type'] ?? '').':'.($line['source_id'] ?? '');

                if (($line['source_id'] ?? null) !== null && in_array($key, $already, true)) {
                    continue;
                }

                InvoiceItem::create([
                    'invoice_id' => $locked->id,
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
                    ], $settings),
                ]);

                $already[] = $key;
                $added++;
            }

            if ($added > 0) {
                $this->recalculate($locked, $settings);
            }

            return $locked->load(['items', 'payments', 'customer']);
        });
    }

    /**
     * Declare the bill complete — it may now be paid.
     *
     * Until this, a visit invoice is a running tab and the counter refuses
     * money against it. The organisation's billing trigger decides which
     * event calls this; `manual` leaves it to whoever is at the desk.
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

            $settings = BillingSetting::current();

            // Totals are recomputed here rather than trusted: lines may have
            // been added by several different events since the last sum.
            $this->recalculate($locked, $settings);

            $actor = Auth::guard('web')->user();

            $locked->forceFill([
                'status' => Invoice::STATUS_PENDING,
                'finalized_at' => now(),
                'finalized_by' => $actor instanceof User ? $actor->id : null,
            ])->save();

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
     * Re-sum an invoice from its own lines.
     *
     * Expects the row locked and inside a transaction. Every path that
     * changes what is on a bill ends here, so the header and the lines can
     * never disagree.
     */
    private function recalculate(Invoice $locked, BillingSetting $settings): void
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

        $locked->forceFill($this->pricing->totals($lines, $settings))->save();
    }

    /**
     * Take a payment against an invoice.
     *
     * The invoice's `paid_amount` and `payment_status` are recomputed from
     * the payments — never trusted from the request — so a double-click at
     * the till cannot mark a bill paid twice.
     *
     * @param  array{method: string, amount: float|int, reference?: string|null, notes?: string|null}  $payment
     */
    public function recordPayment(Invoice $invoice, array $payment): Invoice
    {
        return $this->transaction(function () use ($invoice, $payment) {
            $locked = $this->lock($invoice);

            if ($locked->isCancelled()) {
                throw BillingConflict::because("Invoice {$locked->invoice_number} is cancelled — nothing to pay.");
            }

            /*
             * A draft is a running tab, not a bill.
             *
             * Taking money against one is the exact failure consolidation
             * exists to prevent: the patient pays for the consultation, then
             * the lab result lands and they are asked again at the door.
             */
            if ($locked->isDraft()) {
                throw BillingConflict::because(
                    "Invoice {$locked->invoice_number} is still collecting charges for this visit. Finalize it before taking payment."
                );
            }

            $amount = round((float) $payment['amount'], 2);

            if ($amount <= 0) {
                throw BillingConflict::because('A payment has to be a positive amount.');
            }

            $outstanding = $locked->outstanding();

            if ($amount > $outstanding + 0.001) {
                throw BillingConflict::because(sprintf(
                    'That is more than the invoice owes. Outstanding is %.2f.',
                    $outstanding,
                ));
            }

            $settings = BillingSetting::current();

            if (! in_array($payment['method'], $settings->payment_methods, true)) {
                throw BillingConflict::because(sprintf(
                    'This clinic does not take %s. Change payment settings to add it.',
                    $payment['method'],
                ));
            }

            $actor = Auth::guard('web')->user();

            $record = InvoicePayment::create([
                'invoice_id' => $locked->id,
                'method' => $payment['method'],
                'amount' => $amount,
                'reference' => $payment['reference'] ?? null,
                'notes' => $payment['notes'] ?? null,
                'paid_at' => now(),
                'created_by' => $actor instanceof User ? $actor->id : null,
                'is_refund' => false,
            ]);

            $this->settle($locked);

            // Tell the visit the till has moved, so its own status column
            // stops reading `awaiting_payment` the moment the balance is nil.
            $this->refreshVisit($locked);

            /*
             * TWO EVENTS, NOT ONE.
             *
             * `payment_received` always fires — it is the fact that money
             * changed hands, and a clinic that receipts every payment wants
             * exactly this. The second says what the payment did to the bill,
             * because those are different pieces of paper: a clinic may want a
             * receipt for each instalment but a "settled in full" only once,
             * and one event carrying a flag would make that rule a condition
             * rather than a choice.
             *
             * Read from the invoice after settle() rather than computed from
             * the amount, so the answer matches what the row now says.
             */
            $settled = $locked->outstanding() <= 0.001;
            $meta = ['payment_id' => $record->id, 'method' => $record->method];

            $this->events->dispatch(
                ClinicEvent::for(ClinicEvents::PAYMENT_RECEIVED, $locked, $meta),
            );

            $this->events->dispatch(ClinicEvent::for(
                $settled
                    ? ClinicEvents::PAYMENT_FULLY_RECEIVED
                    : ClinicEvents::PAYMENT_PARTIALLY_RECEIVED,
                $locked,
                $meta,
            ));

            return $locked->load(['items', 'payments', 'customer']);
        });
    }

    /**
     * Refund a payment.
     *
     * The refund row carries the same amount as the payment it undoes (a
     * partial refund is a smaller row against the same original). Recomputes
     * paid_amount and status from the net.
     */
    public function refund(Invoice $invoice, InvoicePayment $payment, float $amount, ?string $reason = null): Invoice
    {
        return $this->transaction(function () use ($invoice, $payment, $amount, $reason) {
            $locked = $this->lock($invoice);

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

            $paidForThis = (float) $payment->amount;
            $alreadyRefunded = (float) InvoicePayment::query()
                ->where('refunds_payment_id', $payment->id)
                ->where('is_refund', true)
                ->sum('amount');

            $refundable = round($paidForThis - $alreadyRefunded, 2);

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
            ]);

            $this->settle($locked);

            // Same as recordPayment — a refund pushes the visit back into
            // `awaiting_payment` if it had briefly moved past.
            $this->refreshVisit($locked);

            /*
             * Money went back. Deliberately NOT also raising a payment event:
             * a refund is its own document (a credit note, a refund receipt),
             * and a clinic whose rule says "receipt on payment_received"
             * must not have one printed when it gives money back.
             */
            $this->events->dispatch(
                ClinicEvent::for(ClinicEvents::REFUND_PROCESSED, $locked, [
                    'refund_id' => $record->id,
                    'refunds_payment_id' => $payment->id,
                ]),
            );

            return $locked->load(['items', 'payments', 'customer']);
        });
    }

    /**
     * Cancel an invoice.
     *
     * Refused once fully paid — money that has been taken has to be refunded
     * before the bill can be closed as cancelled — but allowed while there is
     * only outstanding balance.
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
     * consultation, an appointment, whatever. The trigger resolver checks
     * this before drawing so completing a consultation twice does not draw
     * two invoices.
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
     * Recompute paid_amount and both status columns from the payments.
     *
     * Called inside every path that writes a payment or a refund. The row
     * has to be locked when this is called — every caller above satisfies
     * that.
     */
    private function settle(Invoice $locked): void
    {
        $net = (float) InvoicePayment::query()
            ->where('invoice_id', $locked->id)
            ->selectRaw("COALESCE(SUM(CASE WHEN is_refund THEN -amount ELSE amount END), 0) AS net")
            ->value('net');

        $net = round($net, 2);
        $total = (float) $locked->total_amount;

        $paymentStatus = match (true) {
            $net <= 0.001 => Invoice::UNPAID,
            $net >= $total - 0.001 => Invoice::PAID,
            default => Invoice::PARTIAL,
        };

        $status = match (true) {
            $locked->isCancelled() => Invoice::STATUS_CANCELLED,
            $paymentStatus === Invoice::PAID => Invoice::STATUS_PAID,
            $paymentStatus === Invoice::PARTIAL => Invoice::STATUS_PARTIALLY_PAID,
            default => Invoice::STATUS_PENDING,
        };

        $locked->forceFill([
            'paid_amount' => max(0.0, $net),
            'payment_status' => $paymentStatus,
            'status' => $status,
        ])->save();
    }

    /**
     * Tell the visit workflow that something on this invoice moved.
     *
     * A no-op for a manual invoice (no appointment) or when the workflow was
     * not injected — the second is only true in a test that spun up
     * BillingService directly.
     */
    private function refreshVisit(Invoice $locked): void
    {
        if (! $this->visits || ! $locked->appointment_id) {
            return;
        }

        $appointment = Appointment::query()->find($locked->appointment_id);

        if ($appointment) {
            $this->visits->refresh($appointment);
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
