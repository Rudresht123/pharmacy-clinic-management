<?php

namespace App\Services\Pharmacy\Inventory;

use App\Models\Tenant\MedicineBatch;
use App\Models\Tenant\StockAdjustment;
use App\Models\Tenant\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;

/**
 * Damage, write-offs and count corrections, each with its reason.
 *
 * An elevated action (`pharmacy.adjust`): it is the one way stock leaves the
 * books without a patient or another store on the other end.
 */
class StockAdjustmentService
{
    public function __construct(
        private readonly StockMovementService $stock,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: StockAdjustment, 1: bool} the adjustment, and whether this call created it
     */
    public function adjust(MedicineBatch $batch, array $data, string $key): array
    {
        if ($existing = $this->byKey($key)) {
            return [$existing, false];
        }

        try {
            $adjustment = (new StockAdjustment)->getConnection()->transaction(
                fn () => $this->post($batch, $data, $key)
            );
        } catch (UniqueConstraintViolationException $e) {
            if ($existing = $this->byKey($key)) {
                return [$existing, false];
            }

            throw $e;
        }

        return [$adjustment, true];
    }

    /** @param  array<string, mixed>  $data */
    private function post(MedicineBatch $batch, array $data, string $key): StockAdjustment
    {
        $locked = $this->stock->lock([$batch->id])->first();

        if (! $locked) {
            throw StockConflict::because("Batch {$batch->batch_number} has been removed.");
        }

        // A write-off for expiry is only true of stock that has expired.
        if ($data['reason_code'] === StockAdjustment::EXPIRY_WRITEOFF
            && ! $locked->isPastExpiry()
            && $locked->status !== MedicineBatch::EXPIRED) {
            throw StockConflict::because("Batch {$locked->batch_number} has not expired. Choose another reason.");
        }

        $actor = Auth::guard('web')->user();

        $adjustment = StockAdjustment::create([
            'pharmacy_store_id' => $locked->pharmacy_store_id,
            'location_id' => $locked->store->location_id,
            'medicine_id' => $locked->medicine_id,
            'medicine_batch_id' => $locked->id,
            'direction' => $data['direction'],
            'quantity' => (int) $data['quantity'],
            'reason_code' => $data['reason_code'],
            'reason' => $data['reason'],
            'idempotency_key' => $key,
            'created_by' => $actor instanceof User ? $actor->id : null,
            'created_by_name' => $actor instanceof User ? $actor->name : null,
        ]);

        $adjustment->refresh();

        $sign = $adjustment->direction === StockAdjustment::INCREASE ? 1 : -1;

        $this->stock->record($locked, $adjustment->movementType(), $sign * $adjustment->quantity, [
            'reference_type' => 'stock_adjustment',
            'reference_id' => $adjustment->id,
            'reason' => $adjustment->reason,
            'notes' => $adjustment->adjustment_number,
        ]);

        return $adjustment->load(['batch', 'medicine']);
    }

    private function byKey(string $key): ?StockAdjustment
    {
        return StockAdjustment::query()->with(['batch', 'medicine'])->where('idempotency_key', $key)->first();
    }
}
