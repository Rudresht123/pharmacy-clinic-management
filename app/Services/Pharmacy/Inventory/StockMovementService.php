<?php

namespace App\Services\Pharmacy\Inventory;

use App\Models\Tenant\MedicineBatch;
use App\Models\Tenant\StockMovement;
use App\Models\Tenant\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use LogicException;

/**
 * The only code that changes a quantity.
 *
 * Every change is a ledger row and a batch update in the same transaction,
 * against a batch row the caller has already locked (lock()). The database
 * backs this up twice — quantity_available cannot go below zero, and a
 * ledger row's before + quantity must equal its after — so a mistake above
 * this class fails loudly instead of corrupting stock.
 */
class StockMovementService
{
    /**
     * Lock batches for a stock change, always in ascending id order.
     *
     * The same order everywhere is what stops two transactions touching the
     * same two batches from each holding one and waiting for the other.
     *
     * @param  list<int>  $ids
     * @return Collection<int, MedicineBatch> keyed by id
     */
    public function lock(array $ids): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return MedicineBatch::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /**
     * Move `$quantity` base units in (+) or out (−) of a locked batch.
     *
     * @param  array{
     *     reference_type?: ?string,
     *     reference_id?: ?int,
     *     reverses_movement_id?: ?int,
     *     reason?: ?string,
     *     notes?: ?string,
     *     unit_cost?: string|float|null,
     * }  $context
     *
     * @throws StockConflict when the batch does not hold enough
     */
    public function record(MedicineBatch $batch, string $type, int $quantity, array $context = []): StockMovement
    {
        if (! in_array($type, StockMovement::TYPES, true)) {
            throw new InvalidArgumentException("Unknown movement type [{$type}].");
        }

        if ($quantity === 0) {
            throw new InvalidArgumentException('A stock movement has to move something.');
        }

        if ($batch->getConnection()->transactionLevel() === 0) {
            throw new LogicException('Stock changes run inside a transaction, against a locked batch.');
        }

        $before = (int) $batch->quantity_available;
        $after = $before + $quantity;

        if ($after < 0) {
            throw StockConflict::insufficient($batch, -$quantity);
        }

        $actor = Auth::guard('web')->user();

        $movement = StockMovement::create([
            'location_id' => $batch->store->location_id,
            'pharmacy_store_id' => $batch->pharmacy_store_id,
            'medicine_id' => $batch->medicine_id,
            'medicine_batch_id' => $batch->id,
            'movement_type' => $type,
            'quantity' => $quantity,
            'quantity_before' => $before,
            'quantity_after' => $after,
            'unit_cost' => $context['unit_cost'] ?? $batch->purchase_price,
            'reference_type' => $context['reference_type'] ?? null,
            'reference_id' => $context['reference_id'] ?? null,
            'reverses_movement_id' => $context['reverses_movement_id'] ?? null,
            'reason' => $context['reason'] ?? null,
            'notes' => $context['notes'] ?? null,
            // Null for a system job; the name survives the person's removal.
            'performed_by' => $actor instanceof User ? $actor->id : null,
            'performed_by_name' => $actor instanceof User ? $actor->name : null,
            'movement_date' => now(),
        ]);

        $batch->quantity_available = $after;

        // Running totals kept in step with the ledger.
        if ($type === StockMovement::DAMAGE) {
            $batch->damaged_quantity += -$quantity;
        }

        if ($type === StockMovement::SUPPLIER_RETURN) {
            $batch->returned_quantity += -$quantity;
        }

        /*
         * Empty and refilled are the only status changes stock makes on its
         * own. Blocked, recalled and expired are decisions (or the calendar),
         * and a quantity never overrides them.
         */
        if ($after === 0 && $batch->status === MedicineBatch::ACTIVE) {
            $batch->status = MedicineBatch::EXHAUSTED;
        } elseif ($after > 0 && $batch->status === MedicineBatch::EXHAUSTED) {
            $batch->status = $batch->isPastExpiry() ? MedicineBatch::EXPIRED : MedicineBatch::ACTIVE;
        }

        $batch->save();

        return $movement;
    }
}
