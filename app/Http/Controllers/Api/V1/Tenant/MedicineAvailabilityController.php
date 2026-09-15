<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Concerns\AuthorizesTenantUser;
use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Tenant\Medicine;
use App\Models\Tenant\PharmacyStore;
use App\Services\Pharmacy\MedicineAvailability;
use App\Services\Tenancy\TenantBranchAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Is it on the shelf? Behind `medicines.view` — the doctor's question while
 * prescribing — rather than `pharmacy.view`, which opens the stock screens.
 */
class MedicineAvailabilityController extends BaseApiController
{
    use AuthorizesTenantUser;

    public function __construct(
        private readonly MedicineAvailability $availability,
        private readonly TenantBranchAccess $branches,
    ) {}

    /** What one store holds of the given medicines. */
    public function atStore(Request $request, PharmacyStore $store): JsonResponse
    {
        $this->authorizeTenant('checkAvailability', $store);

        $validated = $request->validate([
            'medicine_ids' => ['required', 'array', 'max:100'],
            'medicine_ids.*' => ['integer'],
        ]);

        return $this->ok(array_values($this->availability->atStore($store, $validated['medicine_ids'])));
    }

    /** One medicine, at every operational store the caller works at. */
    public function forMedicine(Medicine $medicine): JsonResponse
    {
        $mine = $this->branches->allowed(Auth::guard('web')->user());

        $stores = PharmacyStore::query()
            ->operational()
            ->with('location')
            ->when($mine !== null, fn (Builder $q) => $q->whereIn('location_id', $mine ?: [0]))
            ->orderBy('location_id')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return $this->ok($stores->map(fn (PharmacyStore $store) => [
            'store_id' => $store->id,
            'store_name' => $store->name,
            'location_id' => $store->location_id,
            'location_name' => $store->location?->name,
            ...$this->availability->atStore($store, [$medicine->id])[$medicine->id],
        ])->values()->all());
    }
}
