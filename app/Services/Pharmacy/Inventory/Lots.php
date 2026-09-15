<?php

namespace App\Services\Pharmacy\Inventory;

use App\Models\Tenant\MedicineBatch;
use App\Models\Tenant\PharmacyStore;
use App\Models\Tenant\StoreMedicine;
use Illuminate\Support\Carbon;

/**
 * The rules about which lot is which, shared by receiving and transfers.
 *
 * A batch number already in a store is the same lot only if its expiry and
 * prices agree. When they do not, it is refused as a likely typing mistake
 * rather than quietly becoming a second batch with the same number.
 */
class Lots
{
    /** The live batch with this number in this store, ignoring case. */
    public function find(int $storeId, int $medicineId, string $batchNumber): ?MedicineBatch
    {
        return MedicineBatch::query()
            ->where('pharmacy_store_id', $storeId)
            ->where('medicine_id', $medicineId)
            ->whereRaw('lower(batch_number) = ?', [mb_strtolower(trim($batchNumber))])
            ->first();
    }

    /**
     * Stock may be added to this batch as the lot described.
     *
     * @throws StockConflict
     */
    public function assertMatches(MedicineBatch $batch, string $expiry, mixed $mrp, mixed $selling): void
    {
        if (! in_array($batch->status, [MedicineBatch::ACTIVE, MedicineBatch::EXHAUSTED], true) || $batch->isPastExpiry()) {
            $state = $batch->isPastExpiry() ? 'expired' : $batch->status;

            throw StockConflict::because("Batch {$batch->batch_number} is {$state}, so no stock can be added to it.");
        }

        $same = $batch->expiry_date->toDateString() === Carbon::parse($expiry)->toDateString()
            && $this->money($batch->mrp) === $this->money($mrp)
            && $this->money($batch->selling_price) === $this->money($selling);

        if (! $same) {
            throw StockConflict::because(sprintf(
                'Batch %s is already here with expiry %s and MRP ₹%s a unit. A different expiry or price means a different lot, so check the batch number.',
                $batch->batch_number,
                $batch->expiry_date->format('d M Y'),
                $this->money($batch->mrp),
            ));
        }
    }

    /** The store is in use, at a branch that is in use. */
    public function assertOperational(PharmacyStore $store): void
    {
        if (! $store->is_active || ! $store->location?->is_active || $store->trashed()) {
            throw StockConflict::because("{$store->name} is not in use, so stock cannot move in or out of it.");
        }
    }

    /**
     * Receiving a medicine means the store stocks it. A configuration row is
     * added (or the removed one brought back) so it shows on the store's list.
     */
    public function ensureStocked(PharmacyStore $store, int $medicineId): void
    {
        $pair = ['pharmacy_store_id' => $store->id, 'medicine_id' => $medicineId];

        if (StoreMedicine::query()->where($pair)->exists()) {
            return;
        }

        $removed = StoreMedicine::onlyTrashed()->where($pair)->latest('deleted_at')->first();

        if ($removed) {
            $removed->restoreWithReason('Stock received');

            return;
        }

        StoreMedicine::create($pair);
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
