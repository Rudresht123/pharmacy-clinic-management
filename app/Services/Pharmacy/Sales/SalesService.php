<?php

namespace App\Services\Pharmacy\Sales;

use App\Models\Tenant\Customer;
use App\Models\Tenant\Medicine;
use App\Models\Tenant\MedicineBatch;
use App\Models\Tenant\PharmacySale;
use App\Models\Tenant\PharmacySaleItem;
use App\Models\Tenant\PharmacySalePayment;
use App\Models\Tenant\PharmacySetting;
use App\Models\Tenant\PharmacyStore;
use App\Models\Tenant\StockMovement;
use App\Models\Tenant\User;
use App\Models\Tenant\Appointment;
use App\Services\Billing\BillingTriggerResolver;
use App\Services\Pharmacy\Inventory\Lots;
use App\Services\Pharmacy\Inventory\StockConflict;
use App\Services\Pharmacy\Inventory\StockMovementService;
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
 */
class SalesService
{
    public function __construct(
        private readonly StockMovementService $stock,
        private readonly SalePricing $pricing,
        private readonly Lots $lots,
        private readonly Dispensing $dispensing,
        /*
         * Nullable to keep older callers/tests working. In an HTTP request the
         * container populates it, and a dispensing tied to a visit — with the
         * organisation's trigger set to `pharmacy` — draws a clinic invoice
         * for the whole visit right after the sale row goes in.
         */
        private readonly ?BillingTriggerResolver $billingTrigger = null,
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

                $this->stock->record($batch, StockMovement::CORRECTION, $item->quantity, [
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

            $sale->forceFill([
                'status' => PharmacySale::CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $actor instanceof User ? $actor->id : null,
                'cancellation_reason' => $reason,
            ])->save();

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

        /*
         * A dispensing that belongs to a visit is not settled HERE.
         *
         * Its money goes onto the visit's consolidated invoice and is
         * collected at the billing counter, so the usual "this bill is not
         * paid in full and credit sales are off" refusal would be refusing a
         * sale that was never meant to take money. A plain counter sale — no
         * visit behind it — is still held to the settings exactly as before.
         */
        $billedOnTheVisit = $this->billingTrigger !== null
            && $prescription?->appointment_id !== null;

        if (! $billedOnTheVisit) {
            $this->assertSettled($paid, $totals['total_amount'], $customer, $settings);
        }

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

        /*
         * The clinic's side of the same act.
         *
         * A dispensing that belongs to a VISIT is billed on that visit's
         * consolidated invoice — consultation, lab and medicines on one
         * document the patient pays once. So the medicines go onto the
         * invoice and this sale stops owning the money: `billed_via_invoice`
         * is what stops the till asking for it as well, and what keeps the
         * pharmacy's own "still owed" report honest.
         *
         * A plain counter sale — no appointment, which is every sale a
         * standalone medical store makes — is untouched by all of this and
         * takes its money at the counter exactly as before.
         *
         * Inside the same transaction: a dispensed strip that failed to
         * reach a bill is the disagreement this whole path exists to
         * prevent.
         */
        if ($this->billingTrigger && $sale->appointment_id) {
            $appointment = Appointment::query()->find($sale->appointment_id);

            if ($appointment) {
                $invoice = $this->billingTrigger->onPharmacyDispensed($appointment);

                /*
                 * Only once the invoice actually took the charge. If billing
                 * is switched off at this branch the resolver returns null,
                 * and the sale keeps its own payment status — the counter is
                 * still the till.
                 */
                if ($invoice !== null) {
                    $sale->forceFill([
                        'paid_amount' => 0,
                        'payment_status' => PharmacySale::BILLED_VIA_INVOICE,
                    ])->save();
                }
            }
        }

        return $sale->load(['items.medicine', 'payments', 'customer', 'store']);
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
