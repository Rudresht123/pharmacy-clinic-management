<?php

namespace App\Repositories\Tenant;

use App\Models\Tenant\PharmacyStore;
use App\Repositories\BaseRepository;
use App\Repositories\Tenant\Contracts\PharmacyStoreRepositoryInterface;
use App\Services\Tenancy\TenantBranchAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class PharmacyStoreRepository extends BaseRepository implements PharmacyStoreRepositoryInterface
{
    public function __construct(PharmacyStore $model)
    {
        parent::__construct($model);
    }

    public function listing(?string $status = null, ?int $locationId = null, ?string $storeType = null): Builder
    {
        return $this->scoped($this->query())
            ->with(['location', 'pharmacist'])
            ->withCount('storeMedicines')
            ->when($status === 'active', fn (Builder $query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn (Builder $query) => $query->where('is_active', false))
            ->when($locationId !== null, fn (Builder $query) => $query->where('location_id', $locationId))
            ->when($storeType !== null, fn (Builder $query) => $query->where('store_type', $storeType));
    }

    public function removed(): Builder
    {
        return $this->scoped($this->query()->onlyTrashed())->with(['location', 'remover']);
    }

    public function findByCode(string $code, ?int $ignoreId = null): ?PharmacyStore
    {
        return $this->query()
            ->whereRaw('lower(code) = ?', [mb_strtolower(trim($code))])
            ->when($ignoreId !== null, fn (Builder $query) => $query->whereKeyNot($ignoreId))
            ->first();
    }

    public function defaultAt(int $locationId): ?PharmacyStore
    {
        return $this->query()
            ->where('location_id', $locationId)
            ->where('is_default', true)
            ->first();
    }

    /**
     * Only the branches this person works at, the same reach the Locations
     * list uses. Null from TenantBranchAccess means the whole network.
     */
    private function scoped(Builder $query): Builder
    {
        $mine = app(TenantBranchAccess::class)->allowed(Auth::guard('web')->user());

        return $mine === null ? $query : $query->whereIn('location_id', $mine ?: [0]);
    }
}
