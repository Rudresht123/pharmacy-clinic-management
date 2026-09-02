<?php

namespace App\Repositories\Tenant;

use App\Models\Tenant\Location;
use App\Repositories\BaseRepository;
use App\Repositories\Tenant\Contracts\LocationRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

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
            ->when($activeOnly, fn (Builder $query) => $query->where('is_active', true));
    }

    public function activeOrdered(): Collection
    {
        return $this->query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }
}
