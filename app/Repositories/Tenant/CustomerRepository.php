<?php

namespace App\Repositories\Tenant;

use App\Models\Tenant\Customer;
use App\Models\Tenant\Location;
use App\Repositories\BaseRepository;
use App\Repositories\Tenant\Contracts\CustomerRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;

class CustomerRepository extends BaseRepository implements CustomerRepositoryInterface
{
    public function __construct(Customer $model)
    {
        parent::__construct($model);
    }

    public function listing(
        bool $activeOnly = false,
        ?string $status = null,
        ?int $registeredLocationId = null,
    ): Builder {
        return $this->query()
            ->with('registeredLocation')
            ->when($activeOnly, fn (Builder $query) => $query->where('is_active', true))
            ->when(
                $status === 'active',
                fn (Builder $query) => $query->where('is_active', true)
            )
            ->when(
                $status === 'inactive',
                fn (Builder $query) => $query->where('is_active', false)
            )
            ->when(
                $registeredLocationId !== null,
                fn (Builder $query) => $query->where('registered_location_id', $registeredLocationId)
            );
    }

    public function stats(): array
    {
        $total = $this->query()->count();

        $byLocation = $this->query()
            ->selectRaw('registered_location_id, COUNT(*) AS total')
            ->groupBy('registered_location_id')
            ->pluck('total', 'registered_location_id');

        $names = Location::on('organization')->pluck('name', 'id');

        return [
            'total' => $total,
            'active' => $this->query()->where('is_active', true)->count(),
            'inactive' => $this->query()->where('is_active', false)->count(),

            // Signed up in the last 30 days — "new this month" in practice,
            // without the awkwardness of a partial calendar month.
            'recent' => $this->query()->where('created_at', '>=', now()->subDays(30))->count(),

            'by_location' => $byLocation
                ->map(fn ($count, $locationId) => [
                    'location_id' => $locationId ? (int) $locationId : null,
                    // Customers on file before this was recorded, and walk-ins.
                    'label' => $locationId ? ($names[$locationId] ?? 'Unknown') : 'Not recorded',
                    'total' => (int) $count,
                ])
                ->values()
                ->all(),
        ];
    }
}
