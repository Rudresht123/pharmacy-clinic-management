<?php

namespace App\Services\Pharmacy\Inventory;

use App\Models\Tenant\Medicine;
use App\Models\Tenant\MedicineBatch;
use App\Models\Tenant\PharmacyStore;
use App\Models\Tenant\StockInward;
use App\Models\Tenant\StockInwardItem;
use App\Models\Tenant\StockMovement;
use App\Models\Tenant\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;

/**
 * Goods received: stock in, one ledger row per line.
 *
 * Lines arrive as the supplier invoices them — packs, and prices per pack —
 * and are stored in base units with prices per base unit. A strip of 10 at
 * ₹35 MRP is 10 tablets at ₹3.50; the rounding is to the paisa.
 *
 * A line whose batch number is already in the store tops that batch up, if
 * its expiry and prices agree (Lots). Otherwise a new batch is created.
 */
class StockInwardService
{
    public function __construct(
        private readonly StockMovementService $stock,
        private readonly Lots $lots,
    ) {}

    /**
     * Post a goods received note.
     *
     * The idempotency key makes a double-tap or a retried request return the
     * note the first one created, rather than receiving the goods twice.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: StockInward, 1: bool} the note, and whether this call created it
     */
    public function receive(PharmacyStore $store, array $data, string $key): array
    {
        if ($existing = $this->byKey($key)) {
            return [$existing, false];
        }

        try {
            $inward = $this->transaction(fn () => $this->post($store, $data, $key));
        } catch (UniqueConstraintViolationException $e) {
            if ($existing = $this->byKey($key)) {
                return [$existing, false];
            }

            // A batch number another receipt created at the same moment.
            throw StockConflict::because('Another receipt added one of these batches at the same moment. Check the stock and try again.');
        }

        return [$inward, true];
    }

    /**
     * Cancel a note: every line's stock leaves again, as correcting movements
     * pointing at the ones they undo. Refused once any of that stock has been
     * used — a pharmacist adjusts what remains instead.
     */
    public function cancel(StockInward $inward, string $reason): StockInward
    {
        return $this->transaction(function () use ($inward, $reason) {
            $inward = StockInward::query()->whereKey($inward->id)->lockForUpdate()->firstOrFail();

            if ($inward->status === StockInward::CANCELLED) {
                throw StockConflict::because("{$inward->inward_number} is already cancelled.");
            }

            $items = $inward->items()->orderBy('id')->get();
            $batches = $this->stock->lock($items->pluck('medicine_batch_id')->all());

            foreach ($items as $item) {
                $batch = $batches[$item->medicine_batch_id];
                $units = $item->quantity + $item->free_quantity;

                if ($batch->quantity_available < $units) {
                    throw StockConflict::because(sprintf(
                        '%d of the %d units received into batch %s have already left the store, so %s can no longer be cancelled. Adjust the remaining stock instead.',
                        $units - $batch->quantity_available,
                        $units,
                        $batch->batch_number,
                        $inward->inward_number,
                    ));
                }
            }

            foreach ($items as $item) {
                $this->stock->record(
                    $batches[$item->medicine_batch_id],
                    StockMovement::CORRECTION,
                    -($item->quantity + $item->free_quantity),
                    [
                        'reference_type' => 'stock_inward_item',
                        'reference_id' => $item->id,
                        'reverses_movement_id' => StockMovement::query()
                            ->where('reference_type', 'stock_inward_item')
                            ->where('reference_id', $item->id)
                            ->orderBy('id')
                            ->value('id'),
                        'reason' => mb_substr("Cancelled {$inward->inward_number}: {$reason}", 0, 500),
                    ],
                );
            }

            $actor = Auth::guard('web')->user();

            $inward->forceFill([
                'status' => StockInward::CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $actor instanceof User ? $actor->id : null,
                'cancellation_reason' => $reason,
            ])->save();

            return $inward;
        });
    }

    /** @param  array<string, mixed>  $data */
    private function post(PharmacyStore $store, array $data, string $key): StockInward
    {
        $this->lots->assertOperational($store);

        $medicines = Medicine::query()
            ->whereIn('id', array_column($data['items'], 'medicine_id'))
            ->get()
            ->keyBy('id');

        $lines = array_map(
            fn (array $item) => $this->toBaseUnits($item, $medicines[(int) $item['medicine_id']]),
            $data['items'],
        );

        // The batches this note tops up, locked before anything is read from them.
        $existing = [];

        foreach ($lines as $index => $line) {
            if ($batch = $this->lots->find($store->id, $line['medicine_id'], $line['batch_number'])) {
                $existing[$index] = $batch->id;
            }
        }

        $locked = $this->stock->lock(array_values($existing));

        $actor = Auth::guard('web')->user();

        $inward = StockInward::create([
            'pharmacy_store_id' => $store->id,
            'location_id' => $store->location_id,
            'supplier_id' => $data['supplier_id'] ?? null,
            'inward_type' => $data['inward_type'],
            'supplier_invoice_no' => $data['supplier_invoice_no'] ?? null,
            'supplier_invoice_date' => $data['supplier_invoice_date'] ?? null,
            'received_date' => $data['received_date'],
            'total_amount' => round(array_sum(array_column($lines, 'line_total')), 2),
            'notes' => $data['notes'] ?? null,
            'idempotency_key' => $key,
            'created_by' => $actor instanceof User ? $actor->id : null,
            'created_by_name' => $actor instanceof User ? $actor->name : null,
        ]);

        // The number is the database's, taken as the row went in.
        $inward->refresh();

        $movementType = StockInward::MOVEMENT_FOR[$data['inward_type']];

        foreach ($lines as $index => $line) {
            $units = $line['quantity'] + $line['free_quantity'];

            if (isset($existing[$index])) {
                $batch = $locked[$existing[$index]];
                $this->lots->assertMatches($batch, $line['expiry_date'], $line['mrp'], $line['selling_price']);

                $batch->quantity_received += $units;
                $batch->save();
                $created = false;
            } else {
                $batch = new MedicineBatch([
                    'pharmacy_store_id' => $store->id,
                    'medicine_id' => $line['medicine_id'],
                    'supplier_id' => $data['supplier_id'] ?? null,
                    'batch_number' => $line['batch_number'],
                    'expiry_date' => $line['expiry_date'],
                    'manufacture_date' => $line['manufacture_date'],
                    'purchase_price' => $line['purchase_price'],
                    'selling_price' => $line['selling_price'],
                    'mrp' => $line['mrp'],
                    'quantity_received' => $units,
                    'received_date' => $data['received_date'],
                ]);

                // Empty until the ledger row below puts the stock in.
                $batch->forceFill(['quantity_available' => 0, 'status' => MedicineBatch::ACTIVE])->save();
                $created = true;
            }

            $batch->setRelation('store', $store);

            $item = StockInwardItem::create([
                'stock_inward_id' => $inward->id,
                'medicine_id' => $line['medicine_id'],
                'medicine_batch_id' => $batch->id,
                'batch_number' => $line['batch_number'],
                'expiry_date' => $line['expiry_date'],
                'manufacture_date' => $line['manufacture_date'],
                'pack_size' => $line['pack_size'],
                'quantity' => $line['quantity'],
                'free_quantity' => $line['free_quantity'],
                'purchase_price' => $line['purchase_price'],
                'selling_price' => $line['selling_price'],
                'mrp' => $line['mrp'],
                'line_total' => $line['line_total'],
            ]);

            if ($created) {
                $batch->forceFill(['stock_inward_item_id' => $item->id])->save();
            }

            $this->stock->record($batch, $movementType, $units, [
                'reference_type' => 'stock_inward_item',
                'reference_id' => $item->id,
                'unit_cost' => $line['purchase_price'],
                'notes' => $inward->inward_number,
            ]);

            $this->lots->ensureStocked($store, $line['medicine_id']);
        }

        return $inward->load(['items.medicine', 'supplier', 'store']);
    }

    /**
     * One line, in base units and per-unit prices.
     *
     * `in_packs` (the default) reads quantities and prices per pack, which is
     * how an invoice is written; off, they are already per base unit — the
     * way loose stock is counted for an opening balance.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function toBaseUnits(array $item, Medicine $medicine): array
    {
        $factor = ($item['in_packs'] ?? true) ? max(1, (int) $medicine->pack_size) : 1;

        $mrp = (float) $item['mrp'];
        $selling = isset($item['selling_price']) ? (float) $item['selling_price'] : $mrp;

        $perUnit = fn (float $price) => round($price / $factor, 2);

        return [
            'medicine_id' => $medicine->id,
            'batch_number' => trim((string) $item['batch_number']),
            'expiry_date' => $item['expiry_date'],
            'manufacture_date' => $item['manufacture_date'] ?? null,
            'pack_size' => max(1, (int) $medicine->pack_size),
            'quantity' => (int) $item['quantity'] * $factor,
            'free_quantity' => (int) ($item['free_quantity'] ?? 0) * $factor,
            'purchase_price' => $perUnit((float) $item['purchase_price']),
            'selling_price' => $perUnit($selling),
            'mrp' => $perUnit($mrp),
            // What the invoice charges: the paid quantity at the price as written.
            'line_total' => round((int) $item['quantity'] * (float) $item['purchase_price'], 2),
        ];
    }

    private function byKey(string $key): ?StockInward
    {
        return StockInward::query()
            ->with(['items.medicine', 'supplier', 'store'])
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
        return (new StockInward)->getConnection()->transaction($callback);
    }
}
