<?php

namespace App\Repositories\Tenant;

use App\Models\Tenant\User;
use App\Repositories\BaseRepository;
use App\Repositories\Tenant\Contracts\TenantUserRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;

class TenantUserRepository extends BaseRepository implements TenantUserRepositoryInterface
{
    public function __construct(User $model)
    {
        parent::__construct($model);
    }

    public function listing(?string $role = null, bool $activeOnly = false): Builder
    {
        return $this->query()
            ->when($role, fn (Builder $query) => $query->where('role', $role))
            ->when($activeOnly, fn (Builder $query) => $query->where('is_active', true));
    }

    public function otherActiveOwnerCount(User $excluding): int
    {
        return $this->query()
            ->where('role', User::OWNER)
            ->where('is_active', true)
            ->whereKeyNot($excluding->getKey())
            ->count();
    }
}
