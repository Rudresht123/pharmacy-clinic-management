<?php

namespace App\Repositories\Tenant;

use App\Models\Tenant\Location;
use App\Repositories\BaseRepository;
use App\Repositories\Tenant\Contracts\LocationRepositoryInterface;
use App\Services\Tenancy\TenantBranchAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

class LocationRepository extends BaseRepository implements LocationRepositoryInterface
{
    public function __construct(Location $model)
    {
        parent::__construct($model);
    }

    public function listing(?string $type = null, bool $activeOnly = false): Builder
    {
        return $this->query()
            ->when($type, fn (Builder $query) => $query->where('type', $type))
            ->when($activeOnly, fn (Builder $query) => $query->where('is_active', true))
            ->when($this->mine(), fn (Builder $query, array $ids) => $query->whereIn('id', $ids));
    }

    public function activeOrdered(): Collection
    {
        return $this->query()
            ->where('is_active', true)
            ->when($this->mine(), fn (Builder $query, array $ids) => $query->whereIn('id', $ids))
            ->orderBy('name')
            ->get();
    }

    /**
     * The branches the signed-in person may act on, or null for all of them.
     *
     * `branches.view` is a branch-scoped capability — it means "here", not
     * "everywhere" — and this listing was honouring the capability without
     * honouring its scope. A receptionist at Lucknow held it and got Delhi's
     * site list, and every branch picker in the product is populated from
     * this, so they could also aim a request at a branch they do not work at.
     *
     * Null rather than every id, so an owner's query skips the clause.
     *
     * @return list<int>|null
     */
    private function mine(): ?array
    {
        return app(TenantBranchAccess::class)->allowed(Auth::guard('web')->user());
    }
}
