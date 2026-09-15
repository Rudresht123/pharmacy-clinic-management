<?php

namespace App\Policies\Concerns;

use App\Models\Tenant\User;
use App\Services\Permissions\Permission;
use App\Services\Tenancy\TenantBranchAccess;
use Illuminate\Support\Facades\Request;

/**
 * The one question every pharmacy Policy asks: may this person do this, at
 * that branch?
 *
 * Two existing answers, never a new one — the branch has to be one they work
 * at (TenantBranchAccess), and the capability has to hold there, which also
 * means the module is switched on at that branch (Permission::allows).
 */
trait AsksAboutBranch
{
    protected function mayAt(User $user, int $locationId, string $capability): bool
    {
        $organization = Request::instance()->attributes->get('tenant.organization');

        return $organization !== null
            && app(TenantBranchAccess::class)->canUse($user, $locationId)
            && app(Permission::class)->allows($organization, $user, $capability, $locationId);
    }
}
