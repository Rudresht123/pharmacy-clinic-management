<?php

namespace App\Services\Pharmacy;

use App\Models\Tenant\MedicineBatch;
use App\Models\Tenant\PharmacyStore;
use App\Models\Tenant\StoreMedicine;
use App\Repositories\Tenant\Contracts\PharmacyStoreRepositoryInterface;
use App\Services\Pharmacy\Inventory\StockConflict;
use Illuminate\Support\Arr;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Stores and what they stock — the rules a controller should not have to
 * remember.
 *
 * One default store per branch, always: the first store at a branch becomes
 * its default, making another the default takes it from the old one, and
 * removing the default hands it to the oldest active store left. The
 * database's partial unique index is the backstop; this keeps it from ever
 * being hit.
 *
 * Removing a store is refused while it holds stock.
 */
class PharmacyStores
{
    private const LEVELS = ['reorder_level', 'minimum_stock_level', 'maximum_stock_level', 'preferred_supplier_id', 'is_active'];

    public function __construct(
        private readonly PharmacyStoreRepositoryInterface $stores,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data): PharmacyStore
    {
        return $this->transaction(function () use ($data) {
            $makeDefault = (bool) ($data['is_default'] ?? false)
                || $this->stores->defaultAt((int) $data['location_id']) === null;

            /** @var PharmacyStore $store */
            $store = $this->stores->create([...$data, 'is_default' => false]);

            if ($makeDefault) {
                $this->makeDefault($store);
            }

            return $store->refresh();
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(PharmacyStore $store, array $data): PharmacyStore
    {
        return $this->transaction(function () use ($store, $data) {
            $makeDefault = (bool) ($data['is_default'] ?? false) && ! $store->is_default;

            // The flag is moved by makeDefault(), never written directly.
            $updated = $this->stores->update($store, Arr::except($data, ['is_default', 'location_id']));

            if ($makeDefault) {
                $this->makeDefault($updated);
            }

            return $updated->refresh();
        });
    }

    public function remove(PharmacyStore $store, string $reason): void
    {
        $this->transaction(function () use ($store, $reason) {
            $holdsStock = MedicineBatch::query()
                ->where('pharmacy_store_id', $store->id)
                ->where('quantity_available', '>', 0)
                ->exists();

            if ($holdsStock) {
                throw StockConflict::because(
                    "{$store->name} still holds stock. Transfer or adjust it out before removing the store."
                );
            }

            $wasDefault = $store->is_default;

            if ($wasDefault) {
                // Given up first, so the successor can take it.
                $store->forceFill(['is_default' => false])->saveQuietly();
            }

            $store->deleteWithReason($reason);

            if ($wasDefault && ($successor = $this->successorAt($store->location_id))) {
                $this->makeDefault($successor);
            }
        });
    }

    public function restore(PharmacyStore $store, string $reason): PharmacyStore
    {
        if ($this->stores->findByCode($store->code, $store->id)) {
            throw new ConflictHttpException(
                "Another store now uses the code {$store->code}. Change that store's code before restoring this one."
            );
        }

        return $this->transaction(function () use ($store, $reason) {
            $store->restoreWithReason($reason);

            // Back as the default only if its branch has none.
            if ($this->stores->defaultAt($store->location_id) === null && $store->is_active) {
                $this->makeDefault($store);
            }

            return $store->refresh();
        });
    }

    /**
     * Set the levels for some of this store's medicines.
     *
     * Only the rows sent are touched — a store can stock thousands of
     * medicines and the screen edits a handful. A medicine removed from this
     * store earlier is brought back rather than given a second row, so its
     * history stays one story.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function configure(PharmacyStore $store, array $rows): void
    {
        $this->transaction(function () use ($store, $rows) {
            foreach ($rows as $row) {
                $values = Arr::only($row, self::LEVELS);
                $pair = ['pharmacy_store_id' => $store->id, 'medicine_id' => (int) $row['medicine_id']];

                $live = StoreMedicine::query()->where($pair)->first();

                if ($live) {
                    $live->update($values);

                    continue;
                }

                $removed = StoreMedicine::onlyTrashed()->where($pair)->latest('deleted_at')->first();

                if ($removed) {
                    $removed->fill($values)->restoreWithReason('Added back to this store');

                    continue;
                }

                StoreMedicine::create([...$pair, ...$values]);
            }
        });
    }

    /**
     * The store that becomes a branch's default.
     *
     * The old default gives the flag up before the new one takes it, so the
     * one-default-per-branch index never sees two at once. Saved through the
     * model, so both changes are on the record's history.
     */
    private function makeDefault(PharmacyStore $store): void
    {
        PharmacyStore::query()
            ->where('location_id', $store->location_id)
            ->where('is_default', true)
            ->whereKeyNot($store->id)
            ->get()
            ->each(fn (PharmacyStore $other) => $other->update(['is_default' => false]));

        $store->update(['is_default' => true]);
    }

    /** The oldest active store left at a branch. */
    private function successorAt(int $locationId): ?PharmacyStore
    {
        return PharmacyStore::query()
            ->where('location_id', $locationId)
            ->where('is_active', true)
            ->orderBy('id')
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
        return (new PharmacyStore)->getConnection()->transaction($callback);
    }
}
