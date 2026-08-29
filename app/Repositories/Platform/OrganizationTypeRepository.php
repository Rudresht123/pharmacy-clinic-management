<?php

namespace App\Repositories\Platform;

use App\Models\Platform\OrganizationType;
use App\Repositories\BaseRepository;
use App\Repositories\Platform\Contracts\OrganizationTypeRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class OrganizationTypeRepository extends BaseRepository implements OrganizationTypeRepositoryInterface
{
    public function __construct(OrganizationType $model)
    {
        parent::__construct($model);
    }

    public function activeOrdered(): Collection
    {
        return $this->query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    public function listing(bool $activeOnly = false): Builder
    {
        return $this->query()
            ->when($activeOnly, fn (Builder $query) => $query->where('is_active', true));
    }

    public function isInUse(OrganizationType $type): bool
    {
        return $type->organizations()->exists();
    }

    public function toggleStatus(OrganizationType $type): OrganizationType
    {
        $type->update(['is_active' => ! $type->is_active]);

        return $type->refresh();
    }
}
