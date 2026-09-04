<?php

namespace App\Repositories\Tenant;

use App\Models\Tenant\User;
use App\Repositories\BaseRepository;
use App\Repositories\Tenant\Contracts\TenantUserRepositoryInterface;
use App\Services\Permissions\StaffScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class TenantUserRepository extends BaseRepository implements TenantUserRepositoryInterface
{
    public function __construct(
        User $model,
        private readonly StaffScope $scope,
    ) {
        parent::__construct($model);
    }

    public function listing(?string $role = null, bool $activeOnly = false): Builder
    {
        /*
         * Scoped here rather than in the controller. `people.view` says
         * somebody may administer staff; it never said WHICH staff, and the
         * answer was every one of them — a branch manager could list, open and
         * edit another branch's people. Applying it at the query means a
         * second screen that lists staff cannot forget to.
         */
        return $this->scope->apply($this->query(), Auth::guard('web')->user())
            // So the list can name the role each person holds rather than
            // saying "Staff" for everybody, which stopped being the answer the
            // moment roles became configurable.
            ->with('permissionRole')
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
