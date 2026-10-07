<?php

namespace App\Broadcasting;

use App\Models\Platform\Organization;
use App\Models\Tenant\User;
use App\Services\Permissions\Permission;
use App\Services\Tenancy\TenantBranchAccess;
use Illuminate\Support\Facades\Request;

/**
 * Who may listen to one branch's pharmacy counter.
 *
 * `private-org.{organization}.branch.{branch}.pharmacy` — every word of the
 * name is checked, because a channel name is a string the client sends and
 * nothing about it is trusted:
 *
 *   1. ORGANIZATION. The uuid must be the organization this request
 *      authenticated against. A token only exists in its own organization's
 *      database, so a user of Apollo arriving here has been looked up in
 *      Apollo's tables; naming Fortis's uuid fails right here, before any
 *      branch or role is consulted.
 *   2. BRANCH. They must work there — TenantBranchAccess, the same rule every
 *      branch-scoped write uses.
 *   3. FEATURE. The branch must run both the OPD and the pharmacy. A medical
 *      shop with no OPD has no channel to hear on.
 *   4. CAPABILITY. `pharmacy.view` at that branch — whoever may see the
 *      counter's queue over HTTP, and nobody else.
 *
 * Users are numbered per tenant database, so user 7 exists in every
 * organization; that is why the organization is in the channel name at all,
 * and why step 1 compares it rather than trusting it.
 */
class BranchPharmacyChannel
{
    public function __construct(
        private readonly Permission $permission,
        private readonly TenantBranchAccess $branches,
    ) {}

    /** The channel's name, without the `private-` prefix Echo adds. */
    public static function name(string $organizationUuid, int $locationId): string
    {
        return "org.{$organizationUuid}.branch.{$locationId}.pharmacy";
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
            && ($this->permission->featuresAt($current, $locationId)['opd_pharmacy_handoff'] ?? false)
            && $this->permission->allows($current, $user, 'pharmacy.view', $locationId);
    }
}
