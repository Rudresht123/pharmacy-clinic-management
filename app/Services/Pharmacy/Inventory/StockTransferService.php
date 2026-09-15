<?php

namespace App\Services\Pharmacy\Inventory;

use App\Models\Tenant\MedicineBatch;
use App\Models\Tenant\PharmacyStore;
use App\Models\Tenant\StockMovement;
use App\Models\Tenant\StockTransfer;
use App\Models\Tenant\StockTransferItem;
use App\Models\Tenant\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;

/**
 * Stock from one store to another: an out movement and an in movement for
 * every line, in one transaction, so the organization's total never blinks.
 *
 * The same lot keeps its batch number, expiry and prices at the other end;
 * if the destination already holds that batch number, it is topped up, on
 * the same terms as receiving goods (Lots).
 */
class StockTransferService
{
    public function __construct(
        private readonly StockMovementService $stock,
        private readonly Lots $lots,
    ) {}

    /**
     * @param  list<array{batch_id: int, quantity: int}>  $items
     * @return array{0: StockTransfer, 1: bool} the transfer, and whether this call created it
     */
    public function transfer(PharmacyStore $from, PharmacyStore $to, array $items, ?string $notes, string $key): array
    {
        if ($existing = $this->byKey($key)) {
            return [$existing, false];
        }

        try {
            $transfer = (new StockTransfer)->getConnection()->transaction(
                fn () => $this->post($from, $to, $items, $notes, $key)
            );
        } catch (UniqueConstraintViolationException $e) {
            if ($existing = $this->byKey($key)) {
                return [$existing, false];
            }

            throw StockConflict::because('Another movement created one of these batches at the same moment. Check the stock and try again.');
        }

        return [$transfer, true];
    }

    /** @param  list<array{batch_id: int, quantity: int}>  $items */
    private function post(PharmacyStore $from, PharmacyStore $to, array $items, ?string $notes, string $key): StockTransfer
    {
        $this->lots->assertOperational($from);
        $this->lots->assertOperational($to);

        $sourceIds = array_map(fn (array $item) => (int) $item['batch_id'], $items);

        // Numbers and medicines never change, so they can be read before locking.
        $sources = MedicineBatch::query()->whereIn('id', $sourceIds)->get()->keyBy('id');

        $destinationIds = [];

        foreach ($sourceIds as $id) {
            $source = $sources->get($id);

            if (! $source || $source->pharmacy_store_id !== $from->id) {
                throw StockConflict::because("That batch is not in {$from->name}.");
            }

            if ($match = $this->lots->find($to->id, $source->medicine_id, $source->batch_number)) {
                $destinationIds[$id] = $match->id;
            }
        }

        // Sources and existing destinations together, in one id order.
        $locked = $this->stock->lock([...$sourceIds, ...array_values($destinationIds)]);

        $actor = Auth::guard('web')->user();

        $transfer = StockTransfer::create([
            'from_store_id' => $from->id,
            'to_store_id' => $to->id,
            'notes' => $notes,
            'idempotency_key' => $key,
            'created_by' => $actor instanceof User ? $actor->id : null,
            'created_by_name' => $actor instanceof User ? $actor->name : null,
        ]);

        $transfer->refresh();

        foreach ($items as $item) {
            $source = $locked->get((int) $item['batch_id']);
            $quantity = (int) $item['quantity'];

            if (! $source) {
                throw StockConflict::because('One of these batches has been removed.');
            }

            if (! $source->isUsable()) {
                throw StockConflict::unusable($source);
            }

            $source->setRelation('store', $from);

            if (isset($destinationIds[$source->id])) {
                $destination = $locked[$destinationIds[$source->id]];
                $this->lots->assertMatches(
                    $destination,
                    $source->expiry_date->toDateString(),
                    $source->mrp,
                    $source->selling_price,
                );

                $destination->quantity_received += $quantity;
                $destination->save();
            } else {
                $destination = new MedicineBatch([
                    'pharmacy_store_id' => $to->id,
                    'medicine_id' => $source->medicine_id,
                    'supplier_id' => $source->supplier_id,
                    'batch_number' => $source->batch_number,
                    'expiry_date' => $source->expiry_date,
                    'manufacture_date' => $source->manufacture_date,
                    'purchase_price' => $source->purchase_price,
                    'selling_price' => $source->selling_price,
                    'mrp' => $source->mrp,
                    'quantity_received' => $quantity,
                    'received_date' => now()->toDateString(),
                ]);

                $destination->forceFill(['quantity_available' => 0, 'status' => MedicineBatch::ACTIVE])->save();
            }

            $destination->setRelation('store', $to);

            $context = [
                'reference_type' => 'stock_transfer',
                'reference_id' => $transfer->id,
                'unit_cost' => $source->purchase_price,
                'notes' => $transfer->transfer_number,
            ];

            $this->stock->record($source, StockMovement::TRANSFER_OUT, -$quantity, $context);
            $this->stock->record($destination, StockMovement::TRANSFER_IN, $quantity, $context);

            StockTransferItem::create([
                'stock_transfer_id' => $transfer->id,
                'medicine_id' => $source->medicine_id,
                'source_batch_id' => $source->id,
                'destination_batch_id' => $destination->id,
                'quantity' => $quantity,
            ]);

            $this->lots->ensureStocked($to, $source->medicine_id);
        }

        return $transfer->load(['items.medicine', 'items.sourceBatch', 'items.destinationBatch', 'fromStore', 'toStore']);
    }

    private function byKey(string $key): ?StockTransfer
    {
        return StockTransfer::query()
            ->with(['items.medicine', 'items.sourceBatch', 'items.destinationBatch', 'fromStore', 'toStore'])
            ->where('idempotency_key', $key)
            ->first();
    }
}
