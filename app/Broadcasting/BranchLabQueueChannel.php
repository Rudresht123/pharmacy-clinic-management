<?php

namespace App\Broadcasting;

use App\Models\Platform\Organization;
use App\Models\Tenant\User;
use App\Services\Permissions\Permission;
use App\Services\Tenancy\TenantBranchAccess;
use Illuminate\Support\Facades\Request;

/**
 * Who may listen to one branch's laboratory queue.
 *
 * `private-org.{organization}.branch.{branch}.lab-queue`. The laboratory
 * television comes in through its display token rather than through here.
 */
class BranchLabQueueChannel
{
    public function __construct(
        private readonly Permission $permission,
        private readonly TenantBranchAccess $branches,
    ) {}

    public static function name(string $organizationUuid, int $locationId): string
    {
        return "org.{$organizationUuid}.branch.{$locationId}.lab-queue";
    }

    public function join(mixed $user, string $organization, string $branch): bool
    {
        if (! $user instanceof User || ! ctype_digit($branch)) {
            return false;
        }

        $current = Request::instance()->attributes->get('tenant.organization');

        if (! $current instanceof Organization || $current->uuid !== $organization) {
            return false;
        }

        $locationId = (int) $branch;

        return $this->branches->canUse($user, $locationId)
            && $this->permission->allows($current, $user, 'laboratory.view', $locationId);
    }
}
