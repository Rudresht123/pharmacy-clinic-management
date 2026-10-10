<?php

namespace App\Services\Pharmacy\Sales;

use App\Models\Tenant\Appointment;
use App\Models\Tenant\BillingSetting;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Medicine;
use App\Models\Tenant\MedicineBatch;
use App\Models\Tenant\PharmacySale;
use App\Models\Tenant\PharmacySaleItem;
use App\Models\Tenant\PharmacySalePayment;
use App\Models\Tenant\PharmacySaleReturn;
use App\Models\Tenant\PharmacySaleReturnItem;
use App\Models\Tenant\PharmacySetting;
use App\Models\Tenant\PharmacyStore;
use App\Models\Tenant\StockMovement;
use App\Models\Tenant\User;
use App\Services\Clinic\ClinicEvent;
use App\Services\Clinic\ClinicEventDispatcher;
use App\Services\Opd\VisitWorkflow;
use App\Services\Pharmacy\Inventory\Lots;
use App\Services\Pharmacy\Inventory\StockConflict;
use App\Services\Pharmacy\Inventory\StockMovementService;
use App\Support\Clinic\ClinicEvents;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;

/**
 * Selling, in one transaction.
 *
 * The same path for both ways the pharmacy sells: a counter sale is this
 * with no prescription behind it, a dispensing is this carrying one. There
 * is deliberately no second path that moves stock without making a bill —
 * that is how a till and a shelf come to disagree.
 *
 * The order of work is the order that keeps it safe:
 *
 *   1. read what is being asked for, and which batches could fill it;
 *   2. LOCK every candidate batch, in id order, so two counters selling the
 *      last strip queue rather than both succeed;
 *   3. only then decide which batch fills which line (FEFO), with the
 *      quantities as they are under the lock;
 *   4. write the bill, its lines, its payments and one ledger row per line.
 *
 * Nothing below trusts a quantity read before the lock. Postgres backs the
 * whole thing up: stock cannot go negative, and a ledger row's before and
 * after must agree with what it moved.
 *
 * THE PHARMACY'S OWN TILL. A sale is settled here, at the counter, whether
 * or not a visit is behind it — its money is never moved onto the OPD's or
 * the lab's invoice. Money comes in when the bill is made or later against a
 * bill left on credit (collect), and goes back when a bill is cancelled or
 * goods are returned; each movement is its own payment row, so the day's
 * takings are counted from payment rows alone, once.
 */
class SalesService
{
    public function __construct(
        private readonly StockMovementService $stock,
        private readonly SalePricing $pricing,
        private readonly Lots $lots,
        private readonly Dispensing $dispensing,
        /* Raised after commit — see ClinicEventDispatcher. */
        private readonly ClinicEventDispatcher $events,
    ) {}

    /**
     * Ring up a sale.
     *
     * The idempotency key makes a double-tap at the counter — or a retried
     * request on a bad connection — answer with the bill the first one made,
     * rather than selling the same strip twice.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: PharmacySale, 1: bool} the bill, and whether this call made it
     */
    public function sell(PharmacyStore $store, array $data, string $key): array
    {
        if ($existing = $this->byKey($key)) {
            return [$existing, false];
        }

        try {
            $sale = $this->transaction(fn () => $this->ring($store, $data, $key));
        } catch (UniqueConstraintViolationException) {
            if ($existing = $this->byKey($key)) {
                return [$existing, false];
            }

            throw StockConflict::because('That sale was already being saved. Check the bill list before selling again.');
        }

        /*
         * IDEMPOTENCY COMES FREE HERE, and is the reason this sits outside the
         * try rather than inside ring().
         *
         * Both early returns above hand back a sale a previous call already
         * made, and neither reaches this line — so a double-tapped counter
         * raises one event, not two, without any new bookkeeping. This is the
         * mechanism the rest of the events will borrow when Step 3 puts a
         * uniqueness constraint on generated documents.
         *
         * The dispatcher registers after-commit, and by here the transaction
         * has already committed, so it runs immediately.
         */
        $this->events->dispatch(
            ClinicEvent::for(ClinicEvents::PHARMACY_SALE_COMPLETED, $sale, [
                'store_id' => $store->getKey(),
            ]),
        );

        return [$sale, true];
    }

    /**
     * Cancel a bill: everything on it goes back on the shelf as correcting
     * ledger rows pointing at the movements they undo.
     *
     * Refused once a batch has been emptied and refilled past what the bill
     * took — the stock that came back is not the stock that left — and
     * refused on a bill already cancelled.
     */
    public function cancel(PharmacySale $sale, string $reason): PharmacySale
    {
        return $this->transaction(function () use ($sale, $reason) {
            $sale = PharmacySale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();

            if ($sale->isCancelled()) {
                throw StockConflict::because("{$sale->sale_number} is already cancelled.");
            }

            $items = $sale->items()->orderBy('id')->get();
            $batches = $this->stock->lock($items->pluck('medicine_batch_id')->all());

            foreach ($items as $item) {
                $batch = $batches[$item->medicine_batch_id];

                // What already came back over the counter is not on the bill to cancel.
                $remaining = (int) $item->quantity - (int) $item->returned_quantity;

                if ($remaining <= 0) {
                    continue;
                }

                $this->stock->record($batch, StockMovement::CORRECTION, $remaining, [
                    'reference_type' => 'pharmacy_sale_item',
                    'reference_id' => $item->id,
                    'reverses_movement_id' => StockMovement::query()
                        ->where('reference_type', 'pharmacy_sale_item')
                        ->where('reference_id', $item->id)
                        ->orderBy('id')
                        ->value('id'),
                    'reason' => mb_substr("Cancelled {$sale->sale_number}: {$reason}", 0, 500),
                ]);
            }

            $actor = Auth::guard('web')->user();

            /*
             * The money goes back with the goods, and the till says so.
             *
             * Each payment still held is refunded by the method it came in
             * by, dated now — the moment the cash actually left the drawer.
             * Without these rows a cancelled bill's money simply vanished
             * from the record, and a day's takings could only be counted by
             * guessing which cancelled bills had been paid.
             */
            $this->giveBack($sale, round((float) $sale->paid_amount, 2), null, null, "Cancelled: {$reason}");

            $sale->forceFill([
                'status' => PharmacySale::CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $actor instanceof User ? $actor->id : null,
                'cancellation_reason' => $reason,
            ])->save();

            $this->restate($sale);

            /*
             * The prescription's side of the undo.
             *
             * The stock has just gone back on the shelf as correcting ledger
             * rows; without this the prescription would still read
             * `dispensed` while the patient has nothing, and the visit would
             * stay closed on the strength of a bill that no longer exists.
             */
            if ($prescription = $this->dispensing->lockForReversal($sale->prescription_id)) {
                $this->dispensing->reverse($prescription, $items);
            }

            return $sale->load(['items.medicine', 'payments', 'customer', 'store']);
        });
    }

    /** @param  array<string, mixed>  $data */
    private function ring(PharmacyStore $store, array $data, string $key): PharmacySale
    {
        $this->lots->assertOperational($store);

        $settings = PharmacySetting::current();

        $customer = $this->buyer($data, $settings);

        $medicines = Medicine::query()
            ->whereIn('id', array_column($data['items'], 'medicine_id'))
            ->get()
            ->keyBy('id');

        $this->assertSellable($medicines, $data, $settings);

        // Locked before a single quantity is read from them.
        $locked = $this->stock->lock($this->candidateBatchIds($store, $data, $medicines));

        /*
         * The prescription, if this is a dispensing, locked alongside the
         * batches and for the same reason: two counters working the same
         * prescription have to queue, or both will credit the same lines.
         *
         * Null for an ordinary counter sale, and every branch below reads as
         * it always did.
         */
        $prescription = $this->dispensing->lock(
            ! empty($data['prescription_id']) ? (int) $data['prescription_id'] : null
        );

        $lines = [];
        $claimed = [];

        foreach ($data['items'] as $input) {
            $medicine = $medicines[(int) $input['medicine_id']];

            /*
             * Which prescribed line this fulfils, decided once per REQUESTED
             * line rather than per allocation: 20 tablets filled from two
             * batches is two bill lines answering one prescribed line, and
             * matching twice would let the second allocation claim a
             * different line of the same medicine.
             */
            $fulfils = $prescription
                ? $this->dispensing->match($prescription, $input, $claimed)
                : null;

            if ($fulfils !== null) {
                $claimed[] = $fulfils;
            }

            foreach ($this->allocate($store, $medicine, $input, $locked) as [$batch, $quantity]) {
                $lines[] = [
                    ...$this->pricing->line(
                        [...$input, 'quantity' => $quantity],
                        $batch,
                        $medicine,
                        $settings,
                    ),
                    'prescription_item_id' => $fulfils,
                ];
            }
        }

        $totals = $this->pricing->totals($lines, $settings);
        $paid = round(array_sum(array_map(fn (array $p) => (float) $p['amount'], $data['payments'] ?? [])), 2);

        $this->assertTenders($data['payments'] ?? []);

        /*
         * Settled HERE, visit or no visit. A dispensing for an OPD patient is
         * paid at this counter under the pharmacy's own credit setting,
         * exactly like a walk-in's — its money never moves onto the OPD's
         * invoice, so the patient is never asked for it twice.
         */
        $this->assertSettled($paid, $totals['total_amount'], $customer, $settings);

        $actor = Auth::guard('web')->user();

        $sale = PharmacySale::create([
            'pharmacy_store_id' => $store->id,
            'location_id' => $store->location_id,
            'customer_id' => $customer?->id,
            'walk_in_name' => $customer ? null : ($data['walk_in_name'] ?? 'Walk-in customer'),
            'walk_in_phone' => $customer ? null : ($data['walk_in_phone'] ?? null),
            'prescription_id' => $data['prescription_id'] ?? null,

            /*
             * Which visit this bill belongs to.
             *
             * Taken from the prescription rather than the request: the client
             * has no business asserting which visit it is billing, and the
             * prescription already knows. Null for a counter sale, which is
             * every sale a standalone medical store makes.
             */
            'appointment_id' => $prescription?->appointment_id,

            'doctor_id' => $data['doctor_id'] ?? null,
            'sale_date' => now(),
            // How this bill was priced, as the settings were at the time.
            'price_basis' => $settings->price_basis,
            'prices_include_tax' => $settings->prices_include_tax,
            ...$totals,
            'paid_amount' => $paid,
            'payment_status' => $this->paymentStatus($paid, $totals['total_amount']),
            'notes' => $data['notes'] ?? null,
            'idempotency_key' => $key,
            'created_by' => $actor instanceof User ? $actor->id : null,
            'created_by_name' => $actor instanceof User ? $actor->name : null,
        ]);

        // The bill number is the database's, taken as the row went in.
        $sale->refresh();

        foreach ($lines as $line) {
            $item = PharmacySaleItem::create(['pharmacy_sale_id' => $sale->id, ...$line]);

            $batch = $locked[$line['medicine_batch_id']];
            $batch->setRelation('store', $store);

            $this->stock->record($batch, StockMovement::SALE, -$line['quantity'], [
                'reference_type' => 'pharmacy_sale_item',
                'reference_id' => $item->id,
                'unit_cost' => $line['unit_cost'],
                'notes' => $sale->sale_number,
            ]);
        }

        foreach ($data['payments'] ?? [] as $payment) {
            PharmacySalePayment::create([
                'pharmacy_sale_id' => $sale->id,
                'method' => $payment['method'],
                'amount' => round((float) $payment['amount'], 2),
                'reference' => $payment['reference'] ?? null,
                'paid_at' => now(),
                'created_by' => $actor instanceof User ? $actor->id : null,
            ]);
        }

        /*
         * The prescription's side of the same act, and then the visit's.
         *
         * Last, inside the same transaction: the lines have to exist before
         * anything can be credited against them, and if crediting fails —
         * over-dispensing, say — the stock movement rolls back with it. A
         * bill that took stock off the shelf without moving the prescription
         * is the disagreement this whole path exists to prevent.
         */
        if ($prescription) {
            $this->dispensing->apply($prescription, $sale->items()->get());
        }

        return $sale->load(['items.medicine', 'payments', 'customer', 'store']);
    }

    /**
     * Take money against a bill left owing — the rest of a credit sale.
     *
     * At the pharmacy's own counter, under its own rule: with credit sales
     * on, any part of what is owed may be taken; with them off, what is taken
     * has to clear it. Idempotent by key: a double-tapped "collect" answers
     * with the bill as the first one left it.
     *
     * @param  list<array{method: string, amount: float|int, reference?: string|null}>  $tenders
     * @return array{0: PharmacySale, 1: bool} the bill, and whether this call recorded anything
     */
    public function collect(PharmacySale $sale, array $tenders, string $key): array
    {
        if ($this->paymentByKey($key, $sale)) {
            return [$this->fresh($sale), false];
        }

        try {
            return $this->transaction(function () use ($sale, $tenders, $key) {
                $locked = $this->lockSale($sale);

                if ($this->paymentByKey($key, $locked)) {
                    return [$this->fresh($locked), false];
                }

                if ($locked->isCancelled()) {
                    throw StockConflict::because("{$locked->sale_number} is cancelled — nothing to collect.");
                }

                if ($locked->payment_status === PharmacySale::BILLED_VIA_INVOICE) {
                    throw StockConflict::because("{$locked->sale_number} is collected on its visit's invoice, not at this counter.");
                }

                if ($tenders === []) {
                    throw StockConflict::because('Say how much was paid, and how.');
                }

                $this->assertTenders($tenders);

                $total = round(array_sum(array_map(fn (array $t) => (float) $t['amount'], $tenders)), 2);
                $due = $locked->amountDue();

                if ($due <= 0.001) {
                    throw StockConflict::because("{$locked->sale_number} is already paid.");
                }

                if ($total > $due + 0.001) {
                    throw StockConflict::because(sprintf('That is more than the bill owes. Due is %.2f.', $due));
                }

                if (! PharmacySetting::current()->credit_sales_enabled && $total < $due - 0.001) {
                    throw StockConflict::because(sprintf(
                        'Credit sales are switched off in pharmacy settings, so take the whole %.2f.',
                        $due,
                    ));
                }

                $actor = Auth::guard('web')->user();

                foreach (array_values($tenders) as $index => $tender) {
                    PharmacySalePayment::create([
                        'pharmacy_sale_id' => $locked->id,
                        'method' => $tender['method'],
                        'amount' => round((float) $tender['amount'], 2),
                        'reference' => $tender['reference'] ?? null,
                        'paid_at' => now(),
                        'created_by' => $actor instanceof User ? $actor->id : null,
                        'idempotency_key' => $index === 0 ? $key : "{$key}#{$index}",
                    ]);
                }

                $this->restate($locked);

                // A visit held open by this debt can close now.
                $this->tellTheVisit($locked);

                return [$this->fresh($locked), true];
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($this->paymentByKey($key, $sale)) {
                return [$this->fresh($sale), false];
            }

            throw $e;
        }
    }

    /**
     * Take goods back against a bill — some of a line, or all of it.
     *
     * The stock goes back on the shelf as `sale_return` movements, each
     * line's `returned_quantity` and the bill's `returned_amount` grow, and a
     * return document records what came back. Money is handed back only for
     * what had been paid over what the bill now comes to — a credit sale
     * that has not been paid gets its debt reduced instead — and that refund
     * is a payment row going the other way.
     *
     * The prescription is left as it was: the dispensing happened, and the
     * return is a counter transaction after it.
     *
     * @param  list<array{pharmacy_sale_item_id: int, quantity: int}>  $lines
     * @param  array{method?: string|null, reference?: string|null}  $refund
     * @return array{0: PharmacySale, 1: bool} the bill, and whether this call took anything back
     */
    public function returnItems(PharmacySale $sale, array $lines, array $refund, string $reason, string $key): array
    {
        if (PharmacySaleReturn::query()->where('idempotency_key', $key)->exists()) {
            return [$this->fresh($sale), false];
        }

        try {
            return $this->transaction(function () use ($sale, $lines, $refund, $reason, $key) {
                $locked = $this->lockSale($sale);

                if (PharmacySaleReturn::query()->where('idempotency_key', $key)->exists()) {
                    return [$this->fresh($locked), false];
                }

                if ($locked->isCancelled()) {
                    throw StockConflict::because("{$locked->sale_number} is cancelled — its stock already went back.");
                }

                if ($locked->payment_status === PharmacySale::BILLED_VIA_INVOICE) {
                    throw StockConflict::because("{$locked->sale_number} was billed on its visit's invoice; return it from there.");
                }

                if ($lines === []) {
                    throw StockConflict::because('Say what is coming back.');
                }

                $items = PharmacySaleItem::query()
                    ->where('pharmacy_sale_id', $locked->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $asked = [];

                foreach ($lines as $line) {
                    $id = (int) $line['pharmacy_sale_item_id'];
                    $asked[$id] = ($asked[$id] ?? 0) + (int) $line['quantity'];
                }

                foreach ($asked as $id => $quantity) {
                    $item = $items->get($id) ?? throw StockConflict::because('That line is not on this bill.');
                    $left = (int) $item->quantity - (int) $item->returned_quantity;

                    if ($quantity <= 0 || $quantity > $left) {
                        throw StockConflict::because(sprintf(
                            '%s: %d can still come back, %d was asked for.',
                            $item->item_name_snapshot,
                            $left,
                            $quantity,
                        ));
                    }
                }

                $batches = $this->stock->lock(array_map(
                    fn (int $id) => (int) $items[$id]->medicine_batch_id,
                    array_keys($asked),
                ));

                $alreadyReturned = round((float) $locked->returned_amount, 2);
                $room = round((float) $locked->total_amount - $alreadyReturned, 2);

                // Everything still on the bill coming back: the round-off goes with it.
                $everything = $items->every(
                    fn (PharmacySaleItem $item) => ((int) $item->quantity - (int) $item->returned_quantity) === ($asked[$item->id] ?? 0),
                );

                $valued = [];

                foreach ($asked as $id => $quantity) {
                    $item = $items[$id];
                    $share = $quantity / (int) $item->quantity;

                    $valued[$id] = [
                        'taxable_amount' => round((float) $item->taxable_amount * $share, 2),
                        'tax_amount' => round((float) $item->tax_amount * $share, 2),
                        'line_total' => round((float) $item->line_total * $share, 2),
                    ];
                }

                $value = round(array_sum(array_column($valued, 'line_total')), 2);
                $value = $everything ? $room : min($value, $room);

                if ($value <= 0) {
                    throw StockConflict::because('Nothing on that return is worth anything — check the quantities.');
                }

                $actor = Auth::guard('web')->user();

                $return = PharmacySaleReturn::create([
                    'pharmacy_sale_id' => $locked->id,
                    'pharmacy_store_id' => $locked->pharmacy_store_id,
                    'location_id' => $locked->location_id,
                    'return_date' => now(),
                    'reason' => mb_substr($reason, 0, 500),
                    'taxable_amount' => round(array_sum(array_column($valued, 'taxable_amount')), 2),
                    'tax_amount' => round(array_sum(array_column($valued, 'tax_amount')), 2),
                    'total_amount' => $value,
                    'refund_amount' => 0,
                    'idempotency_key' => $key,
                    'created_by' => $actor instanceof User ? $actor->id : null,
                    'created_by_name' => $actor instanceof User ? $actor->name : null,
                ]);

                // The number is the database's, taken as the row went in.
                $return->refresh();

                $store = $locked->store()->firstOrFail();

                foreach ($asked as $id => $quantity) {
                    $item = $items[$id];

                    $returned = PharmacySaleReturnItem::create([
                        'pharmacy_sale_return_id' => $return->id,
                        'pharmacy_sale_item_id' => $item->id,
                        'medicine_batch_id' => $item->medicine_batch_id,
                        'quantity' => $quantity,
                        ...$valued[$id],
                    ]);

                    $batch = $batches[$item->medicine_batch_id];
                    $batch->setRelation('store', $store);

                    $this->stock->record($batch, StockMovement::SALE_RETURN, $quantity, [
                        'reference_type' => 'pharmacy_sale_return_item',
                        'reference_id' => $returned->id,
                        'unit_cost' => $item->unit_cost,
                        'reason' => mb_substr("Returned on {$return->return_number}: {$reason}", 0, 500),
                        'notes' => $locked->sale_number,
                    ]);

                    $item->forceFill(['returned_quantity' => (int) $item->returned_quantity + $quantity])->save();
                }

                $locked->forceFill(['returned_amount' => round($alreadyReturned + $value, 2)])->save();

                /*
                 * Money back only for what was paid over what the bill now
                 * comes to. A credit sale nobody has paid simply owes less.
                 */
                $over = round((float) $locked->paid_amount - ((float) $locked->total_amount - (float) $locked->returned_amount), 2);

                if ($over > 0.001) {
                    if (! empty($refund['method'])) {
                        $this->assertTenders([['method' => $refund['method']]]);
                    }

                    $this->giveBack($locked, $over, $refund['method'] ?? null, $return, "Returned on {$return->return_number}: {$reason}", $refund['reference'] ?? null);

                    $return->forceFill(['refund_amount' => $over])->save();
                }

                $this->restate($locked);
                $this->tellTheVisit($locked);

                return [$this->fresh($locked), true];
            });
        } catch (UniqueConstraintViolationException $e) {
            if (PharmacySaleReturn::query()->where('idempotency_key', $key)->exists()) {
                return [$this->fresh($sale), false];
            }

            throw $e;
        }
    }

    /**
     * Hand money back against a bill, newest payment first.
     *
     * One refund row per payment it comes out of, each pointing at that
     * payment — so "how much of this payment is left to give back" stays
     * answerable — in the method asked for, or the payment's own.
     */
    private function giveBack(
        PharmacySale $locked,
        float $amount,
        ?string $method,
        ?PharmacySaleReturn $return,
        string $note,
        ?string $reference = null,
    ): void {
        $left = round($amount, 2);

        if ($left <= 0.001) {
            return;
        }

        $payments = PharmacySalePayment::query()
            ->where('pharmacy_sale_id', $locked->id)
            ->where('is_refund', false)
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->get();

        $actor = Auth::guard('web')->user();

        foreach ($payments as $payment) {
            if ($left <= 0.001) {
                break;
            }

            $alreadyBack = (float) PharmacySalePayment::query()
                ->where('refunds_payment_id', $payment->id)
                ->sum('amount');

            $available = round((float) $payment->amount - $alreadyBack, 2);

            if ($available <= 0.001) {
                continue;
            }

            $portion = min($available, $left);

            PharmacySalePayment::create([
                'pharmacy_sale_id' => $locked->id,
                'method' => $method ?: $payment->method,
                'amount' => $portion,
                'is_refund' => true,
                'refunds_payment_id' => $payment->id,
                'pharmacy_sale_return_id' => $return?->id,
                'reference' => $reference,
                'notes' => mb_substr($note, 0, 500),
                'paid_at' => now(),
                'created_by' => $actor instanceof User ? $actor->id : null,
            ]);

            $left = round($left - $portion, 2);
        }
    }

    /**
     * Recompute what a bill holds and its payment status from its rows.
     *
     * The same rule the clinic's invoices follow: never trusted from a
     * request, always summed from the payments, refunds counting against.
     */
    private function restate(PharmacySale $locked): void
    {
        if ($locked->payment_status === PharmacySale::BILLED_VIA_INVOICE) {
            return;
        }

        $held = round(max(0.0, (float) PharmacySalePayment::query()
            ->where('pharmacy_sale_id', $locked->id)
            ->selectRaw('COALESCE(SUM('.PharmacySalePayment::NET_SQL.'), 0) AS held')
            ->value('held')), 2);

        $locked->forceFill([
            'paid_amount' => $held,
            'payment_status' => $locked->isCancelled()
                ? ($held > 0.001 ? PharmacySale::PARTIAL : PharmacySale::UNPAID)
                : $this->paymentStatus($held, (float) $locked->total_amount - (float) $locked->returned_amount),
        ])->save();
    }

    /** Ask the visit, if this bill belongs to one, whether it is finished now. */
    private function tellTheVisit(PharmacySale $sale): void
    {
        if (! $sale->appointment_id) {
            return;
        }

        $appointment = Appointment::query()->find($sale->appointment_id);

        if ($appointment) {
            app(VisitWorkflow::class)->refresh($appointment);
        }
    }

    /**
     * Every tender has to be one the organisation accepts.
     *
     * One list for every counter — Billing settings' accepted methods — so a
     * clinic that does not take cheques does not take them at the pharmacy
     * either. Credit is not a tender at all: it is a bill left owing, and
     * whether that is allowed is the pharmacy's credit setting.
     *
     * @param  list<array{method: string}>  $tenders
     */
    private function assertTenders(array $tenders): void
    {
        $settings = BillingSetting::current();

        foreach ($tenders as $tender) {
            $method = (string) ($tender['method'] ?? '');

            if (! $settings->accepts($method)) {
                throw StockConflict::because(sprintf(
                    'This organisation does not take %s. Billing settings decide which payment methods every counter accepts.',
                    str_replace('_', ' ', $method),
                ));
            }
        }
    }

    private function paymentByKey(string $key, PharmacySale $sale): bool
    {
        $seen = PharmacySalePayment::query()->where('idempotency_key', $key)->first();

        if ($seen && (int) $seen->pharmacy_sale_id !== (int) $sale->id) {
            throw StockConflict::because('That request was already used for a different bill. Reload and try again.');
        }

        return $seen !== null;
    }

    private function lockSale(PharmacySale $sale): PharmacySale
    {
        return PharmacySale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
    }

    private function fresh(PharmacySale $sale): PharmacySale
    {
        return PharmacySale::query()
            ->with(['items.medicine', 'payments', 'customer', 'store', 'returns.items'])
            ->findOrFail($sale->id);
    }

    /**
     * Which batches could fill this sale, so all of them can be locked at once.
     *
     * A line naming its own batch contributes that one; a line leaving the
     * choice to the counter contributes every batch that could fill it,
     * because which one does is only decided under the lock.
     *
     * @param  array<string, mixed>  $data
     * @param  Collection<int, Medicine>  $medicines
     * @return list<int>
     */
    private function candidateBatchIds(PharmacyStore $store, array $data, Collection $medicines): array
    {
        $ids = [];

        foreach ($data['items'] as $input) {
            if (! empty($input['medicine_batch_id'])) {
                $ids[] = (int) $input['medicine_batch_id'];

                continue;
            }

            $medicine = $medicines[(int) $input['medicine_id']];

            $ids = [
                ...$ids,
                ...MedicineBatch::query()
                    ->availableForDispensing($store->id, $medicine->id)
                    ->pluck('id')
                    ->all(),
            ];
        }

        return $ids;
    }

    /**
     * Which batch fills how much of a line — first expiry first out.
     *
     * The stock closest to expiry leaves first, so what is left on the shelf
     * is always the longest-lived. A line can be filled from more than one
     * batch, and becomes one bill line per batch: a customer buying 20 when
     * the oldest batch holds 12 gets 12 and 8, each with its own batch
     * number and expiry printed.
     *
     * @param  array<string, mixed>  $input
     * @param  Collection<int, MedicineBatch>  $locked
     * @return list<array{0: MedicineBatch, 1: int}>
     */
    private function allocate(PharmacyStore $store, Medicine $medicine, array $input, Collection $locked): array
    {
        $wanted = (int) $input['quantity'];

        if (! empty($input['medicine_batch_id'])) {
            $batch = $locked[(int) $input['medicine_batch_id']] ?? null;

            if (! $batch || $batch->pharmacy_store_id !== $store->id || $batch->medicine_id !== $medicine->id) {
                throw StockConflict::because("That batch is not {$medicine->displayName()} in this store.");
            }

            $this->assertUsable($batch, $medicine);

            if ($batch->quantity_available < $wanted) {
                throw StockConflict::insufficient($batch, $wanted);
            }

            return [[$batch, $wanted]];
        }

        $usable = $locked
            ->filter(fn (MedicineBatch $batch) => $batch->pharmacy_store_id === $store->id
                && $batch->medicine_id === $medicine->id
                && $batch->isUsable()
                && $batch->quantity_available > 0)
            // First expiry first out; the older row breaks a tie.
            ->sortBy([['expiry_date', 'asc'], ['id', 'asc']]);

        $allocation = [];
        $left = $wanted;

        foreach ($usable as $batch) {
            if ($left <= 0) {
                break;
            }

            $take = min($left, (int) $batch->quantity_available);
            $allocation[] = [$batch, $take];
            $left -= $take;
        }

        if ($left > 0) {
            throw StockConflict::because(sprintf(
                '%s: %d in stock, %d asked for.',
                $medicine->displayName(),
                $wanted - $left,
                $wanted,
            ));
        }

        return $allocation;
    }

    /** Expired stock is never sold, whatever else is true of it. */
    private function assertUsable(MedicineBatch $batch, Medicine $medicine): void
    {
        if ($batch->isPastExpiry()) {
            throw StockConflict::because(sprintf(
                'Batch %s of %s expired on %s and cannot be sold.',
                $batch->batch_number,
                $medicine->displayName(),
                $batch->expiry_date->format('d M Y'),
            ));
        }

        if (! $batch->isUsable()) {
            throw StockConflict::because(
                "Batch {$batch->batch_number} of {$medicine->displayName()} is {$batch->status}, so it cannot be sold."
            );
        }
    }

    /**
     * Who is buying, and whether this organisation sells to them at all.
     *
     * @param  array<string, mixed>  $data
     */
    private function buyer(array $data, PharmacySetting $settings): ?Customer
    {
        if (! empty($data['customer_id'])) {
            return Customer::query()->findOrFail((int) $data['customer_id']);
        }

        if (! $settings->allow_walk_in) {
            throw StockConflict::because('Every sale has to name a customer. Register them, or allow walk-in sales in pharmacy settings.');
        }

        return null;
    }

    /**
     * Prescription-only medicines, where the organisation enforces it.
     *
     * @param  Collection<int, Medicine>  $medicines
     * @param  array<string, mixed>  $data
     */
    private function assertSellable(Collection $medicines, array $data, PharmacySetting $settings): void
    {
        if (! $settings->require_prescription || ! empty($data['prescription_id'])) {
            return;
        }

        $needs = $medicines->filter(fn (Medicine $medicine) => $medicine->prescription_required);

        if ($needs->isNotEmpty()) {
            throw StockConflict::because(sprintf(
                '%s %s a prescription. Attach one, or sell what does not.',
                $needs->map(fn (Medicine $medicine) => $medicine->displayName())->implode(', '),
                $needs->count() === 1 ? 'needs' : 'need',
            ));
        }
    }

    /** What is left unpaid is owed by somebody the store can ask. */
    private function assertSettled(float $paid, float $total, ?Customer $customer, PharmacySetting $settings): void
    {
        if ($paid > $total + 0.001) {
            throw StockConflict::because('That is more than the bill comes to. Take the bill amount, and hand back the change.');
        }

        if ($paid >= $total - 0.001) {
            return;
        }

        if (! $settings->credit_sales_enabled) {
            throw StockConflict::because('This bill is not paid in full, and credit sales are switched off in pharmacy settings.');
        }

        if (! $customer) {
            throw StockConflict::because('A bill left unpaid has to be owed by a registered customer, not a walk-in.');
        }
    }

    private function paymentStatus(float $paid, float $total): string
    {
        if ($paid >= $total - 0.001) {
            return PharmacySale::PAID;
        }

        return $paid > 0 ? PharmacySale::PARTIAL : PharmacySale::UNPAID;
    }

    private function byKey(string $key): ?PharmacySale
    {
        return PharmacySale::query()
            ->with(['items.medicine', 'payments', 'customer', 'store'])
            ->where('idempotency_key', $key)
            ->first();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function transaction(callable $callback): mixed
    {
        return (new PharmacySale)->getConnection()->transaction($callback);
    }
}
