<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Concerns\AuthorizesTenantUser;
use App\Http\Concerns\HandlesTableQueries;
use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Tenant\SaveStoreMedicinesRequest;
use App\Http\Resources\Tenant\StoreMedicineResource;
use App\Models\Tenant\PharmacyStore;
use App\Models\Tenant\StoreMedicine;
use App\Services\Pharmacy\PharmacyStores;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Which medicines a store stocks, and the levels that make each one low.
 *
 * Reading follows `view` on the store; changing follows `configure`, both
 * through PharmacyStorePolicy.
 */
class StoreMedicineController extends BaseApiController
{
    use AuthorizesTenantUser, HandlesTableQueries;

    public function __construct(
        private readonly PharmacyStores $service,
    ) {}

    public function index(Request $request, PharmacyStore $store): JsonResponse
    {
        $this->authorizeTenant('view', $store);

        $term = trim((string) $request->query('search', ''));

        $query = $store->storeMedicines()
            ->getQuery()
            ->with('medicine')
            // Searched on the medicine, which is what anybody types.
            ->when($term !== '', fn (Builder $query) => $query->whereHas(
                'medicine',
                fn (Builder $medicine) => $medicine->withTrashed()->where(
                    fn (Builder $names) => $names
                        ->where('generic_name', 'ILIKE', "%{$term}%")
                        ->orWhere('brand_name', 'ILIKE', "%{$term}%")
                        ->orWhere('medicine_code', 'ILIKE', "%{$term}%")
                ),
            ))
            ->when($request->filled('status'), fn (Builder $query) => $query->where(
                'is_active',
                $request->string('status')->toString() === 'active',
            ));

        return $this->paginated(
            $this->tableQuery(
                $query,
                $request,
                sortable: ['reorder_level', 'minimum_stock_level', 'is_active', 'created_at'],
                defaultSort: 'created_at',
            ),
            StoreMedicineResource::class,
        );
    }

    /** Set the levels for the medicines sent; others are left alone. */
    public function update(SaveStoreMedicinesRequest $request, PharmacyStore $store): JsonResponse
    {
        $this->authorizeTenant('configure', $store);

        $rows = $request->validated('medicines');

        $this->service->configure($store, $rows);

        $saved = StoreMedicine::query()
            ->with('medicine')
            ->where('pharmacy_store_id', $store->id)
            ->whereIn('medicine_id', array_column($rows, 'medicine_id'))
            ->get();

        return $this->ok(StoreMedicineResource::collection($saved), 'Store levels saved');
    }

    /** The store stops stocking this medicine, with a reason. */
    public function destroy(Request $request, PharmacyStore $store, StoreMedicine $storeMedicine): JsonResponse
    {
        $this->authorizeTenant('configure', $store);

        // Only this store's rows are reachable through this store's URL.
        abort_unless($storeMedicine->pharmacy_store_id === $store->id, 404);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $storeMedicine->deleteWithReason($validated['reason']);

        return $this->ok(null, 'Removed from this store');
    }
}
