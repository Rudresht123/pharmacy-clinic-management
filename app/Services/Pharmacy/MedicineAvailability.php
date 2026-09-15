<?php

namespace App\Services\Pharmacy;

use App\Models\Tenant\MedicineBatch;
use App\Models\Tenant\PharmacyStore;
use App\Models\Tenant\StoreMedicine;

/**
 * What a doctor sees beside a medicine: is it on the shelf here?
 *
 * Read from the batches in one grouped query per call — dispensable stock,
 * stock that has expired, the nearest expiry — plus the store's reorder
 * levels. Nothing here blocks prescribing; it is a warning, and the doctor
 * carries on.
 */
class MedicineAvailability
{
    public const AVAILABLE = 'available';

    public const LOW_STOCK = 'low_stock';

    public const OUT_OF_STOCK = 'out_of_stock';

    public const EXPIRED_ONLY = 'expired_only';

    public const NOT_STOCKED = 'not_stocked';

    /**
     * The store a visit at this branch would be dispensed from: its default,
     * or else its oldest operational store.
     */
    public function storeFor(int $locationId): ?PharmacyStore
    {
        return PharmacyStore::query()
            ->operational()
            ->forBranch($locationId)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  list<int>  $medicineIds
     * @return array<int, array{medicine_id: int, status: string, dispensable: int, expired: int, nearest_expiry: ?string, reorder_level: ?int}>
     */
    public function atStore(PharmacyStore $store, array $medicineIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $medicineIds)));

        if ($ids === []) {
            return [];
        }

        $today = now()->toDateString();

        // Dispensable: active and not past expiry on the clinic's calendar.
        $stock = MedicineBatch::query()
            ->where('pharmacy_store_id', $store->id)
            ->whereIn('medicine_id', $ids)
            ->where('quantity_available', '>', 0)
            ->groupBy('medicine_id')
            ->select('medicine_id')
            ->selectRaw("coalesce(sum(quantity_available) filter (where status = 'active' and expiry_date > ?), 0) as dispensable", [$today])
            ->selectRaw("coalesce(sum(quantity_available) filter (where status = 'expired' or expiry_date <= ?), 0) as expired", [$today])
            ->selectRaw("min(expiry_date) filter (where status = 'active' and expiry_date > ?) as nearest_expiry", [$today])
            ->get()
            ->keyBy('medicine_id');

        $configured = StoreMedicine::query()
            ->where('pharmacy_store_id', $store->id)
            ->whereIn('medicine_id', $ids)
            ->where('is_active', true)
            ->get()
            ->keyBy('medicine_id');

        $out = [];

        foreach ($ids as $id) {
            $row = $stock->get($id);
            $config = $configured->get($id);

            $dispensable = (int) ($row?->getAttribute('dispensable') ?? 0);
            $expired = (int) ($row?->getAttribute('expired') ?? 0);
            $nearest = $row?->getAttribute('nearest_expiry');

            $out[$id] = [
                'medicine_id' => $id,
                'status' => $this->status($dispensable, $expired, $config?->reorder_level, $config !== null || $row !== null),
                'dispensable' => $dispensable,
                'expired' => $expired,
                'nearest_expiry' => $nearest ? substr((string) $nearest, 0, 10) : null,
                'reorder_level' => $config?->reorder_level,
            ];
        }

        return $out;
    }

    private function status(int $dispensable, int $expired, ?int $reorderLevel, bool $known): string
    {
        if ($dispensable > 0) {
            return $reorderLevel !== null && $dispensable <= $reorderLevel ? self::LOW_STOCK : self::AVAILABLE;
        }

        if ($expired > 0) {
            return self::EXPIRED_ONLY;
        }

        // Configured here (or holding blocked stock): it belongs here and has run out.
        return $known ? self::OUT_OF_STOCK : self::NOT_STOCKED;
    }
}
