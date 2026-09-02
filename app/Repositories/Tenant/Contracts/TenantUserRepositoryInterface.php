<?php

namespace App\Repositories\Tenant\Contracts;

use App\Models\Tenant\User;
use App\Repositories\Contracts\RepositoryInterface;
use Illuminate\Database\Eloquent\Builder;

interface TenantUserRepositoryInterface extends RepositoryInterface
{
    /**
     * The base query for the list screen, with the optional role and
     * "active only" filters already applied.
     */
    public function listing(?string $role = null, bool $activeOnly = false): Builder;

    /**
     * How many owners the organization still has, not counting one being
     * changed.
     *
     * Asked before demoting, deactivating or removing an owner: an
     * organization with nobody who can manage it is locked out of its own
     * workspace, and no one inside it could undo that.
     */
    public function otherActiveOwnerCount(User $excluding): int;
}
